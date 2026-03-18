<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\DryGoodsItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Dry goods / packaging material inventory item.
 *
 * Tracks packaging materials (bottles, corks, capsules, labels, cartons, etc.)
 * with stock levels in the item's native unit of measure.
 *
 * @property string $id UUID
 * @property string $name
 * @property string $item_type bottle, cork, screw_cap, capsule, label_front, label_back, label_neck, carton, divider, tissue
 * @property string $unit_of_measure each, sleeve, pallet
 * @property float $on_hand Current stock in native units
 * @property float|null $reorder_point Alert threshold
 * @property float|null $cost_per_unit Cost per native unit (feeds COGS)
 * @property string|null $vendor_name Human-readable vendor name
 * @property string|null $vendor_id FK to vendors (when built)
 * @property bool $is_active
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, PurchaseOrderLine> $purchaseOrderLines
 */
class DryGoodsItem extends Model
{
    /** @use HasFactory<DryGoodsItemFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    public const ITEM_TYPES = [
        'bottle',
        'cork',
        'screw_cap',
        'capsule',
        'label_front',
        'label_back',
        'label_neck',
        'carton',
        'divider',
        'tissue',
    ];

    public const UNITS_OF_MEASURE = [
        'each',
        'sleeve',
        'pallet',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'on_hand' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'item_type',
        'unit_of_measure',
        'on_hand',
        'reorder_point',
        'cost_per_unit',
        'vendor_name',
        'vendor_id',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:2',
            'reorder_point' => 'decimal:2',
            'cost_per_unit' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    /**
     * Purchase order lines referencing this dry goods item.
     *
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'item_id')
            ->where('item_type', 'dry_goods');
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * @param  Builder<DryGoodsItem>  $query
     * @return Builder<DryGoodsItem>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<DryGoodsItem>  $query
     * @return Builder<DryGoodsItem>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('item_type', $type);
    }

    /**
     * Items at or below their reorder point.
     *
     * @param  Builder<DryGoodsItem>  $query
     * @return Builder<DryGoodsItem>
     */
    public function scopeBelowReorderPoint(Builder $query): Builder
    {
        return $query->whereNotNull('reorder_point')
            ->whereColumn('on_hand', '<=', 'reorder_point');
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Whether this item's stock is at or below the reorder point.
     */
    public function needsReorder(): bool
    {
        if ($this->reorder_point === null) {
            return false;
        }

        return (float) $this->on_hand <= (float) $this->reorder_point;
    }
}
