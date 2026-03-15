<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBottlingRunRequest;
use App\Http\Resources\BottlingRunResource;
use App\Http\Responses\ApiResponse;
use App\Models\BottlingRun;
use App\Services\BottlingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BottlingRunController extends Controller
{
    public function __construct(
        protected BottlingService $bottlingService,
    ) {}

    /**
     * List bottling runs with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BottlingRun::query()
            ->with(['lot', 'performer', 'components']);

        if ($request->filled('lot_id')) {
            $query->forLot($request->string('lot_id')->toString());
        }

        if ($request->filled('status')) {
            $query->withStatus($request->string('status')->toString());
        }

        $paginator = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::paginated(
            $paginator->through(fn (BottlingRun $run) => (new BottlingRunResource($run))->resolve()),
        );
    }

    /**
     * Create a new bottling run.
     */
    public function store(StoreBottlingRunRequest $request): JsonResponse
    {
        $run = $this->bottlingService->createBottlingRun(
            data: $request->validated(),
            performedBy: $request->user()->id,
        );

        return (new BottlingRunResource($run))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a single bottling run with components.
     */
    public function show(BottlingRun $bottlingRun): BottlingRunResource
    {
        return new BottlingRunResource(
            $bottlingRun->load(['lot', 'performer', 'components']),
        );
    }

    /**
     * Complete a bottling run — deducts lot volume, generates SKU, writes event.
     */
    public function complete(BottlingRun $bottlingRun, Request $request): JsonResponse
    {
        $run = $this->bottlingService->completeBottlingRun(
            run: $bottlingRun,
            performedBy: $request->user()->id,
        );

        return (new BottlingRunResource($run))
            ->response()
            ->setStatusCode(200);
    }
}
