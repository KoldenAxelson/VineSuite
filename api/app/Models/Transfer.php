<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsActivity;
use Database\Factories\TransferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transfer — records wine movement between vessels.
 *
 * Transfers are DESTRUCTIVE for offline sync — the server validates volume on receipt.
 * If two offline devices try to transfer more than a vessel contains, the second gets
 * a conflict error for manual resolution.
 *
 * @property string $id UUID
 * @property string $lot_id FK to lots
 * @property string $from_vessel_id FK to vessels (source)
 * @property string $to_vessel_id FK to vessels (target)
 * @property string $volume_gallons Volume transferred
 * @property string $transfer_type gravity|pump|filter|press
 * @property string $variance_gallons Loss/variance in gallons
 * @property string $performed_by FK to users
 * @property \Illuminate\Support\Carbon $performed_at When the transfer was physically done
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Transfer extends Model
{
    /** @use HasFactory<TransferFactory> */
    use HasFactory;

    use HasUuids;
    use LogsActivity;

    public const TRANSFER_TYPES = [
        'gravity',
        'pump',
        'filter',
        'press',
    ];

    protected $fillable = [
        'lot_id',
        'from_vessel_id',
        'to_vessel_id',
        'volume_gallons',
        'transfer_type',
        'variance_gallons',
        'performed_by',
        'performed_at',
        'notes',
    ];

    protected $attributes = [
        'variance_gallons' => 0,
    ];

    protected function casts(): array
    {
        return [
            'volume_gallons' => 'decimal:4',
            'variance_gallons' => 'decimal:4',
            'performed_at' => 'datetime',
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
     * @return BelongsTo<Vessel, $this>
     */
    public function fromVessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class, 'from_vessel_id');
    }

    /**
     * @return BelongsTo<Vessel, $this>
     */
    public function toVessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class, 'to_vessel_id');
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
     * Filter by lot.
     *
     * @param  Builder<Transfer>  $query
     * @return Builder<Transfer>
     */
    public function scopeForLot(Builder $query, string $lotId): Builder
    {
        return $query->where('lot_id', $lotId);
    }

    /**
     * Filter by transfer type.
     *
     * @param  Builder<Transfer>  $query
     * @return Builder<Transfer>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('transfer_type', $type);
    }

    /**
     * Filter transfers involving a specific vessel (source or target).
     *
     * @param  Builder<Transfer>  $query
     * @return Builder<Transfer>
     */
    public function scopeInvolvingVessel(Builder $query, string $vesselId): Builder
    {
        return $query->where(function (Builder $q) use ($vesselId) {
            $q->where('from_vessel_id', $vesselId)
                ->orWhere('to_vessel_id', $vesselId);
        });
    }

    /**
     * Filter transfers performed within a date range.
     *
     * @param  Builder<Transfer>  $query
     * @return Builder<Transfer>
     */
    public function scopePerformedBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('performed_at', [$from, $to]);
    }
}
