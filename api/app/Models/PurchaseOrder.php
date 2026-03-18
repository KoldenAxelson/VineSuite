<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Purchase order for dry goods and raw materials.
 *
 * @property string $id UUID
 * @property string $vendor_name
 * @property string|null $vendor_id
 * @property Carbon $order_date
 * @property Carbon|null $expected_date
 * @property string $status ordered, partial, received, cancelled
 * @property float $total_cost
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Collection<int, PurchaseOrderLine> $lines
 */
class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    public const STATUSES = [
        'ordered',
        'partial',
        'received',
        'cancelled',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'status' => 'ordered',
        'total_cost' => 0,
    ];

    protected $fillable = [
        'vendor_name',
        'vendor_id',
        'order_date',
        'expected_date',
        'status',
        'total_cost',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'total_cost' => 'decimal:2',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function scopeOfStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * POs that are still open (not received or cancelled).
     *
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['ordered', 'partial']);
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function scopeForVendor(Builder $query, string $vendorName): Builder
    {
        return $query->where('vendor_name', 'ilike', '%'.$vendorName.'%');
    }

    /**
     * POs expected on or before the given date that are still open.
     *
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull('expected_date')
            ->where('expected_date', '<', now()->toDateString());
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Whether all lines are fully received.
     */
    public function isFullyReceived(): bool
    {
        if (! $this->relationLoaded('lines')) {
            $this->load('lines');
        }

        if ($this->lines->isEmpty()) {
            return false;
        }

        return $this->lines->every(
            fn (PurchaseOrderLine $line) => (float) $line->quantity_received >= (float) $line->quantity_ordered
        );
    }

    /**
     * Whether any line has been partially received.
     */
    public function isPartiallyReceived(): bool
    {
        if (! $this->relationLoaded('lines')) {
            $this->load('lines');
        }

        return $this->lines->contains(
            fn (PurchaseOrderLine $line) => (float) $line->quantity_received > 0 && (float) $line->quantity_received < (float) $line->quantity_ordered
        );
    }

    /**
     * Recalculate total_cost from line items.
     */
    public function recalculateTotalCost(): void
    {
        if (! $this->relationLoaded('lines')) {
            $this->load('lines');
        }

        $total = $this->lines->sum(function (PurchaseOrderLine $line) {
            return (float) $line->quantity_ordered * (float) ($line->cost_per_unit ?? 0);
        });

        $this->update(['total_cost' => round($total, 2)]);
    }

    /**
     * Filament badge color for the current status.
     * Single source of truth — used by PO lines relation managers.
     */
    public function statusColor(): string
    {
        return match ($this->status) {
            'draft' => 'gray',
            'submitted' => 'info',
            'partial' => 'warning',
            'received' => 'success',
            'cancelled' => 'danger',
            default => 'secondary',
        };
    }
}
