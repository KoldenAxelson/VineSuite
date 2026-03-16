<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDryGoodsItemRequest;
use App\Http\Requests\UpdateDryGoodsItemRequest;
use App\Http\Resources\DryGoodsItemResource;
use App\Http\Responses\ApiResponse;
use App\Models\DryGoodsItem;
use App\Services\EventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DryGoodsController extends Controller
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * List dry goods items with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DryGoodsItem::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('item_type')) {
            $query->ofType($request->input('item_type'));
        }

        if ($request->boolean('below_reorder')) {
            $query->belowReorderPoint();
        }

        $query->orderBy('name');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (DryGoodsItem $item) => new DryGoodsItemResource($item));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Show a single dry goods item.
     */
    public function show(DryGoodsItem $dryGoodsItem): JsonResponse
    {
        return ApiResponse::success(new DryGoodsItemResource($dryGoodsItem));
    }

    /**
     * Create a new dry goods item.
     */
    public function store(StoreDryGoodsItemRequest $request): JsonResponse
    {
        $item = DryGoodsItem::create($request->validated());

        $this->eventLogger->log(
            entityType: 'dry_goods_item',
            entityId: $item->id,
            operationType: 'dry_goods_created',
            payload: [
                'name' => $item->name,
                'item_type' => $item->item_type,
                'unit_of_measure' => $item->unit_of_measure,
                'on_hand' => (float) $item->on_hand,
                'cost_per_unit' => $item->cost_per_unit !== null ? (float) $item->cost_per_unit : null,
            ],
            performedBy: $request->user()->id,
            performedAt: now(),
        );

        Log::info('Dry goods item created', [
            'item_id' => $item->id,
            'name' => $item->name,
            'item_type' => $item->item_type,
            'tenant_id' => tenant('id'),
        ]);

        return ApiResponse::created(new DryGoodsItemResource($item));
    }

    /**
     * Update an existing dry goods item.
     */
    public function update(UpdateDryGoodsItemRequest $request, DryGoodsItem $dryGoodsItem): JsonResponse
    {
        $dryGoodsItem->update($request->validated());

        $this->eventLogger->log(
            entityType: 'dry_goods_item',
            entityId: $dryGoodsItem->id,
            operationType: 'dry_goods_updated',
            payload: [
                'name' => $dryGoodsItem->name,
                'item_type' => $dryGoodsItem->item_type,
                'on_hand' => (float) $dryGoodsItem->on_hand,
                'is_active' => $dryGoodsItem->is_active,
            ],
            performedBy: $request->user()->id,
            performedAt: now(),
        );

        Log::info('Dry goods item updated', [
            'item_id' => $dryGoodsItem->id,
            'name' => $dryGoodsItem->name,
            'tenant_id' => tenant('id'),
        ]);

        return ApiResponse::success(new DryGoodsItemResource($dryGoodsItem));
    }
}
