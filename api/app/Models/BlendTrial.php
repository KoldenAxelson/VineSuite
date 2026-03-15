<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BlendTrialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Blend trial — a proposed blend of multiple source lots.
 *
 * Winemakers create draft trials, compare versions, and finalize one —
 * which creates a new blended lot and deducts volumes from source lots.
 */
class BlendTrial extends Model
{
    /** @use HasFactory<BlendTrialFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUSES = [
        'draft',
        'finalized',
        'archived',
    ];

    /** TTB requires >=75% of a single variety to label as that variety */
    public const TTB_VARIETY_THRESHOLD = 75.0;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'status',
        'version',
        'variety_composition',
        'ttb_label_variety',
        'total_volume_gallons',
        'resulting_lot_id',
        'created_by',
        'finalized_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'variety_composition' => 'array',
            'total_volume_gallons' => 'decimal:4',
            'finalized_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * @return HasMany<BlendTrialComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(BlendTrialComponent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Lot, $this>
     */
    public function resultingLot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'resulting_lot_id');
    }

    // ─── Scopes ──────────────────────────────────────────────────

    /**
     * @param  Builder<BlendTrial>  $query
     * @return Builder<BlendTrial>
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<BlendTrial>  $query
     * @return Builder<BlendTrial>
     */
    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }
}
