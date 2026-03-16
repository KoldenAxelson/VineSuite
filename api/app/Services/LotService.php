<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LotServiceInterface;
use App\Exceptions\Domain\InsufficientVolumeException;
use App\Models\Lot;
use App\Support\LogContext;
use Illuminate\Support\Facades\Log;

/**
 * LotService — business logic for lot operations.
 *
 * All lot mutations go through this service. It coordinates:
 * - Creating lots with event log writes
 * - Status transitions with validation
 * - Structured logging with tenant context
 */
class LotService implements LotServiceInterface
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * Create a new lot and write a lot_created event.
     *
     * @param  array<string, mixed>  $data  Validated lot data
     * @param  string  $performedBy  UUID of the user creating the lot
     */
    public function createLot(array $data, string $performedBy): Lot
    {
        $lot = Lot::create($data);

        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $lot->id,
            operationType: 'lot_created',
            payload: [
                'name' => $lot->name,
                'variety' => $lot->variety,
                'vintage' => $lot->vintage,
                'source_type' => $lot->source_type,
                'source_details' => $lot->source_details,
                'initial_volume_gallons' => (float) $lot->volume_gallons,
                'status' => $lot->status,
            ],
            performedBy: $performedBy,
            performedAt: now(),
        );

        Log::info('Lot created', LogContext::with([
            'lot_id' => $lot->id,
            'name' => $lot->name,
            'variety' => $lot->variety,
            'vintage' => $lot->vintage,
            'volume_gallons' => $lot->volume_gallons,
        ], $performedBy));

        return $lot;
    }

    /**
     * Update a lot's mutable fields (status, name, source_details).
     *
     * @param  array<string, mixed>  $data  Validated update data
     * @param  string  $performedBy  UUID of the user
     */
    public function updateLot(Lot $lot, array $data, string $performedBy): Lot
    {
        $oldValues = $lot->only(array_keys($data));

        $lot->update($data);

        // Log status changes as events
        if (isset($data['status']) && $oldValues['status'] !== $data['status']) {
            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'lot_status_changed',
                payload: [
                    'old_status' => $oldValues['status'],
                    'new_status' => $data['status'],
                ],
                performedBy: $performedBy,
                performedAt: now(),
            );
        }

        Log::info('Lot updated', LogContext::with([
            'lot_id' => $lot->id,
            'changes' => $data,
            'old_values' => $oldValues,
        ], $performedBy));

        return $lot->fresh();
    }

    /**
     * Adjust lot volume by a delta (positive or negative).
     *
     * This is the single codepath for all volume mutations. It enforces:
     * - Volume cannot go below zero (throws InsufficientVolumeException)
     * - Every change is logged as a volume_adjusted event
     * - Structured logging with tenant context
     *
     * @param  Lot  $lot  The lot to adjust
     * @param  float  $deltaGallons  Volume change (negative for deductions)
     * @param  string  $reason  Why the volume changed (e.g., 'bottling_completed', 'blend_finalization')
     * @param  string  $performedBy  UUID of the user
     * @param  array<string, mixed>  $context  Additional event payload context
     * @return Lot The updated lot (fresh from DB)
     *
     * @throws InsufficientVolumeException If deduction would result in negative volume
     */
    public function adjustVolume(Lot $lot, float $deltaGallons, string $reason, string $performedBy, array $context = []): Lot
    {
        $oldVolume = (float) $lot->volume_gallons;
        $newVolume = $oldVolume + $deltaGallons;

        if ($newVolume < 0) {
            throw new InsufficientVolumeException(
                lotId: $lot->id,
                lotName: $lot->name,
                available: $oldVolume,
                requested: abs($deltaGallons),
            );
        }

        $lot->update(['volume_gallons' => $newVolume]);

        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $lot->id,
            operationType: 'volume_adjusted',
            payload: array_merge([
                'reason' => $reason,
                'old_volume_gallons' => $oldVolume,
                'new_volume_gallons' => $newVolume,
                'delta_gallons' => $deltaGallons,
            ], $context),
            performedBy: $performedBy,
            performedAt: now(),
        );

        Log::info('Lot volume adjusted', LogContext::with([
            'lot_id' => $lot->id,
            'reason' => $reason,
            'delta' => $deltaGallons,
            'old_volume' => $oldVolume,
            'new_volume' => $newVolume,
        ], $performedBy));

        return $lot->fresh();
    }
}
