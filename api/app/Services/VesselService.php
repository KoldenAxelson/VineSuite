<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Vessel;
use App\Support\LogContext;
use Illuminate\Support\Facades\Log;

/**
 * VesselService — business logic for vessel operations.
 *
 * All vessel mutations go through this service. It coordinates:
 * - Creating vessels with event log writes
 * - Status transitions with event logging
 * - Structured logging with tenant context
 */
class VesselService
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * Create a new vessel and write a vessel_created event.
     *
     * @param  array<string, mixed>  $data  Validated vessel data
     * @param  string  $performedBy  UUID of the user creating the vessel
     */
    public function createVessel(array $data, string $performedBy): Vessel
    {
        $vessel = Vessel::create($data);

        $this->eventLogger->log(
            entityType: 'vessel',
            entityId: $vessel->id,
            operationType: 'vessel_created',
            payload: [
                'name' => $vessel->name,
                'type' => $vessel->type,
                'capacity_gallons' => (float) $vessel->capacity_gallons,
                'material' => $vessel->material,
                'location' => $vessel->location,
                'status' => $vessel->status,
            ],
            performedBy: $performedBy,
            performedAt: now(),
        );

        Log::info('Vessel created', LogContext::with([
            'vessel_id' => $vessel->id,
            'name' => $vessel->name,
            'type' => $vessel->type,
            'capacity_gallons' => $vessel->capacity_gallons,
        ], $performedBy));

        return $vessel;
    }

    /**
     * Update a vessel's mutable fields.
     *
     * @param  array<string, mixed>  $data  Validated update data
     * @param  string  $performedBy  UUID of the user
     */
    public function updateVessel(Vessel $vessel, array $data, string $performedBy): Vessel
    {
        $oldValues = $vessel->only(array_keys($data));

        $vessel->update($data);

        // Log status changes as events
        if (isset($data['status']) && $oldValues['status'] !== $data['status']) {
            $this->eventLogger->log(
                entityType: 'vessel',
                entityId: $vessel->id,
                operationType: 'vessel_status_changed',
                payload: [
                    'old_status' => $oldValues['status'],
                    'new_status' => $data['status'],
                ],
                performedBy: $performedBy,
                performedAt: now(),
            );
        }

        Log::info('Vessel updated', LogContext::with([
            'vessel_id' => $vessel->id,
            'changes' => $data,
            'old_values' => $oldValues,
        ], $performedBy));

        return $vessel->fresh();
    }
}
