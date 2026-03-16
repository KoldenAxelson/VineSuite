<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\LotServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLotRequest;
use App\Http\Requests\UpdateLotRequest;
use App\Http\Resources\LotResource;
use App\Http\Responses\ApiResponse;
use App\Models\Lot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LotController extends Controller
{
    public function __construct(
        protected LotServiceInterface $lotService,
    ) {}

    /**
     * List lots with optional filters.
     *
     * Filters: variety, vintage, status, search (name/variety/vintage)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Lot::query()->orderBy('created_at', 'desc');

        if ($request->filled('variety')) {
            $query->ofVariety($request->input('variety'));
        }

        if ($request->filled('vintage')) {
            $query->ofVintage((int) $request->input('vintage'));
        }

        if ($request->filled('status')) {
            $query->withStatus($request->input('status'));
        }

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->paginate($perPage);

        return ApiResponse::paginated($paginator);
    }

    /**
     * Create a new lot.
     */
    public function store(StoreLotRequest $request): JsonResponse
    {
        $lot = $this->lotService->createLot(
            data: $request->validated(),
            performedBy: $request->user()->id,
        );

        return ApiResponse::created(new LotResource($lot));
    }

    /**
     * Get lot detail with event timeline.
     */
    public function show(Lot $lot): JsonResponse
    {
        $lot->load('childLots', 'parentLot');

        return ApiResponse::success(new LotResource($lot));
    }

    /**
     * Update lot (status, name, source_details).
     */
    public function update(UpdateLotRequest $request, Lot $lot): JsonResponse
    {
        $lot = $this->lotService->updateLot(
            lot: $lot,
            data: $request->validated(),
            performedBy: $request->user()->id,
        );

        return ApiResponse::success(new LotResource($lot));
    }
}
