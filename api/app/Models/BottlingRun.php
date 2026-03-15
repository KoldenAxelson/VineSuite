<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BottlingRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bottling run — converts bulk wine (gallons) into case goods (bottles/cases).
 *
 * Bottling is a critical junction bridging the production module and
 * the inventory/sales modules. Completing a run deducts lot volume,
 * creates case goods inventory, and optionally archives the lot.
 */
class BottlingRun extends Model
{
    /** @use HasFactory<BottlingRunFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUSES = [
        'planned',
        'in_progress',
        'completed',
    ];

    public const BOTTLE_FORMATS = [
        '187ml',
        '375ml',
        '500ml',
        '750ml',
        '1.0L',
        '1.5L',
        '3.0L',
    ];

    /** Standard bottles per gallon by format (approximate). */
    public const BOTTLES_PER_GALLON = [
        '187ml' => 20.26,
        '375ml' => 10.13,
        '500ml' => 7.57,
        '750ml' => 5.05,
        '1.0L' => 3.79,
        '1.5L' => 2.52,
        '3.0L' => 1.26,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'lot_id',
        'bottle_format',
        'bottles_filled',
        'bottles_breakage',
        'waste_percent',
        'volume_bottled_gallons',
        'status',
        'sku',
        'cases_produced',
        'bottles_per_case',
        'performed_by',
        'bottled_at',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'bottles_filled' => 'integer',
            'bottles_breakage' => 'integer',
            'waste_percent' => 'decimal:2',
            'volume_bottled_gallons' => 'decimal:4',
            'cases_produced' => 'integer',
            'bottles_per_case' => 'integer',
            'bottled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * @return BelongsTo<Lot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * @return HasMany<BottlingComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(BottlingComponent::class);
    }

    // ─── Scopes ──────────────────────────────────────────────────

    /**
     * @param  Builder<BottlingRun>  $query
     * @return Builder<BottlingRun>
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<BottlingRun>  $query
     * @return Builder<BottlingRun>
     */
    public function scopeForLot(Builder $query, string $lotId): Builder
    {
        return $query->where('lot_id', $lotId);
    }

    /**
     * @param  Builder<BottlingRun>  $query
     * @return Builder<BottlingRun>
     */
    public function scopeBottledBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('bottled_at', [$from, $to]);
    }
}
