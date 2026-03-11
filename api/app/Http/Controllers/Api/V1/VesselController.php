<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVesselRequest;
use App\Http\Requests\UpdateVesselRequest;
use App\Http\Resources\VesselResource;
use App\Http\Responses\ApiResponse;
use App\Models\Vessel;
use App\Services\VesselService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VesselController extends Controller
{
    public function __construct(
        protected VesselService $vesselService,
    ) {}

    /**
     * List vessels with optional filters.
     *
     * Filters: type, status, location, search (name/location/material)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Vessel::query()
            ->with('currentLot')
            ->orderBy('name');

        if ($request->filled('type')) {
            $query->ofType($request->input('type'));
        }

        if ($request->filled('status')) {
            $query->withStatus($request->input('status'));
        }

        if ($request->filled('location')) {
            $query->atLocation($request->input('location'));
        }

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->paginate($perPage);

        return ApiResponse::paginated($paginator);
    }

    /**
     * Create a new vessel.
     */
    public function store(StoreVesselRequest $request): JsonResponse
    {
        $vessel = $this->vesselService->createVessel(
            data: $request->validated(),
            performedBy: $request->user()->id,
        );

        $vessel->load('currentLot');

        return ApiResponse::created(new VesselResource($vessel));
    }

    /**
     * Get vessel detail with current contents.
     */
    public function show(Vessel $vessel): JsonResponse
    {
        $vessel->load(['currentLot', 'barrel']);

        return ApiResponse::success(new VesselResource($vessel));
    }

    /**
     * Update vessel (status, name, location, notes).
     */
    public function update(UpdateVesselRequest $request, Vessel $vessel): JsonResponse
    {
        $vessel = $this->vesselService->updateVessel(
            vessel: $vessel,
            data: $request->validated(),
            performedBy: $request->user()->id,
        );

        $vessel->load('currentLot');

        return ApiResponse::success(new VesselResource($vessel));
    }
}
