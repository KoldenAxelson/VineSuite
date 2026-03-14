<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transfer;
use App\Models\Vessel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * TransferService — business logic for wine transfers between vessels.
 *
 * Transfers are DESTRUCTIVE operations: the server validates volume on receipt.
 * If two offline devices try to transfer more than a vessel contains, the second
 * gets a conflict error for manual resolution.
 *
 * Updates the lot_vessel pivot to reflect volume changes:
 * - Source vessel: decreases volume (or empties entirely)
 * - Target vessel: creates or updates the active pivot record
 */
class TransferService
{
    public function __construct(
        protected EventLogger $eventLogger,
    ) {}

    /**
     * Execute a transfer between vessels.
     *
     * @param  array<string, mixed>  $data  Validated transfer data
     * @param  string  $performedBy  UUID of the user
     *
     * @throws ValidationException If source vessel has insufficient volume
     */
    public function executeTransfer(array $data, string $performedBy): Transfer
    {
        return DB::transaction(function () use ($data, $performedBy) {
            $fromVessel = Vessel::findOrFail($data['from_vessel_id']);
            $toVessel = Vessel::findOrFail($data['to_vessel_id']);

            $requestedVolume = (float) $data['volume_gallons'];
            $variance = (float) ($data['variance_gallons'] ?? 0);
            $sourceCurrentVolume = $fromVessel->current_volume;

            // Validate source has enough volume
            if ($requestedVolume > $sourceCurrentVolume) {
                throw ValidationException::withMessages([
                    'volume_gallons' => [
                        "Cannot transfer {$requestedVolume} gallons — source vessel \"{$fromVessel->name}\" only contains {$sourceCurrentVolume} gallons.",
                    ],
                ]);
            }

            // Warn if target would overfill (log warning but don't block)
            $targetCapacity = (float) $toVessel->capacity_gallons;
            $targetCurrentVolume = $toVessel->current_volume;
            $netVolume = $requestedVolume - $variance;
            if (($targetCurrentVolume + $netVolume) > $targetCapacity) {
                Log::warning('Transfer would overfill target vessel', [
                    'to_vessel_id' => $toVessel->id,
                    'to_vessel_name' => $toVessel->name,
                    'target_capacity' => $targetCapacity,
                    'current_volume' => $targetCurrentVolume,
                    'incoming_volume' => $netVolume,
                    'tenant_id' => tenant('id'),
                ]);
            }

            // Create the transfer record
            $data['performed_by'] = $performedBy;
            $data['performed_at'] = $data['performed_at'] ?? now();

            $transfer = Transfer::create($data);

            // Update lot_vessel pivot — source vessel
            $this->decreaseVesselVolume($fromVessel, $data['lot_id'], $requestedVolume);

            // Update lot_vessel pivot — target vessel
            $this->increaseVesselVolume($toVessel, $data['lot_id'], $netVolume);

            // Write transfer_executed event
            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $transfer->lot_id,
                operationType: 'transfer_executed',
                payload: [
                    'transfer_id' => $transfer->id,
                    'from_vessel_id' => $transfer->from_vessel_id,
                    'from_vessel_name' => $fromVessel->name,
                    'to_vessel_id' => $transfer->to_vessel_id,
                    'to_vessel_name' => $toVessel->name,
                    'volume_gallons' => (float) $transfer->volume_gallons,
                    'variance_gallons' => (float) $transfer->variance_gallons,
                    'transfer_type' => $transfer->transfer_type,
                ],
                performedBy: $performedBy,
                performedAt: $transfer->performed_at,
            );

            Log::info('Transfer executed', [
                'transfer_id' => $transfer->id,
                'lot_id' => $transfer->lot_id,
                'from' => $fromVessel->name,
                'to' => $toVessel->name,
                'volume' => $transfer->volume_gallons,
                'variance' => $transfer->variance_gallons,
                'tenant_id' => tenant('id'),
                'user_id' => $performedBy,
            ]);

            return $transfer->load(['lot', 'fromVessel', 'toVessel', 'performer']);
        });
    }

    /**
     * Decrease volume in a vessel's active lot_vessel pivot.
     * If volume reaches zero, marks the pivot as emptied.
     */
    private function decreaseVesselVolume(Vessel $vessel, string $lotId, float $amount): void
    {
        $pivot = DB::table('lot_vessel')
            ->where('vessel_id', $vessel->id)
            ->where('lot_id', $lotId)
            ->whereNull('emptied_at')
            ->first();

        if (! $pivot) {
            return;
        }

        $newVolume = max(0, (float) $pivot->volume_gallons - $amount);

        if ($newVolume <= 0) {
            // Vessel is now empty
            DB::table('lot_vessel')
                ->where('id', $pivot->id)
                ->update([
                    'volume_gallons' => 0,
                    'emptied_at' => now(),
                    'updated_at' => now(),
                ]);

            // Update vessel status to empty if no other active lots
            $activeCount = DB::table('lot_vessel')
                ->where('vessel_id', $vessel->id)
                ->whereNull('emptied_at')
                ->where('id', '!=', $pivot->id)
                ->count();

            if ($activeCount === 0) {
                $vessel->update(['status' => 'empty']);
            }
        } else {
            DB::table('lot_vessel')
                ->where('id', $pivot->id)
                ->update([
                    'volume_gallons' => $newVolume,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Increase volume in a vessel's active lot_vessel pivot.
     * Creates a new pivot record if the lot isn't already in the vessel.
     */
    private function increaseVesselVolume(Vessel $vessel, string $lotId, float $amount): void
    {
        $pivot = DB::table('lot_vessel')
            ->where('vessel_id', $vessel->id)
            ->where('lot_id', $lotId)
            ->whereNull('emptied_at')
            ->first();

        if ($pivot) {
            // Add to existing volume
            DB::table('lot_vessel')
                ->where('id', $pivot->id)
                ->update([
                    'volume_gallons' => (float) $pivot->volume_gallons + $amount,
                    'updated_at' => now(),
                ]);
        } else {
            // Create new pivot record
            DB::table('lot_vessel')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'lot_id' => $lotId,
                'vessel_id' => $vessel->id,
                'volume_gallons' => $amount,
                'filled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Ensure vessel status is in_use
        if ($vessel->status !== 'in_use') {
            $vessel->update(['status' => 'in_use']);
        }
    }
}
