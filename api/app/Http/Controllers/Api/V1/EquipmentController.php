<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEquipmentRequest;
use App\Http\Requests\UpdateEquipmentRequest;
use App\Http\Resources\EquipmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Equipment;
use App\Services\EventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EquipmentController extends Controller
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * List equipment with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Equipment::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('equipment_type')) {
            $query->ofType($request->input('equipment_type'));
        }

        if ($request->filled('status')) {
            $query->ofStatus($request->input('status'));
        }

        if ($request->boolean('maintenance_overdue')) {
            $query->maintenanceDue();
        }

        if ($request->has('maintenance_due_within_days')) {
            $query->maintenanceDueSoon($request->integer('maintenance_due_within_days', 30));
        }

        $query->orderBy('name');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (Equipment $item) => new EquipmentResource($item));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Show a single equipment item with maintenance logs.
     */
    public function show(Equipment $equipment): JsonResponse
    {
        $equipment->load(['maintenanceLogs' => fn ($q) => $q->orderByDesc('performed_date')]);

        return ApiResponse::success(new EquipmentResource($equipment));
    }

    /**
     * Create a new equipment item.
     */
    public function store(StoreEquipmentRequest $request): JsonResponse
    {
        $item = Equipment::create($request->validated());

        $this->eventLogger->log(
            entityType: 'equipment',
            entityId: $item->id,
            operationType: 'equipment_created',
            payload: [
                'name' => $item->name,
                'equipment_type' => $item->equipment_type,
                'serial_number' => $item->serial_number,
                'status' => $item->status,
            ],
            performedBy: $request->user()->id,
            performedAt: now(),
        );

        Log::info('Equipment created', [
            'equipment_id' => $item->id,
            'name' => $item->name,
            'type' => $item->equipment_type,
            'tenant_id' => tenant('id'),
        ]);

        return ApiResponse::created(new EquipmentResource($item));
    }

    /**
     * Update an existing equipment item.
     */
    public function update(UpdateEquipmentRequest $request, Equipment $equipment): JsonResponse
    {
        $equipment->update($request->validated());

        $this->eventLogger->log(
            entityType: 'equipment',
            entityId: $equipment->id,
            operationType: 'equipment_updated',
            payload: [
                'name' => $equipment->name,
                'equipment_type' => $equipment->equipment_type,
                'status' => $equipment->status,
                'is_active' => $equipment->is_active,
            ],
            performedBy: $request->user()->id,
            performedAt: now(),
        );

        Log::info('Equipment updated', [
            'equipment_id' => $equipment->id,
            'name' => $equipment->name,
            'tenant_id' => tenant('id'),
        ]);

        return ApiResponse::success(new EquipmentResource($equipment));
    }
}
