<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRawMaterialRequest;
use App\Http\Requests\UpdateRawMaterialRequest;
use App\Http\Resources\RawMaterialResource;
use App\Http\Responses\ApiResponse;
use App\Models\RawMaterial;
use App\Services\EventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RawMaterialController extends Controller
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * List raw materials with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = RawMaterial::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('category')) {
            $query->ofCategory($request->input('category'));
        }

        if ($request->boolean('below_reorder')) {
            $query->belowReorderPoint();
        }

        if ($request->boolean('expired')) {
            $query->expired();
        }

        if ($request->has('expiring_within_days')) {
            $query->expiringSoon($request->integer('expiring_within_days', 30));
        }

        $query->orderBy('name');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (RawMaterial $item) => new RawMaterialResource($item));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Show a single raw material.
     */
    public function show(RawMaterial $rawMaterial): JsonResponse
    {
        return ApiResponse::success(new RawMaterialResource($rawMaterial));
    }

    /**
     * Create a new raw material.
     */
    public function store(StoreRawMaterialRequest $request): JsonResponse
    {
        $item = RawMaterial::create($request->validated());

        $this->eventLogger->log(
            entityType: 'raw_material',
            entityId: $item->id,
            operationType: 'raw_material_created',
            payload: [
                'name' => $item->name,
                'category' => $item->category,
                'unit_of_measure' => $item->unit_of_measure,
                'on_hand' => (float) $item->on_hand,
                'cost_per_unit' => $item->cost_per_unit !== null ? (float) $item->cost_per_unit : null,
            ],
            performedBy: $request->user()->id,
            performedAt: now(),
        );

        Log::info('Raw material created', [
            'item_id' => $item->id,
            'name' => $item->name,
            'category' => $item->category,
            'tenant_id' => tenant('id'),
        ]);

        return ApiResponse::created(new RawMaterialResource($item));
    }

    /**
     * Update an existing raw material.
     */
    public function update(UpdateRawMaterialRequest $request, RawMaterial $rawMaterial): JsonResponse
    {
        $rawMaterial->update($request->validated());

        $this->eventLogger->log(
            entityType: 'raw_material',
            entityId: $rawMaterial->id,
            operationType: 'raw_material_updated',
            payload: [
                'name' => $rawMaterial->name,
                'category' => $rawMaterial->category,
                'on_hand' => (float) $rawMaterial->on_hand,
                'is_active' => $rawMaterial->is_active,
            ],
            performedBy: $request->user()->id,
            performedAt: now(),
        );

        Log::info('Raw material updated', [
            'item_id' => $rawMaterial->id,
            'name' => $rawMaterial->name,
            'tenant_id' => tenant('id'),
        ]);

        return ApiResponse::success(new RawMaterialResource($rawMaterial));
    }
}
