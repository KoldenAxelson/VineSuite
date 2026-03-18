<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\AdditionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Addition — records when a product (SO2, nutrients, fining agent, etc.) is added to a lot.
 *
 * Each addition is an immutable log entry. Additions are ADDITIVE for offline sync —
 * if two cellar hands both add SO2 offline, both additions apply (no last-write-wins).
 *
 * @property string $id UUID
 * @property string $lot_id FK to lots
 * @property string|null $vessel_id FK to vessels
 * @property string $addition_type Category: sulfite, nutrient, fining, acid, enzyme, tannin, other
 * @property string $product_name Specific product (e.g., "Potassium Metabisulfite", "Go-Ferm Protect")
 * @property string|null $rate Amount per unit volume (e.g., 25 for 25 ppm)
 * @property string|null $rate_unit Unit for rate (ppm, g/L, mg/L, g/hL, lb/1000gal)
 * @property string $total_amount Total quantity added
 * @property string $total_unit Unit for total (g, kg, lb, oz, mL, L)
 * @property string|null $reason Why the addition was made
 * @property string $performed_by FK to users
 * @property Carbon $performed_at When the addition was physically made
 * @property string|null $inventory_item_id Optional link to inventory for auto-deduct
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Addition extends Model
{
    /** @use HasFactory<AdditionFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    /**
     * Common addition type categories.
     */
    public const ADDITION_TYPES = [
        'sulfite',
        'nutrient',
        'fining',
        'acid',
        'enzyme',
        'tannin',
        'other',
    ];

    /**
     * Common rate units used in winemaking.
     */
    public const RATE_UNITS = [
        'ppm',
        'g/L',
        'mg/L',
        'g/hL',
        'lb/1000gal',
        'mL/L',
    ];

    /**
     * Common total amount units.
     */
    public const TOTAL_UNITS = [
        'g',
        'kg',
        'lb',
        'oz',
        'mL',
        'L',
        'gal',
    ];

    protected $fillable = [
        'lot_id',
        'vessel_id',
        'addition_type',
        'product_name',
        'rate',
        'rate_unit',
        'total_amount',
        'total_unit',
        'reason',
        'performed_by',
        'performed_at',
        'inventory_item_id',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'performed_at' => 'datetime',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────

    /**
     * @return BelongsTo<Lot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /**
     * @return BelongsTo<Vessel, $this>
     */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * Filter by addition type (exact match).
     *
     * @param  Builder<Addition>  $query
     * @return Builder<Addition>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('addition_type', $type);
    }

    /**
     * Filter by product name (case-insensitive partial match).
     *
     * @param  Builder<Addition>  $query
     * @return Builder<Addition>
     */
    public function scopeForProduct(Builder $query, string $product): Builder
    {
        return $query->where('product_name', 'ilike', "%{$product}%");
    }

    /**
     * Filter additions for a specific lot.
     *
     * @param  Builder<Addition>  $query
     * @return Builder<Addition>
     */
    public function scopeForLot(Builder $query, string $lotId): Builder
    {
        return $query->where('lot_id', $lotId);
    }

    /**
     * Filter additions performed within a date range.
     *
     * @param  Builder<Addition>  $query
     * @return Builder<Addition>
     */
    public function scopePerformedBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('performed_at', [$from, $to]);
    }

    /**
     * Filter sulfite additions only (for SO2 running total).
     *
     * @param  Builder<Addition>  $query
     * @return Builder<Addition>
     */
    public function scopeSulfiteOnly(Builder $query): Builder
    {
        return $query->where('addition_type', 'sulfite');
    }
}
