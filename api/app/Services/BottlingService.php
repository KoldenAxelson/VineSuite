<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\BottlingServiceInterface;
use App\Contracts\LotServiceInterface;
use App\Exceptions\Domain\DuplicateOperationException;
use App\Exceptions\Domain\InsufficientVolumeException;
use App\Models\BottlingComponent;
use App\Models\BottlingRun;
use App\Models\DryGoodsItem;
use App\Models\Lot;
use App\Support\LogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BottlingService — business logic for bottling runs.
 *
 * Bottling converts bulk wine (gallons) into case goods (bottles/cases).
 * On completion:
 * - Lot volume is deducted via LotService::adjustVolume()
 * - Cases produced is calculated
 * - SKU is auto-generated if not provided
 * - `bottling_completed` event is written
 * - Lot status can be set to 'bottled'
 * - Packaging materials auto-deducted from dry goods inventory (when inventory_item_id linked)
 * - Per-bottle COGS calculated via CostAccumulationService
 */
class BottlingService implements BottlingServiceInterface
{
    public function __construct(
        private readonly EventLogger $eventLogger,
        private readonly LotServiceInterface $lotService,
        private readonly CostAccumulationService $costService,
    ) {}

    /**
     * Create a bottling run with components.
     *
     * @param  array<string, mixed>  $data  Validated bottling run data with 'components' array
     * @param  string  $performedBy  UUID of the user
     */
    public function createBottlingRun(array $data, string $performedBy): BottlingRun
    {
        return DB::transaction(function () use ($data, $performedBy) {
            $components = $data['components'] ?? [];
            unset($data['components']);

            $data['performed_by'] = $performedBy;
            $data['status'] = 'planned';

            // Calculate cases produced
            $bottlesFilled = (int) $data['bottles_filled'];
            $bottlesPerCase = (int) ($data['bottles_per_case'] ?? 12);
            $data['cases_produced'] = intdiv($bottlesFilled, $bottlesPerCase);

            $run = BottlingRun::create($data);

            // Create component records
            foreach ($components as $component) {
                BottlingComponent::create([
                    'bottling_run_id' => $run->id,
                    'component_type' => $component['component_type'],
                    'product_name' => $component['product_name'],
                    'quantity_used' => $component['quantity_used'],
                    'quantity_wasted' => $component['quantity_wasted'] ?? 0,
                    'unit' => $component['unit'] ?? 'each',
                    'inventory_item_id' => $component['inventory_item_id'] ?? null,
                    'notes' => $component['notes'] ?? null,
                ]);
            }

            Log::info('Bottling run created', LogContext::with([
                'bottling_run_id' => $run->id,
                'lot_id' => $run->lot_id,
                'bottles_filled' => $bottlesFilled,
                'format' => $run->bottle_format,
            ], $performedBy));

            return $run->load(['components', 'lot', 'performer']);
        });
    }

    /**
     * Complete a bottling run — deducts lot volume and writes event.
     *
     * @throws DuplicateOperationException If run is already completed
     * @throws InsufficientVolumeException If lot has insufficient volume
     */
    public function completeBottlingRun(BottlingRun $run, string $performedBy): BottlingRun
    {
        if ($run->status === 'completed') {
            throw new DuplicateOperationException(
                operationType: 'bottling_run',
                entityId: $run->id,
                message: 'This bottling run is already completed.',
            );
        }

        return DB::transaction(function () use ($run, $performedBy) {
            $lot = Lot::findOrFail($run->lot_id);
            $volumeToDeduct = (float) $run->volume_bottled_gallons;
            $lotVolume = (float) $lot->volume_gallons;

            // Deduct volume from lot — throws InsufficientVolumeException if not enough
            $lot = $this->lotService->adjustVolume(
                lot: $lot,
                deltaGallons: -$volumeToDeduct,
                reason: 'bottling_completed',
                performedBy: $performedBy,
                context: ['bottling_run_id' => $run->id],
            );
            $newVolume = (float) $lot->volume_gallons;

            // Auto-generate SKU if not provided
            $sku = $run->sku;
            if (! $sku) {
                $varietyCode = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $lot->variety) ?? '', 0, 4));
                $sku = "{$varietyCode}-{$lot->vintage}-{$run->bottle_format}-".strtoupper(substr($run->id, 0, 6));
            }

            // Calculate final cases
            $bottlesFilled = (int) $run->bottles_filled;
            $bottlesPerCase = (int) $run->bottles_per_case;
            $casesProduced = intdiv($bottlesFilled, $bottlesPerCase);

