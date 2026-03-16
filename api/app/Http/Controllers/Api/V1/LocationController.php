<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Location;
use App\Services\EventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LocationController extends Controller
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * List locations with optional active filter.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Location::query()->with(['stockLevels.sku']);

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('name');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (Location $location) => new LocationResource($location));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Show a single location with stock levels.
     */
    public function show(Location $location): JsonResponse
    {
        $location->load(['stockLevels.sku']);

        return ApiResponse::success(new LocationResource($location));
    }

    /**
     * Create a new location.
     */
    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = Location::create($request->validated());

        $this->eventLogger->log(
            entityType: 'location',
            entityId: $location->id,
            operationType: 'stock_location_created',
            payload: [
                'name' => $location->name,
                'address' => $location->address,
            ],
            performedBy: $request->user()->id,
            performedAt: now(),
        );

        Log::info('Location created', [
            'location_id' => $location->id,
            'name' => $location->name,
            'tenant_id' => tenant('id'),
            'user_id' => $request->user()->id,
        ]);

        return ApiResponse::created(new LocationResource($location));
    }

    /**
     * Update an existing location.
     */
    public function update(UpdateLocationRequest $request, Location $location): JsonResponse
    {
        $location->update($request->validated());

        $this->eventLogger->log(
            entityType: 'location',
            entityId: $location->id,
            operationType: 'stock_location_updated',
            payload: [
                'name' => $location->name,
                'address' => $location->address,
                'is_active' => $location->is_active,
            ],
            performedBy: $request->user()->id,
            performedAt: now(),
        );

        Log::info('Location updated', [
            'location_id' => $location->id,
            'name' => $location->name,
            'tenant_id' => tenant('id'),
            'user_id' => $request->user()->id,
        ]);

        return ApiResponse::success(new LocationResource($location));
    }
}
