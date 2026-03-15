<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFilterLogRequest;
use App\Http\Resources\FilterLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\FilterLog;
use App\Services\FilterLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FilterLogController extends Controller
{
    public function __construct(
        protected FilterLogService $filterLogService,
    ) {}

    /**
     * List filter logs with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FilterLog::query()
            ->with(['lot', 'vessel', 'performer']);

        if ($request->filled('lot_id')) {
            $query->forLot($request->string('lot_id')->toString());
        }

        if ($request->filled('filter_type')) {
            $query->ofType($request->string('filter_type')->toString());
        }

        if ($request->boolean('has_fining')) {
            $query->withFining();
        }

        if ($request->filled('performed_from') && $request->filled('performed_to')) {
            $query->performedBetween(
                $request->string('performed_from')->toString(),
                $request->string('performed_to')->toString(),
            );
        }

        $paginator = $query->orderByDesc('performed_at')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::paginated(
            $paginator->through(fn (FilterLog $filterLog) => (new FilterLogResource($filterLog))->resolve()),
        );
    }

    /**
     * Log a new filtering or fining operation.
     */
    public function store(StoreFilterLogRequest $request): JsonResponse
    {
        $filterLog = $this->filterLogService->logFiltering(
            data: $request->validated(),
            performedBy: $request->user()->id,
        );

        return (new FilterLogResource($filterLog))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a single filter log with relationships.
     */
    public function show(FilterLog $filterLog): FilterLogResource
    {
        return new FilterLogResource(
            $filterLog->load(['lot', 'vessel', 'performer']),
        );
    }
}
