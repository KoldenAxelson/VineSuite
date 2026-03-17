<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Lot;
use App\Models\LotCogsSummary;
use App\Models\LotCostEntry;
use App\Services\CostAccumulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LotCostController extends Controller
{
    public function __construct(
        private readonly CostAccumulationService $costService,
    ) {}

    /**
     * Get cost breakdown for a lot.
     */
    public function index(Request $request, Lot $lot): JsonResponse
    {
        $query = LotCostEntry::where('lot_id', $lot->id);

        if ($request->filled('cost_type')) {
            $query->ofCostType($request->input('cost_type'));
        }

        if ($request->filled('reference_type')) {
            $query->ofReferenceType($request->input('reference_type'));
        }

        $query->orderBy('performed_at');

        $entries = $query->get();

        $totalCost = $this->costService->getTotalCost($lot);
        $breakdown = $this->costService->getCostBreakdown($lot);
        $costPerGallon = $this->costService->getCostPerGallon($lot);

        return ApiResponse::success([
            'lot_id' => $lot->id,
            'lot_name' => $lot->name,
            'entries' => $entries,
            'summary' => [
                'total_cost' => $totalCost,
                'cost_breakdown' => $breakdown,
                'volume_gallons' => (string) $lot->volume_gallons,
                'cost_per_gallon' => $costPerGallon,
            ],
        ]);
    }

    /**
     * Add a manual cost entry to a lot.
     */
    public function store(Request $request, Lot $lot): JsonResponse
    {
        $validated = $request->validate([
            'cost_type' => ['required', 'string', 'in:'.implode(',', LotCostEntry::COST_TYPES)],
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric'],
            'quantity' => ['nullable', 'numeric'],
            'unit_cost' => ['nullable', 'numeric'],
            'performed_at' => ['nullable', 'date'],
        ]);

        $entry = $this->costService->recordManualCost(
            lot: $lot,
            costType: $validated['cost_type'],
            description: $validated['description'],
            amount: (string) $validated['amount'],
            performedBy: $request->user()->id,
            quantity: isset($validated['quantity']) ? (string) $validated['quantity'] : null,
            unitCost: isset($validated['unit_cost']) ? (string) $validated['unit_cost'] : null,
            performedAt: isset($validated['performed_at']) ? new \DateTimeImmutable($validated['performed_at']) : null,
        );

        return ApiResponse::created($entry);
    }

    /**
     * Get COGS summary for a lot.
     */
    public function cogs(Request $request, Lot $lot): JsonResponse
    {
        $summaries = LotCogsSummary::where('lot_id', $lot->id)
            ->orderByDesc('calculated_at')
            ->get();

        if ($summaries->isEmpty()) {
            return ApiResponse::success([
                'lot_id' => $lot->id,
                'lot_name' => $lot->name,
                'cogs_calculated' => false,
                'summaries' => [],
            ]);
        }

        $latest = $summaries->first();

        return ApiResponse::success([
            'lot_id' => $lot->id,
            'lot_name' => $lot->name,
            'cogs_calculated' => true,
            'latest' => $latest,
            'summaries' => $summaries,
        ]);
    }
}
