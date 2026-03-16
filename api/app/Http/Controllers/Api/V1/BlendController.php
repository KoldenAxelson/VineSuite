<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\BlendServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBlendTrialRequest;
use App\Http\Resources\BlendTrialResource;
use App\Http\Responses\ApiResponse;
use App\Models\BlendTrial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlendController extends Controller
{
    public function __construct(
        protected BlendServiceInterface $blendService,
    ) {}

    /**
     * List blend trials with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BlendTrial::query()
            ->with(['components.sourceLot', 'creator']);

        if ($request->filled('status')) {
            $query->withStatus($request->string('status')->toString());
        }

        $paginator = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::paginated(
            $paginator->through(fn (BlendTrial $trial) => (new BlendTrialResource($trial))->resolve()),
        );
    }

    /**
     * Create a new blend trial.
     */
    public function store(StoreBlendTrialRequest $request): JsonResponse
    {
        $trial = $this->blendService->createTrial(
            data: $request->validated(),
            createdBy: $request->user()->id,
        );

        return (new BlendTrialResource($trial))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a single blend trial with components.
     */
    public function show(BlendTrial $blendTrial): BlendTrialResource
    {
        return new BlendTrialResource(
            $blendTrial->load(['components.sourceLot', 'creator', 'resultingLot']),
        );
    }

    /**
     * Finalize a draft blend trial — creates the blended lot and deducts volumes.
     */
    public function finalize(BlendTrial $blendTrial, Request $request): JsonResponse
    {
        $trial = $this->blendService->finalizeTrial(
            trial: $blendTrial,
            performedBy: $request->user()->id,
        );

        return (new BlendTrialResource($trial))
            ->response()
            ->setStatusCode(200);
    }
}
