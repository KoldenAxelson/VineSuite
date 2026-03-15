<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\SensoryNoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sensory/tasting note — internal winemaker evaluation of a lot.
 *
 * Lightweight observational records (not wine-review-style scoring).
 * Multiple tasters can note the same lot on the same date.
 *
 * @property string $id UUID
 * @property string $lot_id FK to lots
 * @property string $taster_id FK to users
 * @property \Illuminate\Support\Carbon $date Tasting date
 * @property string|null $rating Decimal rating value
 * @property string $rating_scale five_point or hundred_point
 * @property string|null $nose_notes Aroma descriptors
 * @property string|null $palate_notes Taste and mouthfeel descriptors
 * @property string|null $overall_notes General assessment
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Lot $lot
 * @property-read User $taster
 */
class SensoryNote extends Model
{
    /** @use HasFactory<SensoryNoteFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    public const RATING_SCALES = ['five_point', 'hundred_point'];

    protected $fillable = [
        'lot_id',
        'taster_id',
        'date',
        'rating',
        'rating_scale',
        'nose_notes',
        'palate_notes',
        'overall_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'rating' => 'decimal:2',
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
    public function taster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taster_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * @param  Builder<SensoryNote>  $query
     * @return Builder<SensoryNote>
     */
    public function scopeForLot(Builder $query, string $lotId): Builder
    {
        return $query->where('lot_id', $lotId);
    }

    /**
     * @param  Builder<SensoryNote>  $query
     * @return Builder<SensoryNote>
     */
    public function scopeByTaster(Builder $query, string $tasterId): Builder
    {
        return $query->where('taster_id', $tasterId);
    }

    /**
     * @param  Builder<SensoryNote>  $query
     * @return Builder<SensoryNote>
     */
    public function scopeRecordedBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('date', [$from, $to]);
    }
}
