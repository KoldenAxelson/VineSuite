<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLabThresholdRequest;
use App\Http\Requests\UpdateLabThresholdRequest;
use App\Http\Resources\LabThresholdResource;
use App\Http\Responses\ApiResponse;
use App\Models\LabThreshold;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabThresholdController extends Controller
{
    /**
     * List all lab thresholds with optional filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = LabThreshold::query();

        if ($request->filled('test_type')) {
            $query->forTestType($request->input('test_type'));
        }

        if ($request->filled('alert_level')) {
            $query->ofLevel($request->input('alert_level'));
        }

        $query->orderBy('test_type')->orderBy('alert_level');

        $paginator = $query->paginate($request->integer('per_page', 50));
        $paginator->through(fn (LabThreshold $threshold) => new LabThresholdResource($threshold));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Create a new threshold.
     */
    public function store(StoreLabThresholdRequest $request): JsonResponse
    {
        $threshold = LabThreshold::create($request->validated());

        return ApiResponse::created(new LabThresholdResource($threshold));
    }

    /**
     * Show a single threshold.
     */
    public function show(LabThreshold $threshold): JsonResponse
    {
        return ApiResponse::success(new LabThresholdResource($threshold));
    }

    /**
     * Update an existing threshold.
     */
    public function update(UpdateLabThresholdRequest $request, LabThreshold $threshold): JsonResponse
    {
        $threshold->update($request->validated());

        return ApiResponse::success(new LabThresholdResource($threshold->fresh()));
    }

    /**
     * Delete a threshold.
     */
    public function destroy(LabThreshold $threshold): JsonResponse
    {
        $threshold->delete();

        return ApiResponse::message('Threshold deleted.');
    }
}
