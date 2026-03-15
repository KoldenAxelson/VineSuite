<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Barrel;
use App\Models\Vessel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BarrelOperationService — barrel-specific operations.
 *
 * Operations:
 * - Fill: move wine from a lot into barrels, creating lot_vessel pivots
 * - Top: add small volumes from a source vessel to barrels (ullage replacement)
 * - Rack: move wine from barrels to a target vessel, logging lees weight
 * - Sample: record a barrel sample extraction
 *
 * Barrel operations generate high event volume — a winery might top 200
 * barrels in one session. Bulk operations process multiple barrels per request.
 */
class BarrelOperationService
{
    public function __construct(
        protected EventLogger $eventLogger,
    ) {}

    /**
     * Fill barrels from a lot.
     *
     * @param  string  $lotId  Source lot UUID
     * @param  array<int, array{barrel_id: string, volume_gallons: float|string}>  $barrels  Barrels to fill with volumes
     * @param  string  $performedBy  UUID of the user
     * @return array<int, array{barrel_id: string, vessel_id: string, barrel_name: string, volume_gallons: float}>
     */
    public function fillBarrels(string $lotId, array $barrels, string $performedBy): array
    {
        return DB::transaction(function () use ($lotId, $barrels, $performedBy) {
            $results = [];

            foreach ($barrels as $entry) {
                $barrel = Barrel::findOrFail($entry['barrel_id']);
                $vessel = $barrel->vessel;
                $volume = (float) $entry['volume_gallons'];

                // Create or update lot_vessel pivot
                $this->increaseVesselVolume($vessel, $lotId, $volume);

                // Write barrel_filled event
                $this->eventLogger->log(
                    entityType: 'lot',
                    entityId: $lotId,
                    operationType: 'barrel_filled',
                    payload: [
                        'barrel_id' => $barrel->id,
                        'vessel_id' => $vessel->id,
                        'vessel_name' => $vessel->name,
                        'volume_gallons' => $volume,
                        'cooperage' => $barrel->cooperage,
                        'oak_type' => $barrel->oak_type,
                        'toast_level' => $barrel->toast_level,
                    ],
                    performedBy: $performedBy,
                    performedAt: now(),
                );

                $results[] = [
                    'barrel_id' => $barrel->id,
                    'vessel_id' => $vessel->id,
                    'barrel_name' => $vessel->name,
                    'volume_gallons' => $volume,
                ];
            }

            Log::info('Barrels filled', [
                'lot_id' => $lotId,
                'barrel_count' => count($barrels),
                'tenant_id' => tenant('id'),
                'user_id' => $performedBy,
            ]);

            return $results;
        });
    }

    /**
     * Top barrels from a source vessel.
     *
     * Topping replaces ullage (air space) with wine from a source vessel.
     * Source vessel volume decreases by the total volume topped.
     *
     * @param  string  $sourceVesselId  Source vessel UUID
     * @param  string  $lotId  The lot being topped
     * @param  array<int, array{barrel_id: string, volume_gallons: float|string}>  $barrels
     * @param  string  $performedBy  UUID of the user
     * @return array<int, array{barrel_id: string, vessel_id: string, barrel_name: string, volume_gallons: float}>
     *
     * @throws ValidationException If source vessel has insufficient volume
     */
    public function topBarrels(string $sourceVesselId, string $lotId, array $barrels, string $performedBy): array
    {
        return DB::transaction(function () use ($sourceVesselId, $lotId, $barrels, $performedBy) {
            $sourceVessel = Vessel::findOrFail($sourceVesselId);

            // Calculate total volume needed
            $totalVolume = 0.0;
            foreach ($barrels as $entry) {
                $totalVolume += (float) $entry['volume_gallons'];
            }

            // Validate source has enough volume
            $sourceCurrentVolume = $sourceVessel->current_volume;
            if ($totalVolume > $sourceCurrentVolume) {
                throw ValidationException::withMessages([
                    'source_vessel_id' => [
                        "Source vessel \"{$sourceVessel->name}\" has only {$sourceCurrentVolume} gallons, but {$totalVolume} gallons needed for topping.",
                    ],
                ]);
            }

            // Decrease source vessel volume
            $this->decreaseVesselVolume($sourceVessel, $lotId, $totalVolume);

            $results = [];
            foreach ($barrels as $entry) {
                $barrel = Barrel::findOrFail($entry['barrel_id']);
                $vessel = $barrel->vessel;
                $volume = (float) $entry['volume_gallons'];

                // Increase barrel volume
                $this->increaseVesselVolume($vessel, $lotId, $volume);

                // Write barrel_topped event
                $this->eventLogger->log(
                    entityType: 'lot',
                    entityId: $lotId,
                    operationType: 'barrel_topped',
                    payload: [
                        'barrel_id' => $barrel->id,
                        'vessel_id' => $vessel->id,
                        'vessel_name' => $vessel->name,
                        'source_vessel_id' => $sourceVessel->id,
                        'source_vessel_name' => $sourceVessel->name,
                        'volume_gallons' => $volume,
                    ],
                    performedBy: $performedBy,
                    performedAt: now(),
                );

                $results[] = [
                    'barrel_id' => $barrel->id,
                    'vessel_id' => $vessel->id,
                    'barrel_name' => $vessel->name,
                    'volume_gallons' => $volume,
                ];
            }

            Log::info('Barrels topped', [
                'lot_id' => $lotId,
                'source_vessel_id' => $sourceVessel->id,
                'barrel_count' => count($barrels),
                'total_volume' => $totalVolume,
                'tenant_id' => tenant('id'),
                'user_id' => $performedBy,
            ]);

            return $results;
        });
    }

