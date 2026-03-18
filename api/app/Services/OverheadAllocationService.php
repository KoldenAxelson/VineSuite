<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lot;
use App\Models\LotCostEntry;
use App\Models\OverheadRate;
use App\Models\WorkOrder;
use App\Support\LogContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * OverheadAllocationService — allocates fixed costs across lots.
 *
 * Allocation methods:
 * - per_gallon: rate × lot volume in gallons
 * - per_case: rate × cases produced (from bottling runs)
 * - per_labor_hour: rate × total labor hours on work orders for the lot
 *
 * Designed to be run manually (monthly or at bottling) by accountants.
 * Creates overhead cost entries on each lot proportionally.
 */
class OverheadAllocationService
{
    public function __construct(
        private readonly CostAccumulationService $costService,
    ) {}

    /**
     * Allocate a specific overhead rate to a set of lots.
     *
     * @param  Collection<int, Lot>|array<int, Lot>  $lots
     * @return array<int, LotCostEntry> Created cost entries
     */
    public function allocateToLots(OverheadRate $rate, Collection|array $lots, string $performedBy): array
    {
        $lots = $lots instanceof Collection ? $lots : collect($lots);
        $entries = [];

        foreach ($lots as $lot) {
            $amount = $this->calculateAllocation($rate, $lot);

            if ($amount === null || bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $entry = $this->costService->recordManualCost(
                lot: $lot,
                costType: 'overhead',
                description: "{$rate->name} ({$rate->allocation_method}: \${$rate->rate})",
                amount: $amount,
                performedBy: $performedBy,
            );

            $entries[] = $entry;
        }

        Log::info('Overhead allocated', LogContext::with([
            'overhead_rate_id' => $rate->id,
            'overhead_name' => $rate->name,
            'lots_allocated' => count($entries),
            'allocation_method' => $rate->allocation_method,
        ], $performedBy));

        return $entries;
    }

    /**
     * Allocate all active overhead rates to all in-progress lots.
     *
     * Convenience method for monthly batch allocation.
     *
     * @return array<string, array<int, LotCostEntry>> Overhead rate name => entries
     */
    public function allocateAllActive(string $performedBy): array
    {
        $rates = OverheadRate::active()->get();
        $lots = Lot::whereIn('status', ['in_progress', 'aging'])->get();

        $results = [];
        foreach ($rates as $rate) {
            $results[$rate->name] = $this->allocateToLots($rate, $lots, $performedBy);
        }

        return $results;
    }

    /**
     * Calculate the overhead amount for a lot based on the allocation method.
     */
    private function calculateAllocation(OverheadRate $rate, Lot $lot): ?string
    {
        $rateValue = (string) $rate->rate;

        return match ($rate->allocation_method) {
            'per_gallon' => $this->allocatePerGallon($rateValue, $lot),
            'per_case' => $this->allocatePerCase($rateValue, $lot),
            'per_labor_hour' => $this->allocatePerLaborHour($rateValue, $lot),
            default => null,
        };
    }

    /**
     * Per-gallon: rate × current volume in gallons.
     */
    private function allocatePerGallon(string $rate, Lot $lot): string
    {
        $volume = (string) $lot->volume_gallons;

        return bcmul($rate, $volume, 4);
    }

    /**
     * Per-case: rate × total cases produced from bottling runs.
     */
    private function allocatePerCase(string $rate, Lot $lot): string
    {
        $casesProduced = (string) $lot->bottlingRuns()
            ->where('status', 'completed')
            ->sum('cases_produced');

        return bcmul($rate, $casesProduced, 4);
    }

    /**
     * Per-labor-hour: rate × total labor hours on completed work orders.
     */
    private function allocatePerLaborHour(string $rate, Lot $lot): string
    {
        $totalHours = (string) WorkOrder::where('lot_id', $lot->id)
            ->where('status', 'completed')
            ->sum('hours');

        return bcmul($rate, $totalHours, 4);
    }
}
