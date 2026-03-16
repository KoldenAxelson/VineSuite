<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaintenanceLogRequest;
use App\Http\Resources\MaintenanceLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\Equipment;
use App\Models\MaintenanceLog;
use App\Services\EventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MaintenanceLogController extends Controller
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * List maintenance logs for a piece of equipment.
     */
    public function index(Request $request, Equipment $equipment): JsonResponse
    {
        $query = $equipment->maintenanceLogs();

        if ($request->filled('maintenance_type')) {
            $query->ofType($request->input('maintenance_type'));
        }

        $query->orderByDesc('performed_date');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (MaintenanceLog $log) => new MaintenanceLogResource($log));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Show a single maintenance log entry.
     */
    public function show(Equipment $equipment, MaintenanceLog $maintenanceLog): JsonResponse
    {
        $maintenanceLog->load('equipment');

        return ApiResponse::success(new MaintenanceLogResource($maintenanceLog));
    }

    /**
     * Create a new maintenance log entry.
     *
     * Also updates the equipment's next_maintenance_due if next_due_date is provided.
     */
    public function store(StoreMaintenanceLogRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Auto-set performed_by to current user if not provided
        if (empty($validated['performed_by'])) {
            $validated['performed_by'] = $request->user()->id;
        }

        $log = MaintenanceLog::create($validated);

        // If the log includes a next_due_date, update the equipment's next_maintenance_due
        if ($log->next_due_date !== null) {
            $log->equipment->update(['next_maintenance_due' => $log->next_due_date]);
        }

        $this->eventLogger->log(
            entityType: 'equipment',
            entityId: $log->equipment_id,
            operationType: 'equipment_maintenance_logged',
            payload: [
                'maintenance_log_id' => $log->id,
                'equipment_name' => $log->equipment->name,
                'maintenance_type' => $log->maintenance_type,
                'performed_date' => $log->performed_date->toDateString(),
                'passed' => $log->passed,
                'next_due_date' => $log->next_due_date?->toDateString(),
            ],
            performedBy: $request->user()->id,
            performedAt: now(),
        );

        Log::info('Maintenance log created', [
            'log_id' => $log->id,
            'equipment_id' => $log->equipment_id,
            'type' => $log->maintenance_type,
            'tenant_id' => tenant('id'),
        ]);

        return ApiResponse::created(new MaintenanceLogResource($log));
    }
}
