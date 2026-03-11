<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\BarrelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Barrel metadata — extends a vessel with barrel-specific tracking fields.
 *
 * This is a 1:1 extension of the Vessel model for type=barrel. Every barrel
 * has a corresponding vessel record; this table adds cooperage, toast, oak
 * type, and usage tracking.
 *
 * @property string $id UUID
 * @property string $vessel_id FK to vessels.id
 * @property string|null $cooperage Cooperage (manufacturer)
 * @property string|null $toast_level light|medium|medium_plus|heavy
 * @property string|null $oak_type french|american|hungarian|other
 * @property string|null $forest_origin Forest of origin
 * @property string $volume_gallons Barrel volume in gallons
 * @property int $years_used Number of years the barrel has been used
 * @property string|null $qr_code QR/barcode label for scanning
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Barrel extends Model
{
    /** @use HasFactory<BarrelFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    public const TOAST_LEVELS = ['light', 'medium', 'medium_plus', 'heavy'];

    public const OAK_TYPES = ['french', 'american', 'hungarian', 'other'];

    /** @var array<int, string> */
    protected array $activityLogExclude = ['updated_at', 'created_at'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'vessel_id',
        'cooperage',
        'toast_level',
        'oak_type',
        'forest_origin',
        'volume_gallons',
        'years_used',
        'qr_code',
    ];

    protected function casts(): array
    {
        return [
            'volume_gallons' => 'decimal:4',
            'years_used' => 'integer',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * The vessel record for this barrel.
     *
     * @return BelongsTo<Vessel, $this>
     */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    // ─── Scopes ──────────────────────────────────────────────────

    /**
     * Filter by cooperage.
     *
     * @param  Builder<Barrel>  $query
     * @return Builder<Barrel>
     */
    public function scopeFromCooperage(Builder $query, string $cooperage): Builder
    {
        return $query->where('cooperage', 'ilike', "%{$cooperage}%");
    }

    /**
     * Filter by oak type.
     *
     * @param  Builder<Barrel>  $query
     * @return Builder<Barrel>
     */
    public function scopeOfOakType(Builder $query, string $oakType): Builder
    {
        return $query->where('oak_type', $oakType);
    }

    /**
     * Filter by toast level.
     *
     * @param  Builder<Barrel>  $query
     * @return Builder<Barrel>
     */
    public function scopeWithToast(Builder $query, string $toastLevel): Builder
    {
        return $query->where('toast_level', $toastLevel);
    }

    /**
     * Filter by years used.
     *
     * @param  Builder<Barrel>  $query
     * @return Builder<Barrel>
     */
    public function scopeWithYearsUsed(Builder $query, int $yearsUsed): Builder
    {
        return $query->where('years_used', $yearsUsed);
    }

    /**
     * Filter by minimum years used (for finding old barrels).
     *
     * @param  Builder<Barrel>  $query
     * @return Builder<Barrel>
     */
    public function scopeMinYearsUsed(Builder $query, int $minYears): Builder
    {
        return $query->where('years_used', '>=', $minYears);
    }
}
