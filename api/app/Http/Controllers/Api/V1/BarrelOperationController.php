<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BarrelFillRequest;
use App\Http\Requests\BarrelRackRequest;
use App\Http\Requests\BarrelSampleRequest;
use App\Http\Requests\BarrelTopRequest;
use App\Http\Responses\ApiResponse;
use App\Services\BarrelOperationService;
use Illuminate\Http\JsonResponse;

class BarrelOperationController extends Controller
{
    public function __construct(
        protected BarrelOperationService $barrelOperationService,
    ) {}

    /**
     * Fill barrels from a lot.
     */
    public function fill(BarrelFillRequest $request): JsonResponse
    {
        $results = $this->barrelOperationService->fillBarrels(
            lotId: $request->validated('lot_id'),
            barrels: $request->validated('barrels'),
            performedBy: $request->user()->id,
        );

        return ApiResponse::success([
            'operation' => 'fill',
            'lot_id' => $request->validated('lot_id'),
            'barrels_filled' => count($results),
            'results' => $results,
        ], 201);
    }

    /**
     * Top barrels from a source vessel.
     */
    public function top(BarrelTopRequest $request): JsonResponse
    {
        $results = $this->barrelOperationService->topBarrels(
            sourceVesselId: $request->validated('source_vessel_id'),
            lotId: $request->validated('lot_id'),
            barrels: $request->validated('barrels'),
            performedBy: $request->user()->id,
        );

        return ApiResponse::success([
            'operation' => 'top',
            'lot_id' => $request->validated('lot_id'),
            'source_vessel_id' => $request->validated('source_vessel_id'),
            'barrels_topped' => count($results),
            'results' => $results,
        ], 201);
    }

    /**
     * Rack barrels to a target vessel.
     */
    public function rack(BarrelRackRequest $request): JsonResponse
    {
        $results = $this->barrelOperationService->rackBarrels(
            targetVesselId: $request->validated('target_vessel_id'),
            lotId: $request->validated('lot_id'),
            barrels: $request->validated('barrels'),
            performedBy: $request->user()->id,
        );

        return ApiResponse::success([
            'operation' => 'rack',
            'lot_id' => $request->validated('lot_id'),
            'target_vessel_id' => $request->validated('target_vessel_id'),
            'barrels_racked' => count($results),
            'results' => $results,
        ], 201);
    }

    /**
     * Record a barrel sample extraction.
     */
    public function sample(BarrelSampleRequest $request): JsonResponse
    {
        $result = $this->barrelOperationService->recordSample(
            barrelId: $request->validated('barrel_id'),
            lotId: $request->validated('lot_id'),
            volumeMl: (float) $request->validated('volume_ml'),
            performedBy: $request->user()->id,
            notes: $request->validated('notes'),
        );

        return ApiResponse::success([
            'operation' => 'sample',
            'lot_id' => $request->validated('lot_id'),
            'result' => $result,
        ], 201);
    }
}
