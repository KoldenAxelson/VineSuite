<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\CaseGoodsSkuFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

/**
 * Case goods SKU — a bottled wine product available for sale.
 *
 * SKUs represent finished products tracked through inventory. They can be
 * auto-created from bottling runs or manually entered for purchased finished wine.
 * Each SKU will have stock levels per location (Sub-Task 2) and stock movements (Sub-Task 3).
 *
 * @property string $id UUID
 * @property string $wine_name Full product name
 * @property int $vintage Vintage year
 * @property string $varietal Grape variety
 * @property string $format Bottle size (750ml, 375ml, 1.5L, etc.)
 * @property int $case_size Bottles per case (6 or 12)
 * @property string|null $upc_barcode UPC/EAN barcode
 * @property string|null $price Default retail price
 * @property string|null $cost_per_bottle COGS per bottle
 * @property bool $is_active Whether SKU is available for sale
 * @property string|null $image_path Product photo path
 * @property string|null $tasting_notes Tasting notes text
 * @property string|null $tech_sheet_path Tech sheet PDF path
 * @property string|null $lot_id FK to origin lot
 * @property string|null $bottling_run_id FK to bottling run that created this SKU
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Lot|null $lot
 * @property-read BottlingRun|null $bottlingRun
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StockLevel> $stockLevels
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StockMovement> $stockMovements
 */
class CaseGoodsSku extends Model
{
    /** @use HasFactory<CaseGoodsSkuFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;
    use Searchable;

    public const FORMATS = [
        '187ml',
        '375ml',
        '500ml',
        '750ml',
        '1.0L',
        '1.5L',
        '3.0L',
    ];

    public const CASE_SIZES = [6, 12];

    /**
     * Fields to exclude from activity logging.
     *
     * @var array<int, string>
     */
    protected array $activityLogExclude = ['updated_at', 'created_at'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'format' => '750ml',
        'case_size' => 12,
        'is_active' => true,
    ];

    protected $fillable = [
        'wine_name',
        'vintage',
        'varietal',
        'format',
        'case_size',
        'upc_barcode',
        'price',
        'cost_per_bottle',
        'is_active',
        'image_path',
        'tasting_notes',
        'tech_sheet_path',
        'lot_id',
        'bottling_run_id',
    ];

    protected function casts(): array
    {
        return [
            'vintage' => 'integer',
            'case_size' => 'integer',
            'price' => 'decimal:2',
            'cost_per_bottle' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    // ─── Scout / Meilisearch ────────────────────────────────────────

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'wine_name' => $this->wine_name,
            'vintage' => $this->vintage,
            'varietal' => $this->varietal,
            'format' => $this->format,
            'upc_barcode' => $this->upc_barcode,
            'is_active' => $this->is_active,
        ];
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return true;
    }

    // ─── Relationships ──────────────────────────────────────────────

    /**
     * Origin lot for traceability.
     *
     * @return BelongsTo<Lot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /**
     * Bottling run that created this SKU.
     *
     * @return BelongsTo<BottlingRun, $this>
     */
    public function bottlingRun(): BelongsTo
    {
        return $this->belongsTo(BottlingRun::class);
    }

    /**
     * Stock levels across all locations.
     *
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'sku_id');
    }

    /**
     * Stock movements (ledger entries) across all locations.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'sku_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * Filter to active SKUs only.
     *
     * @param  Builder<CaseGoodsSku>  $query
     * @return Builder<CaseGoodsSku>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Filter by vintage year.
     *
     * @param  Builder<CaseGoodsSku>  $query
     * @return Builder<CaseGoodsSku>
     */
    public function scopeOfVintage(Builder $query, int $vintage): Builder
    {
        return $query->where('vintage', $vintage);
    }

    /**
     * Filter by varietal.
     *
     * @param  Builder<CaseGoodsSku>  $query
     * @return Builder<CaseGoodsSku>
     */
    public function scopeOfVarietal(Builder $query, string $varietal): Builder
    {
        return $query->where('varietal', $varietal);
    }

    /**
     * Filter by bottle format.
     *
     * @param  Builder<CaseGoodsSku>  $query
     * @return Builder<CaseGoodsSku>
     */
    public function scopeOfFormat(Builder $query, string $format): Builder
    {
        return $query->where('format', $format);
    }

    /**
     * Search by wine name, varietal, or barcode (database fallback).
     *
     * @param  Builder<CaseGoodsSku>  $query
     * @return Builder<CaseGoodsSku>
     */
    public function scopeSearchDb(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('wine_name', 'ilike', "%{$term}%")
                ->orWhere('varietal', 'ilike', "%{$term}%")
                ->orWhere('upc_barcode', 'ilike', "%{$term}%");
        });
    }
}
