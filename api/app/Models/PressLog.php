<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PressLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Press log — records a pressing operation converting must to juice.
 *
 * Each pressing may produce multiple fractions (free run, light press, heavy press),
 * each of which can optionally become a child lot with its own event stream.
 *
 * @property string $id UUID
 * @property string $lot_id FK to parent lot
 * @property string|null $vessel_id FK to vessel (optional)
 * @property string $press_type basket|bladder|pneumatic|manual
 * @property string $fruit_weight_kg Weight of fruit/must in kg
 * @property string $total_juice_gallons Total juice yield in gallons
 * @property array<int, array<string, mixed>> $fractions JSON array of press fractions
 * @property string $yield_percent Juice yield as % of fruit weight
 * @property string|null $pomace_weight_kg Pomace weight in kg
 * @property string|null $pomace_destination Where pomace goes
 * @property string $performed_by FK to user
 * @property Carbon $performed_at
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PressLog extends Model
{
    /** @use HasFactory<PressLogFactory> */
    use HasFactory;

    use HasUuids;

    public const PRESS_TYPES = [
        'basket',
        'bladder',
        'pneumatic',
        'manual',
    ];

    public const FRACTION_TYPES = [
        'free_run',
        'light_press',
        'heavy_press',
    ];

    public const POMACE_DESTINATIONS = [
        'compost',
        'vineyard',
        'disposal',
        'sold',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'lot_id',
        'vessel_id',
        'press_type',
        'fruit_weight_kg',
        'total_juice_gallons',
        'fractions',
        'yield_percent',
        'pomace_weight_kg',
        'pomace_destination',
        'performed_by',
        'performed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'fruit_weight_kg' => 'decimal:4',
            'total_juice_gallons' => 'decimal:4',
            'fractions' => 'array',
            'yield_percent' => 'decimal:4',
            'pomace_weight_kg' => 'decimal:4',
            'performed_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * @return BelongsTo<Lot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /**
     * @return BelongsTo<Vessel, $this>
     */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // ─── Scopes ──────────────────────────────────────────────────

    /**
     * @param  Builder<PressLog>  $query
     * @return Builder<PressLog>
     */
    public function scopeOfType(Builder $query, string $pressType): Builder
    {
        return $query->where('press_type', $pressType);
    }

    /**
     * @param  Builder<PressLog>  $query
     * @return Builder<PressLog>
     */
    public function scopeForLot(Builder $query, string $lotId): Builder
    {
        return $query->where('lot_id', $lotId);
    }

    /**
     * @param  Builder<PressLog>  $query
     * @return Builder<PressLog>
     */
    public function scopePerformedBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('performed_at', [$from, $to]);
    }
}
