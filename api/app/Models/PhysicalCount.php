<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PhysicalCountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Physical inventory count session for a single location.
 *
 * A count session snapshots system quantities, collects actual counts,
 * computes variances, and — when approved — writes stock_adjusted
 * movements for each discrepancy via InventoryService.
 *
 * @property string $id UUID
 * @property string $location_id FK to locations
 * @property string $status in_progress | completed | cancelled
 * @property string $started_by FK to users
 * @property \Illuminate\Support\Carbon $started_at
 * @property string|null $completed_by FK to users
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Location $location
 * @property-read User $starter
 * @property-read User|null $completer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PhysicalCountLine> $lines
 */
class PhysicalCount extends Model
{
    /** @use HasFactory<PhysicalCountFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUSES = ['in_progress', 'completed', 'cancelled'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'status' => 'in_progress',
    ];

    protected $fillable = [
        'location_id',
        'status',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @return HasMany<PhysicalCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PhysicalCountLine::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * @param  Builder<PhysicalCount>  $query
     * @return Builder<PhysicalCount>
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * @param  Builder<PhysicalCount>  $query
     * @return Builder<PhysicalCount>
     */
    public function scopeForLocation(Builder $query, string $locationId): Builder
    {
        return $query->where('location_id', $locationId);
    }
}
