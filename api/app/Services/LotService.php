<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lot;
use Illuminate\Support\Facades\Log;

/**
 * LotService — business logic for lot operations.
 *
 * All lot mutations go through this service. It coordinates:
 * - Creating lots with event log writes
 * - Status transitions with validation
 * - Structured logging with tenant context
 */
class LotService
{
    public function __construct(
        protected EventLogger $eventLogger,
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

        Log::info('Lot created', [
            'lot_id' => $lot->id,
            'name' => $lot->name,
            'variety' => $lot->variety,
            'vintage' => $lot->vintage,
            'volume_gallons' => $lot->volume_gallons,
            'tenant_id' => tenant('id'),
            'user_id' => $performedBy,
        ]);

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

        Log::info('Lot updated', [
            'lot_id' => $lot->id,
            'changes' => $data,
            'old_values' => $oldValues,
            'tenant_id' => tenant('id'),
            'user_id' => $performedBy,
        ]);

        return $lot->fresh();
    }
}
