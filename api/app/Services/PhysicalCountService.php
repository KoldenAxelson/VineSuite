<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PhysicalCount;
use App\Models\PhysicalCountLine;
use App\Models\StockLevel;
use App\Support\LogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PhysicalCountService — manages the physical inventory count workflow.
 *
 * Workflow:
 * 1. startCount()  — Create a count session for a location, snapshot system quantities
 * 2. recordCount() — Enter actual counted quantities per SKU
 * 3. approve()     — Approve variances → writes stock_adjusted movements for each discrepancy
 * 4. cancel()      — Cancel an in-progress count session
 *
 * Counts are for one location at a time. Don't auto-adjust — show variances
 * and let the user approve.
 */
class PhysicalCountService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * Start a new count session for a location.
     *
     * Snapshots current system on_hand for all SKUs with stock at this location.
     * Also includes SKUs at the location with zero stock so they can be counted.
     */
    public function startCount(string $locationId, string $startedBy, ?string $notes = null): PhysicalCount
    {
        return DB::transaction(function () use ($locationId, $startedBy, $notes) {
            $count = PhysicalCount::create([
                'location_id' => $locationId,
                'status' => 'in_progress',
                'started_by' => $startedBy,
                'started_at' => now(),
                'notes' => $notes,
            ]);

            // Snapshot system quantities for all SKUs with stock at this location
            $stockLevels = StockLevel::where('location_id', $locationId)->get();

            foreach ($stockLevels as $stockLevel) {
                PhysicalCountLine::create([
                    'physical_count_id' => $count->id,
                    'sku_id' => $stockLevel->sku_id,
                    'system_quantity' => $stockLevel->on_hand,
                ]);
            }

            $this->eventLogger->log(
                entityType: 'physical_count',
                entityId: $count->id,
                operationType: 'stock_count_started',
                payload: [
                    'location_id' => $locationId,
                    'location_name' => $count->location->name,
                    'line_count' => $stockLevels->count(),
                ],
                performedBy: $startedBy,
                performedAt: now(),
            );

            Log::info('Physical count started', LogContext::with([
                'count_id' => $count->id,
                'location_id' => $locationId,
                'lines' => $stockLevels->count(),
            ], $startedBy));

            return $count->load('lines.sku');
        });
    }

    /**
     * Record actual counted quantities for one or more SKUs.
     *
     * @param  array<string, array{counted_quantity: int, notes?: string}>  $counts  Keyed by SKU ID
     */
    public function recordCounts(string $countId, array $counts): PhysicalCount
    {
        $physicalCount = PhysicalCount::findOrFail($countId);

        if ($physicalCount->status !== 'in_progress') {
            throw new \InvalidArgumentException('Can only record counts for in-progress count sessions.');
        }

        return DB::transaction(function () use ($physicalCount, $counts) {
            foreach ($counts as $skuId => $data) {
                $line = PhysicalCountLine::where('physical_count_id', $physicalCount->id)
                    ->where('sku_id', $skuId)
                    ->first();

                if (! $line) {
                    // SKU wasn't in the snapshot — add it with system_quantity = 0
                    // (could be a new SKU discovered during counting)
                    $line = PhysicalCountLine::create([
                        'physical_count_id' => $physicalCount->id,
                        'sku_id' => $skuId,
                        'system_quantity' => 0,
                    ]);
                }

                $countedQty = (int) $data['counted_quantity'];
                $line->update([
                    'counted_quantity' => $countedQty,
                    'variance' => $countedQty - $line->system_quantity,
                    'notes' => $data['notes'] ?? $line->notes,
                ]);
            }

            return $physicalCount->load('lines.sku');
        });
    }

    /**
     * Approve the count — writes stock_adjusted movements for each non-zero variance.
     *
     * Only lines with a non-null counted_quantity and non-zero variance generate adjustments.
     */
    public function approve(string $countId, string $approvedBy): PhysicalCount
    {
        $physicalCount = PhysicalCount::findOrFail($countId);

        if ($physicalCount->status !== 'in_progress') {
            throw new \InvalidArgumentException('Can only approve in-progress count sessions.');
        }

        return DB::transaction(function () use ($physicalCount, $approvedBy) {
            $lines = $physicalCount->lines()->get();
            $adjustmentCount = 0;

            foreach ($lines as $line) {
                if ($line->counted_quantity === null) {
                    continue; // SKU was not counted — skip
                }

                if ($line->variance === 0 || $line->variance === null) {
                    continue; // No discrepancy — skip
                }

                $this->inventoryService->adjust(
                    skuId: $line->sku_id,
                    locationId: $physicalCount->location_id,
                    quantity: $line->variance,
                    performedBy: $approvedBy,
                    options: [
                        'reference_type' => 'physical_count',
                        'reference_id' => $physicalCount->id,
                        'notes' => "Physical count adjustment: system {$line->system_quantity}, counted {$line->counted_quantity}",
                    ],
                );

                $adjustmentCount++;
            }

            $physicalCount->update([
                'status' => 'completed',
                'completed_by' => $approvedBy,
                'completed_at' => now(),
            ]);

            $this->eventLogger->log(
                entityType: 'physical_count',
                entityId: $physicalCount->id,
                operationType: 'stock_counted',
                payload: [
                    'location_id' => $physicalCount->location_id,
                    'location_name' => $physicalCount->location->name,
                    'total_lines' => $lines->count(),
                    'adjustments_made' => $adjustmentCount,
                ],
                performedBy: $approvedBy,
                performedAt: now(),
            );

            Log::info('Physical count approved', LogContext::with([
                'count_id' => $physicalCount->id,
                'location_id' => $physicalCount->location_id,
                'adjustments' => $adjustmentCount,
            ], $approvedBy));

            return $physicalCount->load('lines.sku');
        });
    }

    /**
     * Cancel an in-progress count session. No stock adjustments are made.
     */
    public function cancel(string $countId, string $cancelledBy): PhysicalCount
    {
        $physicalCount = PhysicalCount::findOrFail($countId);

        if ($physicalCount->status !== 'in_progress') {
            throw new \InvalidArgumentException('Can only cancel in-progress count sessions.');
        }

        $physicalCount->update([
            'status' => 'cancelled',
            'completed_by' => $cancelledBy,
            'completed_at' => now(),
        ]);

        $this->eventLogger->log(
            entityType: 'physical_count',
            entityId: $physicalCount->id,
            operationType: 'stock_count_cancelled',
            payload: [
                'location_id' => $physicalCount->location_id,
                'location_name' => $physicalCount->location->name,
                'lines_recorded' => $physicalCount->lines()->whereNotNull('counted_quantity')->count(),
            ],
            performedBy: $cancelledBy,
            performedAt: now(),
        );

        Log::info('Physical count cancelled', LogContext::with([
            'count_id' => $physicalCount->id,
            'location_id' => $physicalCount->location_id,
        ], $cancelledBy));

        return $physicalCount;
    }
}
