<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockMovementResource;
use App\Http\Responses\ApiResponse;
use App\Models\StockLevel;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Transfer stock from one location to another.
     *
     * Validates that sufficient available stock exists at the source
     * before executing. Creates paired movements via InventoryService.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'sku_id' => ['required', 'uuid', 'exists:case_goods_skus,id'],
            'from_location_id' => ['required', 'uuid', 'exists:locations,id'],
            'to_location_id' => ['required', 'uuid', 'exists:locations,id', 'different:from_location_id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $skuId = $request->input('sku_id');
        $fromLocationId = $request->input('from_location_id');
        $quantity = $request->integer('quantity');

        // Check available stock at source
        /** @var StockLevel|null $sourceLevel */
        $sourceLevel = StockLevel::query()
            ->where('sku_id', $skuId)
            ->where('location_id', $fromLocationId)
            ->first();

        $available = $sourceLevel ? $sourceLevel->available : 0;

        if ($quantity > $available) {
            return ApiResponse::error(
                'Insufficient stock at source location. Available: '.$available.', requested: '.$quantity,
                422,
            );
        }

        $result = $this->inventoryService->transfer(
            skuId: $skuId,
            fromLocationId: $fromLocationId,
            toLocationId: $request->input('to_location_id'),
            quantity: $quantity,
            performedBy: $request->user()->id,
            notes: $request->input('notes'),
        );

        $result['from']->load(['sku', 'location']);
        $result['to']->load(['sku', 'location']);

        return ApiResponse::created([
            'transfer_id' => $result['from']->reference_id,
            'from_movement' => new StockMovementResource($result['from']),
            'to_movement' => new StockMovementResource($result['to']),
        ]);
    }
}
