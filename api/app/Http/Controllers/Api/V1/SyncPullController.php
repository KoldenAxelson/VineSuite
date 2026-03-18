<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BarrelResource;
use App\Http\Resources\LotResource;
use App\Http\Resources\RawMaterialResource;
use App\Http\Resources\VesselResource;
use App\Http\Resources\WorkOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Barrel;
use App\Models\Lot;
use App\Models\RawMaterial;
use App\Models\Vessel;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unified sync pull endpoint for mobile apps.
 *
 * GET /api/v1/sync/pull?since={ISO8601}
 *
 * Returns all entities modified since the given timestamp in a single payload.
 * If `since` is omitted, returns all records (initial sync).
 *
 * Each entity type is capped at MAX_PER_ENTITY records. If any entity hits
 * the cap, `has_more` is true and the client should use the returned
 * `synced_at` as the next `since` value to continue paginating.
 *
 * The `synced_at` timestamp in the response becomes the client's next `since`
 * value. It is captured at the start of the request to ensure no records are
 * missed between pull requests.
 */
class SyncPullController extends Controller
{
    /**
     * Maximum records per entity type in a single pull.
     *
     * Prevents huge payloads on initial sync or after long offline periods.
     * Client paginates by re-pulling with the returned `synced_at`.
     */
    private const MAX_PER_ENTITY = 500;

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        // Capture sync timestamp at request start — no records modified after
        // this point will be missed on the next pull.
        $syncedAt = CarbonImmutable::now();

        $since = $request->filled('since')
            ? CarbonImmutable::parse($request->input('since'))
            : null;

        $lots = $this->pullEntity(Lot::query()->orderBy('updated_at'), $since);
        $vessels = $this->pullEntity(Vessel::query()->with('currentLot')->orderBy('updated_at'), $since);
        $workOrders = $this->pullEntity(WorkOrder::query()->orderBy('updated_at'), $since);
        $barrels = $this->pullEntity(Barrel::query()->orderBy('updated_at'), $since);
        $rawMaterials = $this->pullEntity(RawMaterial::query()->orderBy('updated_at'), $since);

        $hasMore = $lots->count() >= self::MAX_PER_ENTITY
            || $vessels->count() >= self::MAX_PER_ENTITY
            || $workOrders->count() >= self::MAX_PER_ENTITY
            || $barrels->count() >= self::MAX_PER_ENTITY
            || $rawMaterials->count() >= self::MAX_PER_ENTITY;

        return ApiResponse::success([
            'lots' => LotResource::collection($lots),
            'vessels' => VesselResource::collection($vessels),
            'work_orders' => WorkOrderResource::collection($workOrders),
            'barrels' => BarrelResource::collection($barrels),
            'raw_materials' => RawMaterialResource::collection($rawMaterials),
        ], meta: [
            'synced_at' => $syncedAt->toIso8601String(),
            'has_more' => $hasMore,
            'counts' => [
                'lots' => $lots->count(),
                'vessels' => $vessels->count(),
                'work_orders' => $workOrders->count(),
                'barrels' => $barrels->count(),
                'raw_materials' => $rawMaterials->count(),
            ],
        ]);
    }

    /**
     * Pull records modified since the given timestamp, capped at MAX_PER_ENTITY.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Collection<int, TModel>
     */
    private function pullEntity(Builder $query, ?CarbonImmutable $since)
    {
        if ($since !== null) {
            $query->where('updated_at', '>', $since);
        }

        /** @var Collection<int, TModel> */
        return $query->limit(self::MAX_PER_ENTITY)->get();
    }
}
