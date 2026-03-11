<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkStoreWorkOrderRequest;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Http\Resources\WorkOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\WorkOrder;
use App\Models\WorkOrderTemplate;
use App\Services\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    public function __construct(
        protected WorkOrderService $workOrderService,
    ) {}

    /**
     * List work orders with optional filters.
     *
     * Filters: status, priority, assigned_to, operation_type, due_date, due_from, due_to
     */
    public function index(Request $request): JsonResponse
    {
        $query = WorkOrder::query()
            ->with(['lot', 'vessel', 'assignedUser'])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END, due_date ASC')
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->withWorkOrderStatus($request->input('status'));
        }

        if ($request->filled('priority')) {
            $query->withPriority($request->input('priority'));
        }

        if ($request->filled('assigned_to')) {
            $query->assignedTo($request->input('assigned_to'));
        }

        if ($request->filled('operation_type')) {
            $query->ofOperationType($request->input('operation_type'));
        }

        if ($request->filled('due_date')) {
            $query->dueOn($request->input('due_date'));
        }

        if ($request->filled('due_from') && $request->filled('due_to')) {
            $query->dueBetween($request->input('due_from'), $request->input('due_to'));
        }

        if ($request->filled('lot_id')) {
            $query->where('lot_id', $request->input('lot_id'));
        }

        if ($request->filled('vessel_id')) {
            $query->where('vessel_id', $request->input('vessel_id'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->paginate($perPage);

        $paginator->through(fn (WorkOrder $wo) => new WorkOrderResource($wo));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Create a single work order.
     */
    public function store(StoreWorkOrderRequest $request): JsonResponse
    {
        $workOrder = $this->workOrderService->createWorkOrder(
            data: $request->validated(),
            createdBy: $request->user()->id,
        );

        $workOrder->load(['lot', 'vessel', 'assignedUser']);

        return ApiResponse::created(new WorkOrderResource($workOrder));
    }

    /**
     * Get work order detail.
     */
    public function show(WorkOrder $workOrder): JsonResponse
    {
        $workOrder->load(['lot', 'vessel', 'assignedUser', 'completedByUser', 'template']);

        return ApiResponse::success(new WorkOrderResource($workOrder));
    }

    /**
     * Update a work order (status, priority, assignee, notes, etc.).
     *
     * For completing work orders, use the dedicated complete endpoint.
     */
    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $workOrder = $this->workOrderService->updateWorkOrder(
            workOrder: $workOrder,
            data: $request->validated(),
            updatedBy: $request->user()->id,
        );

        $workOrder->load(['lot', 'vessel', 'assignedUser', 'completedByUser']);

        return ApiResponse::success(new WorkOrderResource($workOrder));
    }

    /**
     * Complete a work order — dedicated endpoint for completion.
     */
    public function complete(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'completion_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $workOrder = $this->workOrderService->completeWorkOrder(
            workOrder: $workOrder,
            completionData: $validated,
            completedBy: $request->user()->id,
        );

        $workOrder->load(['lot', 'vessel', 'assignedUser', 'completedByUser']);

        return ApiResponse::success(new WorkOrderResource($workOrder));
    }

    /**
     * Bulk create work orders — same operation across multiple lots/vessels.
     */
    public function bulkStore(BulkStoreWorkOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $baseData = collect($validated)->except('targets')->toArray();
        $targets = $validated['targets'];

        $workOrders = $this->workOrderService->bulkCreate(
            baseData: $baseData,
            targets: $targets,
            createdBy: $request->user()->id,
        );

        return ApiResponse::created([
            'count' => $workOrders->count(),
            'work_orders' => $workOrders->map(fn (WorkOrder $wo) => [
                'id' => $wo->id,
                'operation_type' => $wo->operation_type,
                'lot_id' => $wo->lot_id,
                'vessel_id' => $wo->vessel_id,
            ]),
        ]);
    }

    /**
     * Calendar view — work orders grouped by due date.
     *
     * Returns a map of date → work_orders for a given date range.
     */
    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $workOrders = WorkOrder::query()
            ->with(['lot', 'vessel', 'assignedUser'])
            ->dueBetween($validated['from'], $validated['to'])
            ->orderBy('due_date')
            ->orderBy('priority', 'desc')
            ->get();

        $grouped = $workOrders->groupBy(fn (WorkOrder $wo) => $wo->due_date->toDateString())
            ->map(fn ($dayOrders) => $dayOrders->map(fn (WorkOrder $wo) => new WorkOrderResource($wo)));

        return ApiResponse::success($grouped);
    }

    /**
     * List available work order templates.
     */
    public function templates(): JsonResponse
    {
        $templates = WorkOrderTemplate::active()
            ->orderBy('name')
            ->get();

        return ApiResponse::success($templates);
    }
}
