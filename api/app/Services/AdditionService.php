<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AdditionServiceInterface;
use App\Models\Addition;
use App\Models\Lot;
use App\Models\RawMaterial;
use App\Support\LogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AdditionService — business logic for additions (SO2, nutrients, fining, etc.).
 *
 * Addition events are ADDITIVE for offline sync — if two cellar hands both add SO2
 * offline, both additions apply (no last-write-wins). Each addition is immutable
 * once created.
 *
 * Inventory auto-deduct: when an addition has an inventory_item_id, the
 * corresponding RawMaterial's on_hand is decremented by the total_amount.
 *
 * Cost tracking: when an addition uses a raw material with cost_per_unit,
 * a material cost entry is auto-created on the lot via CostAccumulationService.
 */
class AdditionService implements AdditionServiceInterface
{
    public function __construct(
        private readonly EventLogger $eventLogger,
        private readonly CostAccumulationService $costService,
    ) {}

    /**
     * Log an addition to a lot and write an addition_made event.
     *
     * @param  array<string, mixed>  $data  Validated addition data
     * @param  string  $performedBy  UUID of the user
     */
    public function createAddition(array $data, string $performedBy): Addition
    {
        return DB::transaction(function () use ($data, $performedBy) {
            // Set performed_by from the authenticated user
            $data['performed_by'] = $performedBy;
            $data['performed_at'] = $data['performed_at'] ?? now();

            $addition = Addition::create($data);

            // Write the addition_made event to the lot's event log
            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $addition->lot_id,
                operationType: 'addition_made',
                payload: [
                    'addition_id' => $addition->id,
                    'addition_type' => $addition->addition_type,
                    'product_name' => $addition->product_name,
                    'rate' => $addition->rate ? (float) $addition->rate : null,
                    'rate_unit' => $addition->rate_unit,
                    'total_amount' => (float) $addition->total_amount,
                    'total_unit' => $addition->total_unit,
                    'vessel_id' => $addition->vessel_id,
                ],
                performedBy: $performedBy,
                performedAt: $addition->performed_at,
            );

            // Auto-deduct from raw material inventory when linked
            if ($addition->inventory_item_id) {
                $this->deductInventory($addition, $performedBy);
            }

            // Auto-create material cost entry on the lot
            $lot = Lot::find($addition->lot_id);
            if ($lot) {
                $this->costService->recordMaterialCost(
                    lot: $lot,
                    addition: $addition,
                    performedBy: $performedBy,
                );
            }

            Log::info('Addition logged', LogContext::with([
                'addition_id' => $addition->id,
                'lot_id' => $addition->lot_id,
                'type' => $addition->addition_type,
                'product' => $addition->product_name,
                'amount' => $addition->total_amount,
                'unit' => $addition->total_unit,
            ], $performedBy));

            return $addition->load(['lot', 'vessel', 'performer']);
        });
    }

    /**
     * Get the running SO2 total for a lot.
     *
     * Sums all sulfite additions for the lot. Returns total in ppm
     * (only includes additions with rate_unit = 'ppm').
     */
    public function getSo2RunningTotal(string $lotId): float
    {
        return (float) Addition::where('lot_id', $lotId)
            ->where('addition_type', 'sulfite')
            ->where('rate_unit', 'ppm')
            ->sum('rate');
    }

    /**
     * Deduct the addition's total_amount from the linked RawMaterial's on_hand.
     *
     * Uses lockForUpdate to prevent race conditions. Allows on_hand to go
     * negative (winery may need to record usage even if stock is inaccurate).
     */
    private function deductInventory(Addition $addition, string $performedBy): void
    {
        /** @var RawMaterial|null $material */
        $material = RawMaterial::lockForUpdate()->find($addition->inventory_item_id);

        if (! $material) {
            Log::warning('Addition linked to non-existent raw material', LogContext::with([
                'addition_id' => $addition->id,
                'inventory_item_id' => $addition->inventory_item_id,
            ]));

            return;
        }

        $deductAmount = (float) $addition->total_amount;
        $previousOnHand = (float) $material->on_hand;

        $material->decrement('on_hand', $deductAmount);

        $this->eventLogger->log(
            entityType: 'raw_material',
            entityId: $material->id,
            operationType: 'raw_material_deducted',
            payload: [
                'raw_material_name' => $material->name,
                'addition_id' => $addition->id,
                'lot_id' => $addition->lot_id,
                'deducted_amount' => $deductAmount,
                'unit_of_measure' => $material->unit_of_measure,
                'previous_on_hand' => $previousOnHand,
                'new_on_hand' => $previousOnHand - $deductAmount,
            ],
            performedBy: $performedBy,
            performedAt: now(),
        );

        Log::info('Raw material auto-deducted from addition', LogContext::with([
            'raw_material_id' => $material->id,
            'raw_material_name' => $material->name,
            'addition_id' => $addition->id,
            'deducted' => $deductAmount,
            'previous_on_hand' => $previousOnHand,
            'new_on_hand' => $previousOnHand - $deductAmount,
        ]));
    }
}
