<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBarrelRequest;
use App\Http\Requests\UpdateBarrelRequest;
use App\Http\Resources\BarrelResource;
use App\Http\Responses\ApiResponse;
use App\Models\Barrel;
use App\Services\BarrelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BarrelController extends Controller
{
    public function __construct(
        protected BarrelService $barrelService,
    ) {}

    /**
     * List barrels with optional filters.
     *
     * Filters: cooperage, oak_type, toast_level, years_used, location, search
     */
    public function index(Request $request): JsonResponse
    {
        $query = Barrel::query()
            ->with(['vessel.currentLot'])
            ->join('vessels', 'barrels.vessel_id', '=', 'vessels.id')
            ->select('barrels.*')
            ->orderBy('vessels.name');

        if ($request->filled('cooperage')) {
            $query->fromCooperage($request->input('cooperage'));
        }

        if ($request->filled('oak_type')) {
            $query->ofOakType($request->input('oak_type'));
        }

        if ($request->filled('toast_level')) {
            $query->withToast($request->input('toast_level'));
        }

        if ($request->filled('years_used')) {
            $query->withYearsUsed((int) $request->input('years_used'));
        }

        if ($request->filled('location')) {
            $query->whereHas('vessel', function ($q) use ($request) {
                $q->where('location', 'ilike', '%'.$request->input('location').'%');
            });
        }

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(function ($q) use ($term) {
                $q->where('cooperage', 'ilike', "%{$term}%")
                    ->orWhere('qr_code', 'ilike', "%{$term}%")
                    ->orWhereHas('vessel', function ($vq) use ($term) {
                        $vq->where('name', 'ilike', "%{$term}%")
                            ->orWhere('location', 'ilike', "%{$term}%");
                    });
            });
        }

        // Filter by vessel status (active, retired, etc.)
        if ($request->filled('status')) {
            $query->whereHas('vessel', function ($q) use ($request) {
                $q->where('status', $request->input('status'));
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->paginate($perPage);

        // Transform items through BarrelResource so the list includes
        // vessel fields (name, location, status) alongside barrel metadata.
        $paginator->through(fn (Barrel $barrel) => new BarrelResource($barrel));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Create a new barrel (vessel + barrel metadata).
     */
    public function store(StoreBarrelRequest $request): JsonResponse
    {
        $barrel = $this->barrelService->createBarrel(
            data: $request->validated(),
            performedBy: $request->user()->id,
        );

        $barrel->load('vessel.currentLot');

        return ApiResponse::created(new BarrelResource($barrel));
    }

    /**
     * Get barrel detail with vessel info and current contents.
     */
    public function show(Barrel $barrel): JsonResponse
    {
        $barrel->load('vessel.currentLot');

        return ApiResponse::success(new BarrelResource($barrel));
    }

    /**
     * Update barrel metadata and/or vessel fields.
     */
    public function update(UpdateBarrelRequest $request, Barrel $barrel): JsonResponse
    {
        $barrel = $this->barrelService->updateBarrel(
            barrel: $barrel,
            data: $request->validated(),
            performedBy: $request->user()->id,
        );

        $barrel->load('vessel.currentLot');

        return ApiResponse::success(new BarrelResource($barrel));
    }
}
