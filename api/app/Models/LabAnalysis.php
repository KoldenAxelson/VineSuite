<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\LabAnalysisFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lab analysis — a single analytical measurement for a lot.
 *
 * Each record captures one test type (pH, TA, VA, etc.) for one lot on one date.
 * Multiple test types can be recorded for the same lot on the same date as separate entries.
 *
 * @property string $id UUID
 * @property string $lot_id FK to lots
 * @property \Illuminate\Support\Carbon $test_date Date the analysis was performed
 * @property string $test_type Type of analysis (pH, TA, VA, free_SO2, etc.)
 * @property string $value Measured value
 * @property string $unit Unit of measurement
 * @property string|null $method Analytical method used
 * @property string|null $analyst Person or lab that performed the analysis
 * @property string|null $notes Free-text notes
 * @property string $source How the data was entered (manual, ets_labs, oenofoss, wine_scan, csv_import)
 * @property string|null $performed_by FK to users (who entered the record)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class LabAnalysis extends Model
{
    /** @use HasFactory<LabAnalysisFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    /**
     * Standard wine lab test types.
     */
    public const TEST_TYPES = [
        'pH',
        'TA',
        'VA',
        'free_SO2',
        'total_SO2',
        'residual_sugar',
        'alcohol',
        'malic_acid',
        'glucose_fructose',
        'turbidity',
        'color',
    ];

    /**
     * Common units per test type.
     *
     * @var array<string, string>
     */
    public const DEFAULT_UNITS = [
        'pH' => 'pH',
        'TA' => 'g/L',
        'VA' => 'g/100mL',
        'free_SO2' => 'mg/L',
        'total_SO2' => 'mg/L',
        'residual_sugar' => 'g/L',
        'alcohol' => '%v/v',
        'malic_acid' => 'g/L',
        'glucose_fructose' => 'g/L',
        'turbidity' => 'NTU',
        'color' => 'AU',
    ];

    /**
     * Data sources for lab analyses.
     */
    public const SOURCES = [
        'manual',
        'ets_labs',
        'oenofoss',
        'wine_scan',
        'csv_import',
    ];

    /**
     * Fields to exclude from activity logging.
     *
     * @var array<int, string>
     */
    protected array $activityLogExclude = ['updated_at', 'created_at'];

    protected $fillable = [
        'lot_id',
        'test_date',
        'test_type',
        'value',
        'unit',
        'method',
        'analyst',
        'notes',
        'source',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'test_date' => 'date',
            'value' => 'decimal:6',
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
     * @return BelongsTo<User, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * Filter by test type.
     *
     * @param  Builder<LabAnalysis>  $query
     * @return Builder<LabAnalysis>
     */
    public function scopeOfType(Builder $query, string $testType): Builder
    {
        return $query->where('test_type', $testType);
    }

    /**
     * Filter analyses for a specific lot.
     *
     * @param  Builder<LabAnalysis>  $query
     * @return Builder<LabAnalysis>
     */
    public function scopeForLot(Builder $query, string $lotId): Builder
    {
        return $query->where('lot_id', $lotId);
    }

    /**
     * Filter analyses within a date range.
     *
     * @param  Builder<LabAnalysis>  $query
     * @return Builder<LabAnalysis>
     */
    public function scopeTestedBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('test_date', [$from, $to]);
    }

    /**
     * Filter by data source.
     *
     * @param  Builder<LabAnalysis>  $query
     * @return Builder<LabAnalysis>
     */
    public function scopeFromSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }
}
