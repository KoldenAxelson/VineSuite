<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LotCogsSummary — immutable COGS snapshot calculated at bottling completion.
 *
 * Captures the full cost picture at the moment of bottling:
 * bulk wine cost (accumulated from fruit + materials + labor + overhead + blends)
 * plus packaging materials plus bottling labor = total COGS.
 *
 * @property string $id UUID
 * @property string $lot_id FK to lots
 * @property string $total_fruit_cost
 * @property string $total_material_cost
 * @property string $total_labor_cost
 * @property string $total_overhead_cost
 * @property string $total_transfer_in_cost
 * @property string $total_cost
 * @property string $volume_gallons_at_calc Volume at time of COGS calculation
 * @property string|null $cost_per_gallon
 * @property int|null $bottles_produced
 * @property string|null $cost_per_bottle
 * @property string|null $cost_per_case
 * @property string|null $packaging_cost_per_bottle
 * @property string|null $bottling_labor_cost
 * @property \Illuminate\Support\Carbon $calculated_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class LotCogsSummary extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'lot_id',
        'total_fruit_cost',
        'total_material_cost',
        'total_labor_cost',
        'total_overhead_cost',
        'total_transfer_in_cost',
        'total_cost',
        'volume_gallons_at_calc',
        'cost_per_gallon',
        'bottles_produced',
        'cost_per_bottle',
        'cost_per_case',
        'packaging_cost_per_bottle',
        'bottling_labor_cost',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'total_fruit_cost' => 'decimal:4',
            'total_material_cost' => 'decimal:4',
            'total_labor_cost' => 'decimal:4',
            'total_overhead_cost' => 'decimal:4',
            'total_transfer_in_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'volume_gallons_at_calc' => 'decimal:4',
            'cost_per_gallon' => 'decimal:4',
            'bottles_produced' => 'integer',
            'cost_per_bottle' => 'decimal:4',
            'cost_per_case' => 'decimal:4',
            'packaging_cost_per_bottle' => 'decimal:4',
            'bottling_labor_cost' => 'decimal:4',
            'calculated_at' => 'datetime',
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
}
