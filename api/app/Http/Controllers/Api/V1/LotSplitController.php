<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLotSplitRequest;
use App\Http\Resources\LotResource;
use App\Http\Responses\ApiResponse;
use App\Models\Lot;
use App\Services\LotSplitService;
use Illuminate\Http\JsonResponse;

class LotSplitController extends Controller
{
    public function __construct(
        protected LotSplitService $lotSplitService,
    ) {}

    /**
     * Split a lot into multiple child lots.
     */
    public function store(StoreLotSplitRequest $request): JsonResponse
    {
        $parentLot = Lot::findOrFail($request->validated('lot_id'));

        $result = $this->lotSplitService->splitLot(
            parentLot: $parentLot,
            children: $request->validated('children'),
            performedBy: $request->user()->id,
        );

        return ApiResponse::success([
            'parent' => (new LotResource($result['parent']))->resolve(),
            'children' => collect($result['children'])->map(
                fn (Lot $lot) => (new LotResource($lot))->resolve()
            )->all(),
        ], 201);
    }
}
