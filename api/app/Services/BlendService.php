<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\BlendServiceInterface;
use App\Contracts\LotServiceInterface;
use App\Exceptions\Domain\DuplicateOperationException;
use App\Exceptions\Domain\InsufficientVolumeException;
use App\Models\BlendTrial;
use App\Models\BlendTrialComponent;
use App\Models\Lot;
use App\Support\LogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BlendService — business logic for blend trials and finalization.
 *
 * Blending workflow:
 * 1. Create draft trial with source lots and percentages
 * 2. Compare multiple trial versions
 * 3. Finalize: creates new blended lot, deducts volumes from sources
 *
 * TTB compliance: >=75% of a single variety to label as that variety.
 * Cost rolls through proportionally (tracked via events for COGS).
 */
class BlendService implements BlendServiceInterface
{
    public function __construct(
        private readonly EventLogger $eventLogger,
        private readonly LotServiceInterface $lotService,
        private readonly CostAccumulationService $costService,
    ) {}

    /**
     * Create a blend trial with components.
     *
     * @param  array<string, mixed>  $data  Validated trial data with 'components' array
     * @param  string  $createdBy  UUID of the user
     */
    public function createTrial(array $data, string $createdBy): BlendTrial
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $components = $data['components'];
            unset($data['components']);

            $data['created_by'] = $createdBy;
            $data['status'] = 'draft';

            // Calculate total volume and variety composition
            $totalVolume = 0;
            /** @var array<string, float> $varietyVolumes */
            $varietyVolumes = [];

            foreach ($components as $component) {
                $sourceLot = Lot::findOrFail($component['source_lot_id']);
                $volume = (float) $component['volume_gallons'];
                $totalVolume += $volume;

                $variety = $sourceLot->variety;
                $varietyVolumes[$variety] = ($varietyVolumes[$variety] ?? 0) + $volume;
            }

            // Calculate variety composition as percentages
            $varietyComposition = [];
            foreach ($varietyVolumes as $variety => $volume) {
                $varietyComposition[$variety] = $totalVolume > 0
                    ? round(($volume / $totalVolume) * 100, 4)
                    : 0;
            }

            // Determine TTB label variety (>=75% of a single variety)
            $ttbLabelVariety = null;
            foreach ($varietyComposition as $variety => $percentage) {
                if ($percentage >= BlendTrial::TTB_VARIETY_THRESHOLD) {
                    $ttbLabelVariety = $variety;
                    break;
                }
            }

            $data['variety_composition'] = $varietyComposition;
            $data['ttb_label_variety'] = $ttbLabelVariety;
            $data['total_volume_gallons'] = $totalVolume;

            $trial = BlendTrial::create($data);

            // Create component records
            foreach ($components as $component) {
                BlendTrialComponent::create([
                    'blend_trial_id' => $trial->id,
                    'source_lot_id' => $component['source_lot_id'],
                    'percentage' => $component['percentage'],
                    'volume_gallons' => $component['volume_gallons'],
                ]);
            }

            Log::info('Blend trial created', LogContext::with([
                'blend_trial_id' => $trial->id,
                'name' => $trial->name,
                'component_count' => count($components),
                'total_volume' => $totalVolume,
                'ttb_label_variety' => $ttbLabelVariety,
            ], $createdBy));