            // Update run to completed
            $run->update([
                'status' => 'completed',
                'sku' => $sku,
                'cases_produced' => $casesProduced,
                'completed_at' => now(),
            ]);

            // If lot volume is now zero, optionally mark as bottled
            if ($newVolume <= 0) {
                $lot->update(['status' => 'bottled']);

                $this->eventLogger->log(
                    entityType: 'lot',
                    entityId: $lot->id,
                    operationType: 'lot_status_changed',
                    payload: [
                        'old_status' => 'in_progress',
                        'new_status' => 'bottled',
                        'reason' => 'bottling_completed',
                    ],
                    performedBy: $performedBy,
                    performedAt: now(),
                );
            }

            // Write bottling_completed event on the lot
            $run->load('components');
            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'bottling_completed',
                payload: [
                    'bottling_run_id' => $run->id,
                    'bottle_format' => $run->bottle_format,
                    'bottles_filled' => $bottlesFilled,
                    'bottles_breakage' => (int) $run->bottles_breakage,
                    'waste_percent' => (float) $run->waste_percent,
                    'volume_bottled_gallons' => $volumeToDeduct,
                    'cases_produced' => $casesProduced,
                    'sku' => $sku,
                    'old_lot_volume_gallons' => $lotVolume,
                    'new_lot_volume_gallons' => $newVolume,
                    'components' => $run->components->map(fn (BottlingComponent $c) => [
                        'component_type' => $c->component_type,
                        'product_name' => $c->product_name,
                        'quantity_used' => $c->quantity_used,
                        'quantity_wasted' => $c->quantity_wasted,
                    ])->all(),
                ],
                performedBy: $performedBy,
                performedAt: now(),
            );

            // Auto-deduct packaging materials from dry goods inventory
            $this->deductPackagingInventory($run, $performedBy);

            // Calculate per-bottle COGS
            $this->costService->calculateBottlingCogs(
                lot: $lot,
                bottlingRun: $run,
                performedBy: $performedBy,
            );

            Log::info('Bottling run completed', LogContext::with([
                'bottling_run_id' => $run->id,
                'lot_id' => $lot->id,
                'sku' => $sku,
                'cases_produced' => $casesProduced,
                'volume_deducted' => $volumeToDeduct,
            ], $performedBy));

            return $run->fresh(['components', 'lot', 'performer']);
        });
    }

    /**
     * Deduct packaging materials from dry goods inventory for each linked component.
     *
     * Follows the same pattern as AdditionService::deductInventory():
     * lockForUpdate to prevent races, allows negative on_hand (winery may need to
     * record usage even if stock count is inaccurate), writes dry_goods_deducted event.
     */
    private function deductPackagingInventory(BottlingRun $run, string $performedBy): void
    {
        foreach ($run->components as $component) {
            if (! $component->inventory_item_id) {
                continue;
            }

            /** @var DryGoodsItem|null $dryGoodsItem */
            $dryGoodsItem = DryGoodsItem::lockForUpdate()->find($component->inventory_item_id);

            if (! $dryGoodsItem) {
                Log::warning('Bottling component linked to non-existent dry goods item', LogContext::with([
                    'bottling_run_id' => $run->id,
                    'component_id' => $component->id,
                    'inventory_item_id' => $component->inventory_item_id,
                ]));

                continue;
            }

            $deductAmount = (float) $component->quantity_used;
            $previousOnHand = (float) $dryGoodsItem->on_hand;

            $dryGoodsItem->decrement('on_hand', $deductAmount);

            $this->eventLogger->log(
                entityType: 'dry_goods_item',
                entityId: $dryGoodsItem->id,
                operationType: 'dry_goods_deducted',
                payload: [
                    'dry_goods_name' => $dryGoodsItem->name,
                    'bottling_run_id' => $run->id,
                    'component_type' => $component->component_type,
                    'deducted_quantity' => $deductAmount,
                    'unit' => $component->unit,
                    'previous_on_hand' => $previousOnHand,
                    'new_on_hand' => $previousOnHand - $deductAmount,
                ],
                performedBy: $performedBy,
                performedAt: now(),
            );

            Log::info('Dry goods auto-deducted from bottling', LogContext::with([
                'dry_goods_id' => $dryGoodsItem->id,
                'dry_goods_name' => $dryGoodsItem->name,
                'bottling_run_id' => $run->id,
                'component_type' => $component->component_type,
                'deducted' => $deductAmount,
                'previous_on_hand' => $previousOnHand,
                'new_on_hand' => $previousOnHand - $deductAmount,
            ]));
        }
    }
}