    /**
     * Rack barrels to a target vessel.
     *
     * Racking moves wine out of barrels into a tank or new barrels,
     * leaving behind lees (sediment).
     *
     * @param  string  $targetVesselId  Target vessel UUID
     * @param  string  $lotId  The lot being racked
     * @param  array<int, array{barrel_id: string, volume_gallons: float|string, lees_weight_kg?: float|string|null}>  $barrels
     * @param  string  $performedBy  UUID of the user
     * @return array<int, array{barrel_id: string, vessel_id: string, barrel_name: string, volume_gallons: float, lees_weight_kg: float|null}>
     */
    public function rackBarrels(string $targetVesselId, string $lotId, array $barrels, string $performedBy): array
    {
        return DB::transaction(function () use ($targetVesselId, $lotId, $barrels, $performedBy) {
            $targetVessel = Vessel::findOrFail($targetVesselId);

            $results = [];
            $totalVolume = 0.0;

            foreach ($barrels as $entry) {
                $barrel = Barrel::findOrFail($entry['barrel_id']);
                $vessel = $barrel->vessel;
                $volume = (float) $entry['volume_gallons'];
                $leesWeight = isset($entry['lees_weight_kg']) ? (float) $entry['lees_weight_kg'] : null;

                // Decrease barrel volume
                $this->decreaseVesselVolume($vessel, $lotId, $volume);

                $totalVolume += $volume;

                // Write barrel_racked event
                $this->eventLogger->log(
                    entityType: 'lot',
                    entityId: $lotId,
                    operationType: 'barrel_racked',
                    payload: array_filter([
                        'barrel_id' => $barrel->id,
                        'vessel_id' => $vessel->id,
                        'vessel_name' => $vessel->name,
                        'target_vessel_id' => $targetVessel->id,
                        'target_vessel_name' => $targetVessel->name,
                        'volume_gallons' => $volume,
                        'lees_weight_kg' => $leesWeight,
                    ], fn ($v) => $v !== null),
                    performedBy: $performedBy,
                    performedAt: now(),
                );

                $results[] = [
                    'barrel_id' => $barrel->id,
                    'vessel_id' => $vessel->id,
                    'barrel_name' => $vessel->name,
                    'volume_gallons' => $volume,
                    'lees_weight_kg' => $leesWeight,
                ];
            }

            // Increase target vessel volume with total racked volume
            $this->increaseVesselVolume($targetVessel, $lotId, $totalVolume);

            Log::info('Barrels racked', [
                'lot_id' => $lotId,
                'target_vessel_id' => $targetVessel->id,
                'barrel_count' => count($barrels),
                'total_volume' => $totalVolume,
                'tenant_id' => tenant('id'),
                'user_id' => $performedBy,
            ]);

            return $results;
        });
    }

    /**
     * Record a barrel sample extraction.
     *
     * @param  string  $barrelId  Barrel UUID
     * @param  string  $lotId  The lot being sampled
     * @param  float  $volumeMl  Sample volume in milliliters
     * @param  string  $performedBy  UUID of the user
     * @param  string|null  $notes  Optional notes
     * @return array{barrel_id: string, vessel_id: string, barrel_name: string, volume_ml: float}
     */
    public function recordSample(string $barrelId, string $lotId, float $volumeMl, string $performedBy, ?string $notes = null): array
    {
        $barrel = Barrel::findOrFail($barrelId);
        $vessel = $barrel->vessel;

        // Convert mL to gallons for pivot update (1 gallon = 3785.41 mL)
        $volumeGallons = $volumeMl / 3785.41;

        // Small deduction from barrel
        $this->decreaseVesselVolume($vessel, $lotId, $volumeGallons);

        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $lotId,
            operationType: 'barrel_sampled',
            payload: array_filter([
                'barrel_id' => $barrel->id,
                'vessel_id' => $vessel->id,
                'vessel_name' => $vessel->name,
                'volume_ml' => $volumeMl,
                'volume_gallons' => round($volumeGallons, 6),
                'notes' => $notes,
            ], fn ($v) => $v !== null),
            performedBy: $performedBy,
            performedAt: now(),
        );

        Log::info('Barrel sampled', [
            'barrel_id' => $barrel->id,
            'lot_id' => $lotId,
            'volume_ml' => $volumeMl,
            'tenant_id' => tenant('id'),
            'user_id' => $performedBy,
        ]);

        return [
            'barrel_id' => $barrel->id,
            'vessel_id' => $vessel->id,
            'barrel_name' => $vessel->name,
            'volume_ml' => $volumeMl,
        ];
    }

    // ─── Pivot Helpers (reused from TransferService pattern) ─────

    /**
     * Decrease volume in a vessel's active lot_vessel pivot.
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
            DB::table('lot_vessel')
                ->where('id', $pivot->id)
                ->update([
                    'volume_gallons' => 0,
                    'emptied_at' => now(),
                    'updated_at' => now(),
                ]);

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
     */
    private function increaseVesselVolume(Vessel $vessel, string $lotId, float $amount): void
    {
        $pivot = DB::table('lot_vessel')
            ->where('vessel_id', $vessel->id)
            ->where('lot_id', $lotId)
            ->whereNull('emptied_at')
            ->first();

        if ($pivot) {
            DB::table('lot_vessel')
                ->where('id', $pivot->id)
                ->update([
                    'volume_gallons' => (float) $pivot->volume_gallons + $amount,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('lot_vessel')->insert([
                'id' => (string) Str::uuid(),
                'lot_id' => $lotId,
                'vessel_id' => $vessel->id,
                'volume_gallons' => $amount,
                'filled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($vessel->status !== 'in_use') {
            $vessel->update(['status' => 'in_use']);
        }
    }
}
