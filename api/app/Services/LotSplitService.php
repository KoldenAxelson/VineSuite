<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lot;
use App\Support\LogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * LotSplitService — business logic for splitting a lot into child lots.
 *
 * Splitting divides one parent lot into N child lots with specified volumes.
 * Child lots inherit the parent's variety, vintage, and source details.
 * Each child lot gets its own event stream going forward.
 *
 * COGS note: accumulated costs on the parent lot are split proportionally
 * by volume ratio — tracked via events for the cost accounting module.
 */
class LotSplitService
{
    public function __construct(
        private readonly EventLogger $eventLogger,
        private readonly CostAccumulationService $costService,
    ) {}

    /**
     * Split a lot into multiple child lots.
     *
     * @param  Lot  $parentLot  The lot to split
     * @param  array<int, array{name: string, volume_gallons: float|string}>  $children  Child lot definitions
     * @param  string  $performedBy  UUID of the user performing the split
     * @return array{parent: Lot, children: array<int, Lot>}
     *
     * @throws ValidationException If total child volume exceeds parent volume
     */
    public function splitLot(Lot $parentLot, array $children, string $performedBy): array
    {
        // Validate total child volume does not exceed parent volume
        $parentVolume = (float) $parentLot->volume_gallons;
        $totalChildVolume = 0.0;

        foreach ($children as $child) {
            $totalChildVolume += (float) $child['volume_gallons'];
        }

        if ($totalChildVolume > $parentVolume) {
            throw ValidationException::withMessages([
                'children' => [
                    "Total child volume ({$totalChildVolume} gal) exceeds parent lot volume ({$parentVolume} gal).",
                ],
            ]);
        }

        return DB::transaction(function () use ($parentLot, $children, $performedBy, $parentVolume, $totalChildVolume) {
            $createdChildren = [];
            $childDetails = [];

            foreach ($children as $child) {
                $childVolume = (float) $child['volume_gallons'];
                $volumeRatio = $parentVolume > 0 ? $childVolume / $parentVolume : 0;

                $childLot = Lot::create([
                    'name' => $child['name'],
                    'variety' => $parentLot->variety,
                    'vintage' => $parentLot->vintage,
                    'source_type' => $parentLot->source_type,
                    'source_details' => $parentLot->source_details,
                    'volume_gallons' => $childVolume,
                    'status' => 'in_progress',
                    'parent_lot_id' => $parentLot->id,
                ]);

                // Write lot_created event on the child lot
                $this->eventLogger->log(
                    entityType: 'lot',
                    entityId: $childLot->id,
                    operationType: 'lot_created',
                    payload: [
                        'name' => $childLot->name,
                        'variety' => $childLot->variety,
                        'vintage' => $childLot->vintage,
                        'source_type' => $childLot->source_type,
                        'initial_volume_gallons' => $childVolume,
                        'parent_lot_id' => $parentLot->id,
                        'split_volume_ratio' => round($volumeRatio, 6),
                    ],
                    performedBy: $performedBy,
                    performedAt: now(),
                );

                $createdChildren[] = $childLot;
                $childDetails[] = [
                    'child_lot_id' => $childLot->id,
                    'child_lot_name' => $childLot->name,
                    'volume_gallons' => $childVolume,
                    'volume_ratio' => round($volumeRatio, 6),
                ];
            }

            // Deduct total child volume from parent
            $newParentVolume = $parentVolume - $totalChildVolume;
            $parentLot->update([
                'volume_gallons' => $newParentVolume,
            ]);

            // Write lot_split event on the parent lot
            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $parentLot->id,
                operationType: 'lot_split',
                payload: [
                    'old_volume_gallons' => $parentVolume,
                    'new_volume_gallons' => $newParentVolume,
                    'total_split_volume_gallons' => $totalChildVolume,
                    'child_count' => count($createdChildren),
                    'children' => $childDetails,
                ],
                performedBy: $performedBy,
                performedAt: now(),
            );

            // Split costs proportionally to child lots by volume ratio
            $this->splitCostsToChildren(
                $parentLot,
                $createdChildren,
                (string) $parentVolume,
                $performedBy,
            );

            Log::info('Lot split', LogContext::with([
                'parent_lot_id' => $parentLot->id,
                'child_count' => count($createdChildren),
                'total_split_volume' => $totalChildVolume,
                'remaining_parent_volume' => $newParentVolume,
            ], $performedBy));

            return [
                'parent' => $parentLot->fresh(),
                'children' => $createdChildren,
            ];
        });
    }

    /**
     * Split costs from parent lot to child lots proportionally by volume.
     *
     * Each child gets: (child volume / parent volume before split) × parent total cost.
     * Cost per gallon should be identical across all children and the parent.
     *
     * @param  array<int, Lot>  $children
     */
    private function splitCostsToChildren(
        Lot $parentLot,
        array $children,
        string $parentVolumeBeforeSplit,
        string $performedBy,
    ): void {
        $parentTotalCost = $this->costService->getTotalCost($parentLot);

        if (bccomp($parentTotalCost, '0', 4) <= 0 || bccomp($parentVolumeBeforeSplit, '0', 4) <= 0) {
            return;
        }

        // Cost per gallon at time of split (same for all children)
        $costPerGallon = bcdiv($parentTotalCost, $parentVolumeBeforeSplit, 8);

        foreach ($children as $childLot) {
            $childVolume = (string) $childLot->volume_gallons;
            $proportionalCost = bcmul($costPerGallon, $childVolume, 4);

            if (bccomp($proportionalCost, '0', 4) <= 0) {
                continue;
            }

            $this->costService->recordTransferInCost(
                lot: $childLot,
                description: "Cost from parent {$parentLot->name} ({$childVolume} gal split)",
                amount: $proportionalCost,
                performedBy: $performedBy,
                referenceType: 'split_allocation',
                referenceId: $parentLot->id,
            );
        }
    }
}
