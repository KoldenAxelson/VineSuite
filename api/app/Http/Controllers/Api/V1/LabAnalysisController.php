<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLabAnalysisRequest;
use App\Http\Resources\LabAnalysisResource;
use App\Http\Responses\ApiResponse;
use App\Models\LabAnalysis;
use App\Services\LabAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabAnalysisController extends Controller
{
    public function __construct(
        protected LabAnalysisService $labAnalysisService,
    ) {}

    /**
     * List lab analyses for a specific lot with filtering and pagination.
     */
    public function index(Request $request, string $lotId): JsonResponse
    {
        $query = LabAnalysis::query()
            ->with(['lot', 'performer'])
            ->where('lot_id', $lotId);

        // Filters
        if ($request->filled('test_type')) {
            $query->ofType($request->input('test_type'));
        }

        if ($request->filled('source')) {
            $query->fromSource($request->input('source'));
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->testedBetween(
                $request->input('date_from'),
                $request->input('date_to'),
            );
        }

        $query->orderByDesc('test_date')->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (LabAnalysis $analysis) => new LabAnalysisResource($analysis));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Record a new lab analysis entry.
     *
     * The lot_id is taken from the route parameter, not the request body,
     * to prevent mismatched route/body IDs.
     */
    public function store(StoreLabAnalysisRequest $request, string $lotId): JsonResponse
    {
        $data = $request->validated();
        $data['lot_id'] = $lotId;

        $analysis = $this->labAnalysisService->createAnalysis(
            data: $data,
            performedBy: $request->user()->id,
        );

        return ApiResponse::created(new LabAnalysisResource($analysis));
    }

    /**
     * Show a single lab analysis with relationships.
     */
    public function show(string $lotId, LabAnalysis $analysis): JsonResponse
    {
        $analysis->load(['lot', 'performer']);

        return ApiResponse::success(new LabAnalysisResource($analysis));
    }
}
