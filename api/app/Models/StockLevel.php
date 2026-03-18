<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StockLevelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stock level for a SKU at a specific location.
 *
 * Tracks on_hand and committed quantities. Available is computed as
 * on_hand - committed. Stock levels update atomically via the
 * InventoryService (Sub-Task 3) — never update directly.
 *
 * @property string $id UUID
 * @property string $sku_id FK to case_goods_skus
 * @property string $location_id FK to locations
 * @property int $on_hand Physical quantity on hand
 * @property int $committed Quantity allocated to unfulfilled orders
 * @property-read int $available Computed: on_hand - committed
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read CaseGoodsSku $sku
 * @property-read Location $location
 */
class StockLevel extends Model
{
    /** @use HasFactory<StockLevelFactory> */
    use HasFactory;

    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'on_hand' => 0,
        'committed' => 0,
    ];

    protected $fillable = [
        'sku_id',
        'location_id',
        'on_hand',
        'committed',
    ];

    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'committed' => 'integer',
        ];
    }

    // ─── Computed Attributes ────────────────────────────────────────

    /**
     * Available = on_hand - committed.
     *
     * Can go negative (overselling happens in tasting rooms).
     * UI should warn but not hard-block.
     */
    public function getAvailableAttribute(): int
    {
        return $this->on_hand - $this->committed;
    }

    // ─── Relationships ──────────────────────────────────────────────

    /**
     * The SKU this stock level belongs to.
     *
     * @return BelongsTo<CaseGoodsSku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(CaseGoodsSku::class, 'sku_id');
    }

    /**
     * The location this stock level belongs to.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * Filter to stock levels with positive on-hand.
     *
     * @param  Builder<StockLevel>  $query
     * @return Builder<StockLevel>
     */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('on_hand', '>', 0);
    }

    /**
     * Filter by SKU.
     *
     * @param  Builder<StockLevel>  $query
     * @return Builder<StockLevel>
     */
    public function scopeForSku(Builder $query, string $skuId): Builder
    {
        return $query->where('sku_id', $skuId);
    }

    /**
     * Filter by location.
     *
     * @param  Builder<StockLevel>  $query
     * @return Builder<StockLevel>
     */
    public function scopeAtLocation(Builder $query, string $locationId): Builder
    {
        return $query->where('location_id', $locationId);
    }
}
