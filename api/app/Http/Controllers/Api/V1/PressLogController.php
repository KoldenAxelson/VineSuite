<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePressLogRequest;
use App\Http\Resources\PressLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\PressLog;
use App\Services\PressLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PressLogController extends Controller
{
    public function __construct(
        protected PressLogService $pressLogService,
    ) {}

    /**
     * List press logs with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PressLog::query()
            ->with(['lot', 'vessel', 'performer']);

        if ($request->filled('lot_id')) {
            $query->forLot($request->string('lot_id')->toString());
        }

        if ($request->filled('press_type')) {
            $query->ofType($request->string('press_type')->toString());
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
            $paginator->through(fn (PressLog $pressLog) => (new PressLogResource($pressLog))->resolve()),
        );
    }

    /**
     * Log a new pressing operation.
     */
    public function store(StorePressLogRequest $request): JsonResponse
    {
        $pressLog = $this->pressLogService->logPressing(
            data: $request->validated(),
            performedBy: $request->user()->id,
        );

        return (new PressLogResource($pressLog))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a single press log with relationships.
     */
    public function show(PressLog $pressLog): PressLogResource
    {
        return new PressLogResource(
            $pressLog->load(['lot', 'vessel', 'performer']),
        );
    }
}
