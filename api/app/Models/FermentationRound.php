<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\FermentationRoundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fermentation round — a single fermentation pass for a lot.
 *
 * Each lot may have multiple rounds: primary fermentation (Brix decrease)
 * and malolactic fermentation (malic acid decrease). Rounds track inoculation
 * details, daily entries, and status through completion.
 *
 * @property string $id UUID
 * @property string $lot_id FK to lots
 * @property int $round_number 1 for primary, 2 for ML, 3+ for re-inoculation
 * @property string $fermentation_type primary or malolactic
 * @property \Illuminate\Support\Carbon $inoculation_date When yeast/bacteria was added
 * @property string|null $yeast_strain Yeast strain used (primary fermentation)
 * @property string|null $ml_bacteria ML bacteria strain (malolactic only)
 * @property string|null $target_temp Target fermentation temperature (°F)
 * @property array<string, mixed>|null $nutrients_schedule JSON nutrient additions plan
 * @property string $status active, completed, or stuck
 * @property \Illuminate\Support\Carbon|null $completion_date When fermentation finished
 * @property \Illuminate\Support\Carbon|null $confirmation_date ML dryness confirmation date
 * @property string|null $notes Free-text notes
 * @property string|null $created_by UUID of the user who created the round
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class FermentationRound extends Model
{
    /** @use HasFactory<FermentationRoundFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    public const FERMENTATION_TYPES = [
        'primary',
        'malolactic',
    ];

    public const STATUSES = [
        'active',
        'completed',
        'stuck',
    ];

    /**
     * @var array<int, string>
     */
    protected array $activityLogExclude = ['updated_at', 'created_at'];

    protected $fillable = [
        'lot_id',
        'round_number',
        'fermentation_type',
        'inoculation_date',
        'yeast_strain',
        'ml_bacteria',
        'target_temp',
        'nutrients_schedule',
        'status',
        'completion_date',
        'confirmation_date',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'inoculation_date' => 'date',
            'target_temp' => 'decimal:2',
            'nutrients_schedule' => 'array',
            'completion_date' => 'date',
            'confirmation_date' => 'date',
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
     * @return HasMany<FermentationEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(FermentationEntry::class)->orderBy('entry_date');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * @param  Builder<FermentationRound>  $query
     * @return Builder<FermentationRound>
     */
    public function scopeForLot(Builder $query, string $lotId): Builder
    {
        return $query->where('lot_id', $lotId);
    }

    /**
     * @param  Builder<FermentationRound>  $query
     * @return Builder<FermentationRound>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('fermentation_type', $type);
    }

    /**
     * @param  Builder<FermentationRound>  $query
     * @return Builder<FermentationRound>
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<FermentationRound>  $query
     * @return Builder<FermentationRound>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
