<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\VesselFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Production vessel — a container for wine (tank, barrel, flexitank, etc.).
 *
 * Capacity and volume are stored in gallons. A vessel can hold wine from only
 * one lot at a time (v1 simplification). Historical fills are tracked via the
 * lot_vessel pivot with filled_at/emptied_at timestamps.
 *
 * @property string $id UUID
 * @property string $name Human-readable vessel identifier (e.g., "T-01", "B-042")
 * @property string $type tank|barrel|flexitank|tote|demijohn|concrete_egg|amphora
 * @property string $capacity_gallons Maximum volume in gallons
 * @property string|null $material Vessel material (stainless, oak, concrete, etc.)
 * @property string|null $location Physical location within the winery
 * @property string $status in_use|empty|cleaning|out_of_service
 * @property \Illuminate\Support\Carbon|null $purchase_date Date of purchase
 * @property string|null $notes Free-text notes
 * @property-read float $current_volume Current volume from active lot_vessel pivot
 * @property-read float $fill_percent Fill percentage (current_volume / capacity)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Vessel extends Model
{
    /** @use HasFactory<VesselFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    public const TYPES = [
        'tank',
        'barrel',
        'flexitank',
        'tote',
        'demijohn',
        'concrete_egg',
        'amphora',
    ];

    public const STATUSES = [
        'in_use',
        'empty',
        'cleaning',
        'out_of_service',
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
        'status' => 'empty',
    ];

    protected $fillable = [
        'name',
        'type',
        'capacity_gallons',
        'material',
        'location',
        'status',
        'purchase_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'capacity_gallons' => 'decimal:4',
            'purchase_date' => 'date',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * All lots ever stored in this vessel (historical + current).
     *
     * @return BelongsToMany<Lot, $this>
     */
    public function lots(): BelongsToMany
    {
        return $this->belongsToMany(Lot::class, 'lot_vessel')
            ->withPivot('volume_gallons', 'filled_at', 'emptied_at')
            ->withTimestamps();
    }

    /**
     * The lot currently in this vessel (no emptied_at).
     *
     * @return BelongsToMany<Lot, $this>
     */
    public function currentLot(): BelongsToMany
    {
        return $this->belongsToMany(Lot::class, 'lot_vessel')
            ->withPivot('volume_gallons', 'filled_at', 'emptied_at')
            ->wherePivotNull('emptied_at');
    }

    /**
     * Events in the event log for this vessel.
     *
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'entity_id')
            ->where('entity_type', 'vessel')
            ->orderBy('performed_at');
    }

    /**
     * Barrel metadata (1:1 — only for type=barrel).
     *
     * @return HasOne<Barrel, $this>
     */
    public function barrel(): HasOne
    {
        return $this->hasOne(Barrel::class);
    }

    // ─── Accessors ──────────────────────────────────────────────

    /**
     * Get the current volume in gallons from the active lot_vessel record.
     */
    public function getCurrentVolumeAttribute(): float
    {
        /** @var \App\Models\Lot|null $currentPivot */
        $currentPivot = $this->currentLot->first();

        if (! $currentPivot) {
            return 0.0;
        }

        // @phpstan-ignore property.notFound (pivot is dynamically set by BelongsToMany)
        return (float) $currentPivot->pivot->volume_gallons;
    }

    /**
     * Get fill percentage (current volume / capacity).
     */
    public function getFillPercentAttribute(): float
    {
        $capacity = (float) $this->capacity_gallons;

        if ($capacity <= 0) {
            return 0.0;
        }

        return round(($this->current_volume / $capacity) * 100, 2);
    }

    // ─── Scopes ──────────────────────────────────────────────────

    /**
     * Filter by vessel type.
     *
     * @param  Builder<Vessel>  $query
     * @return Builder<Vessel>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Filter by status.
     *
     * @param  Builder<Vessel>  $query
     * @return Builder<Vessel>
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Filter by location.
     *
     * @param  Builder<Vessel>  $query
     * @return Builder<Vessel>
     */
    public function scopeAtLocation(Builder $query, string $location): Builder
    {
        return $query->where('location', 'ilike', "%{$location}%");
    }

    /**
     * Search by name or location.
     *
     * @param  Builder<Vessel>  $query
     * @return Builder<Vessel>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'ilike', "%{$term}%")
                ->orWhere('location', 'ilike', "%{$term}%")
                ->orWhere('material', 'ilike', "%{$term}%");
        });
    }
}
