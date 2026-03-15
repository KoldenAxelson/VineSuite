<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\LotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Production lot — a batch of wine tracked from grape reception through bottling.
 *
 * Volume is always stored internally as gallons. Display conversion happens
 * at the API response level based on winery preference.
 *
 * @property string $id UUID
 * @property string $name Free-text lot name (winery-specific convention)
 * @property string $variety Grape variety
 * @property int $vintage Vintage year
 * @property string $source_type 'estate' or 'purchased'
 * @property array<string, mixed>|null $source_details JSON — vineyard, block, grower info
 * @property string $volume_gallons Current volume in gallons
 * @property string $status in_progress|aging|finished|bottled|sold|archived
 * @property string|null $parent_lot_id UUID of parent lot (for splits/blends)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Lot extends Model
{
    /** @use HasFactory<LotFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    public const STATUSES = [
        'in_progress',
        'aging',
        'finished',
        'bottled',
        'sold',
        'archived',
    ];

    public const SOURCE_TYPES = [
        'estate',
        'purchased',
    ];

    /**
     * Fields to exclude from activity logging.
     *
     * @var array<int, string>
     */
    protected array $activityLogExclude = ['updated_at', 'created_at'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'status' => 'in_progress',
    ];

    protected $fillable = [
        'name',
        'variety',
        'vintage',
        'source_type',
        'source_details',
        'volume_gallons',
        'status',
        'parent_lot_id',
    ];

    protected function casts(): array
    {
        return [
            'vintage' => 'integer',
            'volume_gallons' => 'decimal:4',
            'source_details' => 'array',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * Parent lot (for splits/blends).
     *
     * @return BelongsTo<Lot, $this>
     */
    public function parentLot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'parent_lot_id');
    }

    /**
     * Child lots (created from splits or pressing).
     *
     * @return HasMany<Lot, $this>
     */
    public function childLots(): HasMany
    {
        return $this->hasMany(Lot::class, 'parent_lot_id');
    }

    /**
     * Vessels this lot has been stored in (historical + current).
     *
     * @return BelongsToMany<Vessel, $this>
     */
    public function vessels(): BelongsToMany
    {
        return $this->belongsToMany(Vessel::class, 'lot_vessel')
            ->withPivot('volume_gallons', 'filled_at', 'emptied_at')
            ->withTimestamps();
    }

    /**
     * Events in the event log for this lot.
     *
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'entity_id')
            ->where('entity_type', 'lot')
            ->orderBy('performed_at');
    }

    /**
     * @return HasMany<Addition, $this>
     */
    public function additions(): HasMany
    {
        return $this->hasMany(Addition::class)->orderByDesc('performed_at');
    }

    /**
     * @return HasMany<Transfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class)->orderByDesc('performed_at');
    }

    /**
     * @return HasMany<PressLog, $this>
     */
    public function pressLogs(): HasMany
    {
        return $this->hasMany(PressLog::class)->orderByDesc('performed_at');
    }

    /**
     * @return HasMany<FilterLog, $this>
     */
    public function filterLogs(): HasMany
    {
        return $this->hasMany(FilterLog::class)->orderByDesc('performed_at');
    }

    /**
     * @return HasMany<BottlingRun, $this>
     */
    public function bottlingRuns(): HasMany
    {
        return $this->hasMany(BottlingRun::class)->orderByDesc('created_at');
    }

    /**
     * @return HasMany<LabAnalysis, $this>
     */
    public function labAnalyses(): HasMany
    {
        return $this->hasMany(LabAnalysis::class)->orderByDesc('test_date');
    }

    /**
     * @return HasMany<FermentationRound, $this>
     */
    public function fermentationRounds(): HasMany
    {
        return $this->hasMany(FermentationRound::class)->orderBy('round_number');
    }

    // ─── Scopes ──────────────────────────────────────────────────

    /**
     * Filter by variety.
     *
     * @param  Builder<Lot>  $query
     * @return Builder<Lot>
     */
    public function scopeOfVariety(Builder $query, string $variety): Builder
    {
        return $query->where('variety', $variety);
    }

    /**
     * Filter by vintage year.
     *
     * @param  Builder<Lot>  $query
     * @return Builder<Lot>
     */
    public function scopeOfVintage(Builder $query, int $vintage): Builder
    {
        return $query->where('vintage', $vintage);
    }

    /**
     * Filter by status.
     *
     * @param  Builder<Lot>  $query
     * @return Builder<Lot>
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Search by name, variety, or vintage.
     *
     * @param  Builder<Lot>  $query
     * @return Builder<Lot>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'ilike', "%{$term}%")
                ->orWhere('variety', 'ilike', "%{$term}%")
                ->orWhereRaw('CAST(vintage AS TEXT) LIKE ?', ["%{$term}%"]);
        });
    }
}
