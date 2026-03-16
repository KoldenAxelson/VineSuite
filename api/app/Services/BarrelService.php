<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Barrel;
use App\Models\Vessel;
use App\Support\LogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BarrelService — business logic for barrel operations.
 *
 * Barrels are a 1:1 extension of vessels (type=barrel). Creating a barrel
 * means creating both a vessel record and a barrel metadata record in one
 * transaction. The vessel tracks location/status/capacity, the barrel adds
 * cooperage, toast, oak, and usage data.
 */
class BarrelService
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * Create a barrel (vessel + barrel record) and write a barrel_created event.
     *
     * @param  array<string, mixed>  $data  Validated data (includes both vessel and barrel fields)
     * @param  string  $performedBy  UUID of the user
     */
    public function createBarrel(array $data, string $performedBy): Barrel
    {
        return DB::transaction(function () use ($data, $performedBy) {
            // Create the vessel record (type=barrel)
            $vessel = Vessel::create([
                'name' => $data['name'],
                'type' => 'barrel',
                'capacity_gallons' => $data['volume_gallons'] ?? 59.43,
                'material' => $this->deriveMaterial($data['oak_type'] ?? null),
                'location' => $data['location'] ?? null,
                'status' => $data['status'] ?? 'empty',
                'purchase_date' => $data['purchase_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Create the barrel metadata record
            $barrel = Barrel::create([
                'vessel_id' => $vessel->id,
                'cooperage' => $data['cooperage'] ?? null,
                'toast_level' => $data['toast_level'] ?? null,
                'oak_type' => $data['oak_type'] ?? null,
                'forest_origin' => $data['forest_origin'] ?? null,
                'volume_gallons' => $data['volume_gallons'] ?? 59.43,
                'years_used' => $data['years_used'] ?? 0,
                'qr_code' => $data['qr_code'] ?? null,
            ]);

            $this->eventLogger->log(
                entityType: 'barrel',
                entityId: $barrel->id,
                operationType: 'barrel_created',
                payload: [
                    'vessel_id' => $vessel->id,
                    'name' => $vessel->name,
                    'cooperage' => $barrel->cooperage,
                    'toast_level' => $barrel->toast_level,
                    'oak_type' => $barrel->oak_type,
                    'forest_origin' => $barrel->forest_origin,
                    'volume_gallons' => (float) $barrel->volume_gallons,
                    'years_used' => $barrel->years_used,
                ],
                performedBy: $performedBy,
                performedAt: now(),
            );

            Log::info('Barrel created', LogContext::with([
                'barrel_id' => $barrel->id,
                'vessel_id' => $vessel->id,
                'name' => $vessel->name,
                'cooperage' => $barrel->cooperage,
                'oak_type' => $barrel->oak_type,
            ], $performedBy));

            return $barrel->load('vessel');
        });
    }

    /**
     * Update barrel metadata and/or vessel fields.
     *
     * @param  array<string, mixed>  $data  Validated update data
     * @param  string  $performedBy  UUID of the user
     */
    public function updateBarrel(Barrel $barrel, array $data, string $performedBy): Barrel
    {
        return DB::transaction(function () use ($barrel, $data, $performedBy) {
            $oldBarrelValues = $barrel->only(array_intersect(
                array_keys($data),
                ['cooperage', 'toast_level', 'oak_type', 'forest_origin', 'years_used', 'qr_code']
            ));

            // Update barrel metadata fields
            $barrelFields = array_intersect_key($data, array_flip([
                'cooperage', 'toast_level', 'oak_type', 'forest_origin', 'years_used', 'qr_code',
            ]));

            if (! empty($barrelFields)) {
                $barrel->update($barrelFields);
            }

            // Update vessel fields (location, status, notes, name)
            $vesselFields = array_intersect_key($data, array_flip([
                'name', 'location', 'status', 'notes',
            ]));

            $vessel = $barrel->vessel;
            $oldVesselStatus = $vessel->status;

            if (! empty($vesselFields)) {
                $vessel->update($vesselFields);
            }

            // Log status change as event (retirement = status → out_of_service)
            if (isset($vesselFields['status']) && $oldVesselStatus !== $vesselFields['status']) {
                $this->eventLogger->log(
                    entityType: 'barrel',
                    entityId: $barrel->id,
                    operationType: 'barrel_status_changed',
                    payload: [
                        'old_status' => $oldVesselStatus,
                        'new_status' => $vesselFields['status'],
                    ],
                    performedBy: $performedBy,
                    performedAt: now(),
                );
            }

            Log::info('Barrel updated', LogContext::with([
                'barrel_id' => $barrel->id,
                'vessel_id' => $vessel->id,
                'changes' => $data,
            ], $performedBy));

            $barrel->refresh();
            $barrel->load('vessel');

            return $barrel;
        });
    }

    /**
     * Derive vessel material from oak type for display.
     */
    private function deriveMaterial(?string $oakType): string
    {
        return match ($oakType) {
            'french' => 'French oak',
            'american' => 'American oak',
            'hungarian' => 'Hungarian oak',
            default => 'oak',
        };
    }
}
