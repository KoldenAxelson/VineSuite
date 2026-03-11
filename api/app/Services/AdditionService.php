<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Addition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AdditionService — business logic for additions (SO2, nutrients, fining, etc.).
 *
 * Addition events are ADDITIVE for offline sync — if two cellar hands both add SO2
 * offline, both additions apply (no last-write-wins). Each addition is immutable
 * once created.
 *
 * Inventory auto-deduct is stubbed for now (depends on 04-inventory.md).
 * When inventory exists, linked additions will auto-deduct from inventory_items.
 */
class AdditionService
{
    public function __construct(
        protected EventLogger $eventLogger,
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

            // Stub: auto-deduct from inventory when inventory module exists
            // if ($addition->inventory_item_id) {
            //     $this->deductInventory($addition);
            // }

            Log::info('Addition logged', [
                'addition_id' => $addition->id,
                'lot_id' => $addition->lot_id,
                'type' => $addition->addition_type,
                'product' => $addition->product_name,
                'amount' => $addition->total_amount,
                'unit' => $addition->total_unit,
                'tenant_id' => tenant('id'),
                'user_id' => $performedBy,
            ]);

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
}
