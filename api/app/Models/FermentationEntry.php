<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\FermentationEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fermentation entry — a single daily measurement during a fermentation round.
 *
 * Records temperature, Brix/density, optional SO2, and notes for a specific date.
 * Multiple entries per day are allowed (e.g., AM and PM readings).
 *
 * @property string $id UUID
 * @property string $fermentation_round_id FK to fermentation_rounds
 * @property \Illuminate\Support\Carbon $entry_date Date of the reading
 * @property string|null $temperature Temperature reading (°F)
 * @property string|null $brix_or_density Brix or specific gravity value
 * @property string|null $measurement_type 'brix' or 'specific_gravity'
 * @property string|null $free_so2 Free SO2 reading (mg/L)
 * @property string|null $notes Free-text notes
 * @property string|null $performed_by UUID of the user who recorded the entry
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class FermentationEntry extends Model
{
    /** @use HasFactory<FermentationEntryFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    public const MEASUREMENT_TYPES = [
        'brix',
        'specific_gravity',
    ];

    /**
     * @var array<int, string>
     */
    protected array $activityLogExclude = ['updated_at', 'created_at'];

    protected $fillable = [
        'fermentation_round_id',
        'entry_date',
        'temperature',
        'brix_or_density',
        'measurement_type',
        'free_so2',
        'notes',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'temperature' => 'decimal:2',
            'brix_or_density' => 'decimal:4',
            'free_so2' => 'decimal:2',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────

    /**
     * @return BelongsTo<FermentationRound, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(FermentationRound::class, 'fermentation_round_id');
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
     * @param  Builder<FermentationEntry>  $query
     * @return Builder<FermentationEntry>
     */
    public function scopeForRound(Builder $query, string $roundId): Builder
    {
        return $query->where('fermentation_round_id', $roundId);
    }

    /**
     * @param  Builder<FermentationEntry>  $query
     * @return Builder<FermentationEntry>
     */
    public function scopeRecordedBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('entry_date', [$from, $to]);
    }
}
