<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PhysicalCountResource;
use App\Http\Responses\ApiResponse;
use App\Models\PhysicalCount;
use App\Services\PhysicalCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhysicalCountController extends Controller
{
    public function __construct(
        private readonly PhysicalCountService $service,
    ) {}

    /**
     * List physical count sessions with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PhysicalCount::query()->with(['location', 'lines.sku']);

        if ($request->filled('location_id')) {
            $query->forLocation($request->input('location_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $query->orderByDesc('started_at');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (PhysicalCount $count) => new PhysicalCountResource($count));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Show a single count session with lines.
     */
    public function show(PhysicalCount $physicalCount): JsonResponse
    {
        $physicalCount->load(['location', 'lines.sku']);

        return ApiResponse::success(new PhysicalCountResource($physicalCount));
    }

    /**
     * Start a new physical count session for a location.
     */
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $count = $this->service->startCount(
            locationId: $request->input('location_id'),
            startedBy: $request->user()->id,
            notes: $request->input('notes'),
        );

        return ApiResponse::created(new PhysicalCountResource($count));
    }

    /**
     * Record counted quantities for SKUs in an in-progress count.
     */
    public function recordCounts(Request $request, PhysicalCount $physicalCount): JsonResponse
    {
        $request->validate([
            'counts' => ['required', 'array', 'min:1'],
            'counts.*.sku_id' => ['required', 'uuid', 'exists:case_goods_skus,id'],
            'counts.*.counted_quantity' => ['required', 'integer', 'min:0'],
            'counts.*.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        // Transform array to keyed format expected by service
        $counts = [];
        foreach ($request->input('counts') as $entry) {
            $counts[$entry['sku_id']] = [
                'counted_quantity' => $entry['counted_quantity'],
                'notes' => $entry['notes'] ?? null,
            ];
        }

        $count = $this->service->recordCounts($physicalCount->id, $counts);

        return ApiResponse::success(new PhysicalCountResource($count));
    }

    /**
     * Approve variances and write stock adjustments.
     */
    public function approve(Request $request, PhysicalCount $physicalCount): JsonResponse
    {
        $count = $this->service->approve($physicalCount->id, $request->user()->id);

        return ApiResponse::success(new PhysicalCountResource($count));
    }

    /**
     * Cancel an in-progress count session.
     */
    public function cancel(Request $request, PhysicalCount $physicalCount): JsonResponse
    {
        $count = $this->service->cancel($physicalCount->id, $request->user()->id);

        return ApiResponse::success(new PhysicalCountResource($count));
    }
}
