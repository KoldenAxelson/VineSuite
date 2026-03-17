<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Addition;
use App\Models\BottlingRun;
use App\Models\CaseGoodsSku;
use App\Models\DryGoodsItem;
use App\Models\Lot;
use App\Models\LotCogsSummary;
use App\Models\LotCostEntry;
use App\Models\RawMaterial;
use App\Models\WorkOrder;
use App\Support\LogContext;
use Illuminate\Support\Facades\Log;

/**
 * CostAccumulationService — creates and queries cost entries for production lots.
 *
 * This is the single entry point for all cost mutations. Cost entries are immutable
 * (append-only). Corrections use negative adjustment entries, never edits.
 *
 * Cost types:
 * - fruit: grape purchase cost at lot creation
 * - material: raw material cost from additions (SO2, nutrients, fining, etc.)
 * - labor: work order labor hours × rate
 * - overhead: allocated fixed costs (per-gallon, per-case, per-labor-hour)
 * - transfer_in: costs rolled through from blends or splits
 */
class CostAccumulationService
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * Record a fruit purchase cost for a lot.
     *
     * Called when a lot is created with grape purchase cost information.
     *
     * @param  array{description?: string, performed_at?: \DateTimeInterface|string}  $options
     */
    public function recordFruitCost(
        Lot $lot,
        string $amount,
        ?string $quantity,
        ?string $unitCost,
        string $performedBy,
        array $options = [],
    ): LotCostEntry {
        $entry = LotCostEntry::create([
            'lot_id' => $lot->id,
            'cost_type' => 'fruit',
            'description' => $options['description'] ?? "Fruit purchase for {$lot->name}",
            'amount' => $amount,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reference_type' => 'purchase',
            'reference_id' => null,
            'performed_at' => $options['performed_at'] ?? now(),
        ]);

        $this->logCostEvent($entry, $performedBy);

        return $entry;
    }

    /**
     * Record a material cost from an addition.
     *
     * Automatically looks up the RawMaterial cost_per_unit when an addition
     * has an inventory_item_id, or accepts explicit cost parameters.
     */
    public function recordMaterialCost(
        Lot $lot,
        Addition $addition,
        string $performedBy,
        ?string $amount = null,
        ?string $quantity = null,
        ?string $unitCost = null,
    ): ?LotCostEntry {
        // If amount not explicitly provided, calculate from raw material
        if ($amount === null) {
            if ($addition->inventory_item_id) {
                /** @var RawMaterial|null $material */
                $material = RawMaterial::find($addition->inventory_item_id);
                if ($material && $material->cost_per_unit) {
                    $qty = (string) $addition->total_amount;
                    $uCost = (string) $material->cost_per_unit;
                    $amount = bcmul($qty, $uCost, 4);
                    $quantity = $qty;
                    $unitCost = $uCost;
                }
            }

            // No cost information available — skip silently
            if ($amount === null) {
                return null;
            }
        }

        $entry = LotCostEntry::create([
            'lot_id' => $lot->id,
            'cost_type' => 'material',
            'description' => "{$addition->product_name} addition",
            'amount' => $amount,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reference_type' => 'addition',
            'reference_id' => $addition->id,
            'performed_at' => $addition->performed_at ?? now(),
        ]);

        $this->logCostEvent($entry, $performedBy);

        return $entry;
    }

    /**
     * Record a labor cost from a work order.
     *
     * @param  string  $amount  Pre-calculated labor cost (hours × rate)
     * @param  string  $hours  Hours worked
     * @param  string  $hourlyRate  Hourly rate applied
     */
    public function recordLaborCost(
        Lot $lot,
        WorkOrder $workOrder,
        string $amount,
        string $hours,
        string $hourlyRate,
        string $performedBy,
    ): LotCostEntry {
        $entry = LotCostEntry::create([
            'lot_id' => $lot->id,
            'cost_type' => 'labor',
            'description' => "{$workOrder->operation_type} labor ({$hours} hrs @ \${$hourlyRate}/hr)",
            'amount' => $amount,
            'quantity' => $hours,
            'unit_cost' => $hourlyRate,
            'reference_type' => 'work_order',
            'reference_id' => $workOrder->id,
            'performed_at' => $workOrder->completed_at ?? now(),
        ]);

        $this->logCostEvent($entry, $performedBy);

        return $entry;
    }

    /**
     * Record a manual cost entry for one-off expenses.
     */
    public function recordManualCost(
        Lot $lot,
        string $costType,
        string $description,
        string $amount,
        string $performedBy,
        ?string $quantity = null,
        ?string $unitCost = null,
        ?\DateTimeInterface $performedAt = null,
    ): LotCostEntry {
        $entry = LotCostEntry::create([
            'lot_id' => $lot->id,
            'cost_type' => $costType,
            'description' => $description,
            'amount' => $amount,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reference_type' => 'manual',
            'reference_id' => null,
            'performed_at' => $performedAt ?? now(),
        ]);

        $this->logCostEvent($entry, $performedBy);

        return $entry;
    }

    /**
     * Record a transfer_in cost entry (from blend or split allocation).
     *
     * @param  string  $referenceType  'blend_allocation' or 'split_allocation'
     * @param  string|null  $referenceId  UUID of the blend trial or parent lot
     */
    public function recordTransferInCost(
        Lot $lot,
        string $description,
        string $amount,
        string $performedBy,
        string $referenceType = 'blend_allocation',
        ?string $referenceId = null,
        ?\DateTimeInterface $performedAt = null,
    ): LotCostEntry {
        $entry = LotCostEntry::create([
            'lot_id' => $lot->id,
            'cost_type' => 'transfer_in',
            'description' => $description,
            'amount' => $amount,
            'quantity' => null,
            'unit_cost' => null,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'performed_at' => $performedAt ?? now(),
        ]);

        $this->logCostEvent($entry, $performedBy);

        return $entry;
    }

    /**
     * Get total accumulated cost for a lot.
     *
     * Uses bcmath to sum all cost entries for precision.
     */
    public function getTotalCost(Lot $lot): string
    {
        $entries = LotCostEntry::where('lot_id', $lot->id)->get();
        $total = '0.0000';

        foreach ($entries as $entry) {
            $total = bcadd($total, (string) $entry->amount, 4);
        }

        return $total;
    }

    /**
     * Get cost breakdown by type for a lot.
     *
     * @return array<string, string> Map of cost_type => total amount
     */
    public function getCostBreakdown(Lot $lot): array
    {
        $entries = LotCostEntry::where('lot_id', $lot->id)->get();
        $breakdown = [];

        foreach ($entries as $entry) {
            $type = $entry->cost_type;
            $breakdown[$type] = bcadd($breakdown[$type] ?? '0.0000', (string) $entry->amount, 4);
        }

        return $breakdown;
    }

    /**
     * Get cost per gallon for a lot at its current volume.
     *
     * @return string|null Null if lot has zero volume
     */
    public function getCostPerGallon(Lot $lot): ?string
    {
        $totalCost = $this->getTotalCost($lot);
        $volume = (string) $lot->volume_gallons;

        if (bccomp($volume, '0', 4) <= 0) {
            return null;
        }

        return bcdiv($totalCost, $volume, 4);
    }

    /**
     * Write a cost_entry_created event to the event log.
     */
    private function logCostEvent(LotCostEntry $entry, string $performedBy): void
    {
        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $entry->lot_id,
            operationType: 'cost_entry_created',
            payload: [
                'cost_entry_id' => $entry->id,
                'cost_type' => $entry->cost_type,
                'amount' => (string) $entry->amount,
                'description' => $entry->description,
                'reference_type' => $entry->reference_type,
                'reference_id' => $entry->reference_id,
            ],
            performedBy: $performedBy,
            performedAt: $entry->performed_at,
        );

        Log::info('Cost entry created', LogContext::with([
            'cost_entry_id' => $entry->id,
            'lot_id' => $entry->lot_id,
            'cost_type' => $entry->cost_type,
            'amount' => (string) $entry->amount,
            'reference_type' => $entry->reference_type,
        ], $performedBy));
    }

    /**
     * Calculate per-bottle COGS at bottling completion.
     *
     * Computes: lot accumulated cost + packaging material costs + bottling labor = total COGS.
     * Creates a LotCogsSummary snapshot and updates CaseGoodsSku.cost_per_bottle.
     */
    public function calculateBottlingCogs(
        Lot $lot,
        BottlingRun $bottlingRun,
        string $performedBy,
    ): LotCogsSummary {
        $breakdown = $this->getCostBreakdown($lot);
        $bulkWineCost = $this->getTotalCost($lot);

        // Calculate packaging cost from bottling run components (dry goods with cost_per_unit)
        $packagingCostTotal = '0.0000';
        $bottlingRun->load('components');
        foreach ($bottlingRun->components as $component) {
            // Look up the dry goods item cost
            $dryGoodsItem = DryGoodsItem::where('name', 'ilike', "%{$component->product_name}%")->first();
            if ($dryGoodsItem && $dryGoodsItem->cost_per_unit) {
                $componentCost = bcmul((string) $component->quantity_used, (string) $dryGoodsItem->cost_per_unit, 4);
                $packagingCostTotal = bcadd($packagingCostTotal, $componentCost, 4);
            }
        }

        $bottlesProduced = (int) $bottlingRun->bottles_filled;
        $caseSize = (int) ($bottlingRun->bottles_per_case ?? 12);

        // Packaging cost per bottle
        $packagingCostPerBottle = $bottlesProduced > 0
            ? bcdiv($packagingCostTotal, (string) $bottlesProduced, 4)
            : '0.0000';

        // Total cost = bulk wine cost + packaging
        $totalCost = bcadd($bulkWineCost, $packagingCostTotal, 4);

        // Per-bottle cost
        $costPerBottle = $bottlesProduced > 0
            ? bcdiv($totalCost, (string) $bottlesProduced, 4)
            : null;

        // Per-case cost
        $costPerCase = $costPerBottle !== null
            ? bcmul($costPerBottle, (string) $caseSize, 4)
            : null;

        $volumeAtCalc = (string) $bottlingRun->volume_bottled_gallons;
        $costPerGallon = bccomp($volumeAtCalc, '0', 4) > 0
            ? bcdiv($totalCost, $volumeAtCalc, 4)
            : null;

        // Create the COGS summary snapshot
        $summary = LotCogsSummary::create([
            'lot_id' => $lot->id,
            'total_fruit_cost' => $breakdown['fruit'] ?? '0.0000',
            'total_material_cost' => $breakdown['material'] ?? '0.0000',
            'total_labor_cost' => $breakdown['labor'] ?? '0.0000',
            'total_overhead_cost' => $breakdown['overhead'] ?? '0.0000',
            'total_transfer_in_cost' => $breakdown['transfer_in'] ?? '0.0000',
            'total_cost' => $totalCost,
            'volume_gallons_at_calc' => $volumeAtCalc,
            'cost_per_gallon' => $costPerGallon,
            'bottles_produced' => $bottlesProduced,
            'cost_per_bottle' => $costPerBottle,
            'cost_per_case' => $costPerCase,
            'packaging_cost_per_bottle' => $packagingCostPerBottle,
            'bottling_labor_cost' => '0.0000',
            'calculated_at' => now(),
        ]);

        // Update CaseGoodsSku.cost_per_bottle if a SKU is linked
        if ($costPerBottle !== null) {
            $sku = CaseGoodsSku::where('lot_id', $lot->id)
                ->orWhere('bottling_run_id', $bottlingRun->id)
                ->first();

            if ($sku) {
                $sku->update(['cost_per_bottle' => $costPerBottle]);
            }
        }

        // Write cogs_calculated event
        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $lot->id,
            operationType: 'cogs_calculated',
            payload: [
                'cogs_summary_id' => $summary->id,
                'bottling_run_id' => $bottlingRun->id,
                'total_cost' => $totalCost,
                'cost_per_bottle' => $costPerBottle,
                'cost_per_case' => $costPerCase,
                'bottles_produced' => $bottlesProduced,
                'packaging_cost_total' => $packagingCostTotal,
            ],
            performedBy: $performedBy,
            performedAt: now(),
        );

        Log::info('COGS calculated at bottling', LogContext::with([
            'lot_id' => $lot->id,
            'bottling_run_id' => $bottlingRun->id,
            'total_cost' => $totalCost,
            'cost_per_bottle' => $costPerBottle,
            'bottles_produced' => $bottlesProduced,
        ], $performedBy));

        return $summary;
    }
}
