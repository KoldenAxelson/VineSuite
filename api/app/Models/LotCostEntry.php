<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LotCostEntry — immutable cost ledger entry for a production lot.
 *
 * Cost entries are append-only (like the event log). Corrections are
 * negative adjustment entries — never edits to historical records.
 * All money values use decimal columns + bcmath for precision.
 *
 * @property string $id UUID
 * @property string $lot_id FK to lots
 * @property string $cost_type fruit|material|labor|overhead|transfer_in
 * @property string $description Human-readable description
 * @property string $amount Signed decimal — negative for adjustments
 * @property string|null $quantity Units consumed (nullable)
 * @property string|null $unit_cost Cost per unit (nullable)
 * @property string|null $reference_type Source record type: addition|work_order|purchase|manual|blend_allocation|split_allocation|bottling
 * @property string|null $reference_id UUID of the source record
 * @property \Illuminate\Support\Carbon $performed_at When the cost was incurred
 * @property \Illuminate\Support\Carbon $created_at
 */
class LotCostEntry extends Model
{
    use HasUuids;

    /**
     * Immutable — no updated_at column.
     */
    public const UPDATED_AT = null;

    public const COST_TYPES = [
        'fruit',
        'material',
        'labor',
        'overhead',
        'transfer_in',
    ];

    public const REFERENCE_TYPES = [
        'addition',
        'work_order',
        'purchase',
        'manual',
        'blend_allocation',
        'split_allocation',
        'bottling',
    ];

    protected $fillable = [
        'lot_id',
        'cost_type',
        'description',
        'amount',
        'quantity',
        'unit_cost',
        'reference_type',
        'reference_id',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
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

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * Filter by cost type.
     *
     * @param  Builder<LotCostEntry>  $query
     * @return Builder<LotCostEntry>
     */
    public function scopeOfCostType(Builder $query, string $costType): Builder
    {
        return $query->where('cost_type', $costType);
    }

    /**
     * Filter entries for a specific lot.
     *
     * @param  Builder<LotCostEntry>  $query
     * @return Builder<LotCostEntry>
     */
    public function scopeForLot(Builder $query, string $lotId): Builder
    {
        return $query->where('lot_id', $lotId);
    }

    /**
     * Filter by reference type.
     *
     * @param  Builder<LotCostEntry>  $query
     * @return Builder<LotCostEntry>
     */
    public function scopeOfReferenceType(Builder $query, string $referenceType): Builder
    {
        return $query->where('reference_type', $referenceType);
    }

    /**
     * Filter entries performed within a date range.
     *
     * @param  Builder<LotCostEntry>  $query
     * @return Builder<LotCostEntry>
     */
    public function scopePerformedBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('performed_at', [$from, $to]);
    }
}
