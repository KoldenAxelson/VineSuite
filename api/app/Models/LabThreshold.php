<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\LabThresholdFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Lab threshold — configurable alert boundaries for lab analysis values.
 *
 * Thresholds can be global (variety=null → applies to all varieties) or
 * variety-specific. When checking a lab value, variety-specific thresholds
 * take precedence over global ones.
 *
 * @property int $id
 * @property string $test_type The lab test type (pH, TA, VA, etc.)
 * @property string|null $variety Grape variety this threshold applies to (null = all)
 * @property string|null $min_value Minimum acceptable value (null = no lower bound)
 * @property string|null $max_value Maximum acceptable value (null = no upper bound)
 * @property string $alert_level 'warning' or 'critical'
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class LabThreshold extends Model
{
    /** @use HasFactory<LabThresholdFactory> */
    use HasFactory;

    use LogsActivity;

    public const ALERT_LEVELS = [
        'warning',
        'critical',
    ];

    /**
     * Fields to exclude from activity logging.
     *
     * @var array<int, string>
     */
    protected array $activityLogExclude = ['updated_at', 'created_at'];

    protected $fillable = [
        'test_type',
        'variety',
        'min_value',
        'max_value',
        'alert_level',
    ];

    protected function casts(): array
    {
        return [
            'min_value' => 'decimal:6',
            'max_value' => 'decimal:6',
        ];
    }

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * Filter by test type.
     *
     * @param  Builder<LabThreshold>  $query
     * @return Builder<LabThreshold>
     */
    public function scopeForTestType(Builder $query, string $testType): Builder
    {
        return $query->where('test_type', $testType);
    }

    /**
     * Filter by alert level.
     *
     * @param  Builder<LabThreshold>  $query
     * @return Builder<LabThreshold>
     */
    public function scopeOfLevel(Builder $query, string $level): Builder
    {
        return $query->where('alert_level', $level);
    }

    /**
     * Get thresholds applicable to a given variety (variety-specific + global).
     *
     * Variety-specific thresholds are returned first so callers can prioritize them.
     *
     * @param  Builder<LabThreshold>  $query
     * @return Builder<LabThreshold>
     */
    public function scopeApplicableTo(Builder $query, string $testType, ?string $variety = null): Builder
    {
        return $query->where('test_type', $testType)
            ->where(function (Builder $q) use ($variety) {
                $q->whereNull('variety');
                if ($variety !== null) {
                    $q->orWhere('variety', $variety);
                }
            })
            ->orderByRaw('variety IS NULL ASC');
    }
}