            return $trial->load(['components.sourceLot', 'creator']);
        });
    }

    /**
     * Finalize a blend trial — creates a new lot and deducts from sources.
     *
     * @throws DuplicateOperationException If trial is not in draft status
     * @throws InsufficientVolumeException If source lots lack volume
     */
    public function finalizeTrial(BlendTrial $trial, string $performedBy): BlendTrial
    {
        if ($trial->status !== 'draft') {
            throw new DuplicateOperationException(
                operationType: 'blend_finalization',
                entityId: $trial->id,
                message: 'Only draft blend trials can be finalized.',
            );
        }

        return DB::transaction(function () use ($trial, $performedBy) {
            $trial->load('components.sourceLot');

            // Validate all source lots have enough volume
            foreach ($trial->components as $component) {
                $sourceLot = $component->sourceLot;
                $requiredVolume = (float) $component->volume_gallons;
                $availableVolume = (float) $sourceLot->volume_gallons;

                if ($requiredVolume > $availableVolume) {
                    throw new InsufficientVolumeException(
                        lotId: $sourceLot->id,
                        lotName: $sourceLot->name,
                        available: $availableVolume,
                        requested: $requiredVolume,
                    );
                }
            }

            // Determine the blended lot's variety label
            $variety = $trial->ttb_label_variety ?? 'Blend';
            $vintage = null;
            $vintages = [];

            foreach ($trial->components as $component) {
                $vintages[] = $component->sourceLot->vintage;
            }
            $vintages = array_unique($vintages);
            $vintage = count($vintages) === 1 ? $vintages[0] : min($vintages);

            // Create the resulting blended lot
            $blendedLot = Lot::create([
                'name' => $trial->name,
                'variety' => $variety,
                'vintage' => $vintage,
                'source_type' => 'estate',
                'volume_gallons' => $trial->total_volume_gallons,
                'status' => 'in_progress',
                'source_details' => [
                    'blend_trial_id' => $trial->id,
                    'variety_composition' => $trial->variety_composition,
                ],
            ]);

            // Write lot_created event for the new blended lot
            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $blendedLot->id,
                operationType: 'lot_created',
                payload: [
                    'name' => $blendedLot->name,
                    'variety' => $blendedLot->variety,
                    'vintage' => $blendedLot->vintage,
                    'source_type' => 'estate',
                    'initial_volume_gallons' => (float) $blendedLot->volume_gallons,
                    'blend_trial_id' => $trial->id,
                    'variety_composition' => $trial->variety_composition,
                ],
                performedBy: $performedBy,
                performedAt: now(),
            );

            // Deduct volumes from source lots via centralized LotService
            $componentDetails = [];
            foreach ($trial->components as $component) {
                $sourceLot = $component->sourceLot;
                $deductVolume = (float) $component->volume_gallons;

                // Deduct volume via LotService — handles event logging and invariant checks
                $this->lotService->adjustVolume(
                    lot: $sourceLot,
                    deltaGallons: -$deductVolume,
                    reason: 'blend_finalization',
                    performedBy: $performedBy,
                    context: [
                        'blend_trial_id' => $trial->id,
                        'resulting_lot_id' => $blendedLot->id,
                    ],
                );

                $componentDetails[] = [
                    'source_lot_id' => $sourceLot->id,
                    'source_lot_name' => $sourceLot->name,
                    'variety' => $sourceLot->variety,
                    'percentage' => (float) $component->percentage,
                    'volume_gallons' => $deductVolume,
                ];
            }

            // Update trial to finalized
            $trial->update([
                'status' => 'finalized',
                'resulting_lot_id' => $blendedLot->id,
                'finalized_at' => now(),
            ]);

            // Write blend_finalized event on the new blended lot
            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $blendedLot->id,
                operationType: 'blend_finalized',
                payload: [
                    'blend_trial_id' => $trial->id,
                    'components' => $componentDetails,
                    'variety_composition' => $trial->variety_composition,
                    'ttb_label_variety' => $trial->ttb_label_variety,
                    'total_volume_gallons' => (float) $trial->total_volume_gallons,
                ],
                performedBy: $performedBy,
                performedAt: now(),
            );

            // Roll costs from source lots into blended lot proportionally by volume
            $this->rollCostsToBlendedLot($trial, $blendedLot, $performedBy);

            Log::info('Blend trial finalized', LogContext::with([
                'blend_trial_id' => $trial->id,
                'resulting_lot_id' => $blendedLot->id,
                'total_volume' => $trial->total_volume_gallons,
                'component_count' => $trial->components->count(),
            ], $performedBy));

            return $trial->fresh(['components.sourceLot', 'creator', 'resultingLot']);
        });
    }

    /**
     * Roll costs from source lots to the blended lot proportionally by volume.
     *
     * For each source lot, calculates: (component volume / source lot total volume) × source lot total cost
     * Creates transfer_in cost entries on the blended lot.
     *
     * Example: Lot A ($10/gal, 100 gal contribution) + Lot B ($15/gal, 50 gal contribution)
     * → Blended lot gets $1000 from A + $750 from B = $1750 total, $11.67/gal
     */
    private function rollCostsToBlendedLot(BlendTrial $trial, Lot $blendedLot, string $performedBy): void
    {
        foreach ($trial->components as $component) {
            $sourceLot = $component->sourceLot;
            $componentVolume = (string) $component->volume_gallons;
            $sourceTotalVolume = bcadd((string) $sourceLot->volume_gallons, $componentVolume, 4);

            // Get total cost of source lot at time of blend
            $sourceTotalCost = $this->costService->getTotalCost($sourceLot);

            if (bccomp($sourceTotalCost, '0', 4) <= 0 || bccomp($sourceTotalVolume, '0', 4) <= 0) {
                continue;
            }

            // Proportional cost = (componentVolume / sourceTotalVolume before deduction) × sourceTotalCost
            // Note: sourceLot volume was already deducted, so we add back componentVolume
            $proportion = bcdiv($componentVolume, $sourceTotalVolume, 8);
            $proportionalCost = bcmul($sourceTotalCost, $proportion, 4);

            if (bccomp($proportionalCost, '0', 4) <= 0) {
                continue;
            }

            $this->costService->recordTransferInCost(
                lot: $blendedLot,
                description: "Cost from {$sourceLot->name} ({$componentVolume} gal @ blend)",
                amount: $proportionalCost,
                performedBy: $performedBy,
                referenceType: 'blend_allocation',
                referenceId: $trial->id,
            );
        }
    }
}
