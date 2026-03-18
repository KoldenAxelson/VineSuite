<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\RawMaterialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Raw material / cellar supply inventory item.
 *
 * Tracks consumable winemaking inputs (additives, yeast, nutrients, fining agents,
 * acids, enzymes, oak alternatives) with stock levels, cost, and expiration tracking.
 *
 * @property string $id UUID
 * @property string $name
 * @property string $category additive, yeast, nutrient, fining_agent, acid, enzyme, oak_alternative
 * @property string $unit_of_measure g, kg, L, each
 * @property float $on_hand Current stock in native units
 * @property float|null $reorder_point Alert threshold
 * @property float|null $cost_per_unit Cost per native unit (feeds COGS)
 * @property Carbon|null $expiration_date
 * @property string|null $vendor_name Human-readable vendor name
 * @property string|null $vendor_id FK to vendors (when built)
 * @property bool $is_active
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, PurchaseOrderLine> $purchaseOrderLines
 */
class RawMaterial extends Model
{
    /** @use HasFactory<RawMaterialFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    protected $table = 'raw_materials';

    public const CATEGORIES = [
        'additive',
        'yeast',
        'nutrient',
        'fining_agent',
        'acid',
        'enzyme',
        'oak_alternative',
    ];

    public const UNITS_OF_MEASURE = [
        'g',
        'kg',
        'L',
        'each',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'on_hand' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'category',
        'unit_of_measure',
        'on_hand',
        'reorder_point',
        'cost_per_unit',
        'expiration_date',
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
            'expiration_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    /**
     * Purchase order lines referencing this raw material.
     *
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'item_id')
            ->where('item_type', 'raw_material');
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * @param  Builder<RawMaterial>  $query
     * @return Builder<RawMaterial>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<RawMaterial>  $query
     * @return Builder<RawMaterial>
     */
    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Items at or below their reorder point.
     *
     * @param  Builder<RawMaterial>  $query
     * @return Builder<RawMaterial>
     */
    public function scopeBelowReorderPoint(Builder $query): Builder
    {
        return $query->whereNotNull('reorder_point')
            ->whereColumn('on_hand', '<=', 'reorder_point');
    }

    /**
     * Items past their expiration date.
     *
     * @param  Builder<RawMaterial>  $query
     * @return Builder<RawMaterial>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expiration_date')
            ->where('expiration_date', '<', now()->toDateString());
    }

    /**
     * Items expiring within the given number of days.
     *
     * @param  Builder<RawMaterial>  $query
     * @return Builder<RawMaterial>
     */
    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->whereNotNull('expiration_date')
            ->where('expiration_date', '>=', now()->toDateString())
            ->where('expiration_date', '<=', now()->addDays($days)->toDateString());
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

    /**
     * Whether this item is past its expiration date.
     */
    public function isExpired(): bool
    {
        if ($this->expiration_date === null) {
            return false;
        }

        return $this->expiration_date->isPast();
    }
}
