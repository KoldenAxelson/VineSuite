<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FilterLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Filter log — records a filtering or fining operation on a lot.
 *
 * Simple log entry per the spec: "Keep it simple — this is a log entry,
 * not a complex workflow." Pre/post analysis comparison references lab
 * analysis entries (from 03-lab-fermentation.md) via nullable UUIDs.
 */
class FilterLog extends Model
{
    /** @use HasFactory<FilterLogFactory> */
    use HasFactory;

    use HasUuids;

    public const FILTER_TYPES = [
        'pad',
        'crossflow',
        'cartridge',
        'plate_and_frame',
        'de',
        'lenticular',
    ];

    public const FINING_RATE_UNITS = [
        'g/L',
        'g/hL',
        'mL/L',
        'lb/1000gal',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'lot_id',
        'vessel_id',
        'filter_type',
        'filter_media',
        'flow_rate_lph',
        'volume_processed_gallons',
        'fining_agent',
        'fining_rate',
        'fining_rate_unit',
        'bench_trial_notes',
        'treatment_notes',
        'pre_analysis_id',
        'post_analysis_id',
        'performed_by',
        'performed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'flow_rate_lph' => 'decimal:2',
            'volume_processed_gallons' => 'decimal:4',
            'fining_rate' => 'decimal:4',
            'performed_at' => 'datetime',
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

    // ─── Scopes ──────────────────────────────────────────────────

    /**
     * @param  Builder<FilterLog>  $query
     * @return Builder<FilterLog>
     */
    public function scopeOfType(Builder $query, string $filterType): Builder
    {
        return $query->where('filter_type', $filterType);
    }

    /**
     * @param  Builder<FilterLog>  $query
     * @return Builder<FilterLog>
     */
    public function scopeForLot(Builder $query, string $lotId): Builder
    {
        return $query->where('lot_id', $lotId);
    }

    /**
     * @param  Builder<FilterLog>  $query
     * @return Builder<FilterLog>
     */
    public function scopePerformedBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('performed_at', [$from, $to]);
    }

    /**
     * Filter logs that include fining operations.
     *
     * @param  Builder<FilterLog>  $query
     * @return Builder<FilterLog>
     */
    public function scopeWithFining(Builder $query): Builder
    {
        return $query->whereNotNull('fining_agent');
    }
}
