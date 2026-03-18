<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stock movement — an immutable ledger entry recording a change to stock levels.
 *
 * Every change to a StockLevel goes through the InventoryService, which creates
 * a StockMovement and atomically updates the corresponding StockLevel row using
 * SELECT FOR UPDATE to prevent race conditions.
 *
 * @property string $id UUID
 * @property string $sku_id FK to case_goods_skus
 * @property string $location_id FK to locations
 * @property string $movement_type Movement type (received, sold, transferred, adjusted, returned, bottled)
 * @property int $quantity Positive = stock in, negative = stock out
 * @property string|null $reference_type Source type (order, bottling_run, transfer, adjustment)
 * @property string|null $reference_id Source UUID
 * @property string|null $performed_by FK to users
 * @property Carbon $performed_at When the movement occurred
 * @property string|null $notes Free-text notes
 * @property Carbon|null $created_at
 * @property-read CaseGoodsSku $sku
 * @property-read Location $location
 * @property-read User|null $performer
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    use HasUuids;

    public const MOVEMENT_TYPES = [
        'received',
        'sold',
        'transferred',
        'adjusted',
        'returned',
        'bottled',
    ];

    public const REFERENCE_TYPES = [
        'order',
        'bottling_run',
        'transfer',
        'adjustment',
        'physical_count',
    ];

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'sku_id',
        'location_id',
        'movement_type',
        'quantity',
        'reference_type',
        'reference_id',
        'performed_by',
        'performed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'performed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    /**
     * @return BelongsTo<CaseGoodsSku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(CaseGoodsSku::class, 'sku_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * @param  Builder<StockMovement>  $query
     * @return Builder<StockMovement>
     */
    public function scopeForSku(Builder $query, string $skuId): Builder
    {
        return $query->where('sku_id', $skuId);
    }

    /**
     * @param  Builder<StockMovement>  $query
     * @return Builder<StockMovement>
     */
    public function scopeAtLocation(Builder $query, string $locationId): Builder
    {
        return $query->where('location_id', $locationId);
    }

    /**
     * @param  Builder<StockMovement>  $query
     * @return Builder<StockMovement>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('movement_type', $type);
    }

    /**
     * @param  Builder<StockMovement>  $query
     * @return Builder<StockMovement>
     */
    public function scopePerformedBetween(Builder $query, \DateTimeInterface|string $from, \DateTimeInterface|string $to): Builder
    {
        return $query->whereBetween('performed_at', [$from, $to]);
    }
}
