<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AdditionServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdditionRequest;
use App\Http\Resources\AdditionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Addition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdditionController extends Controller
{
    public function __construct(
        protected AdditionServiceInterface $additionService,
    ) {}

    /**
     * List additions with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Addition::query()
            ->with(['lot', 'vessel', 'performer']);

        // Filters
        if ($request->filled('lot_id')) {
            $query->forLot($request->input('lot_id'));
        }

        if ($request->filled('addition_type')) {
            $query->ofType($request->input('addition_type'));
        }

        if ($request->filled('product_name')) {
            $query->forProduct($request->input('product_name'));
        }

        if ($request->filled('vessel_id')) {
            $query->where('vessel_id', $request->input('vessel_id'));
        }

        if ($request->filled('performed_from') && $request->filled('performed_to')) {
            $query->performedBetween(
                $request->input('performed_from'),
                $request->input('performed_to'),
            );
        }

        $query->orderByDesc('performed_at');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (Addition $addition) => new AdditionResource($addition));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Log a new addition.
     */
    public function store(StoreAdditionRequest $request): JsonResponse
    {
        $addition = $this->additionService->createAddition(
            data: $request->validated(),
            performedBy: $request->user()->id,
        );

        return ApiResponse::created(new AdditionResource($addition));
    }

    /**
     * Show addition detail.
     */
    public function show(Addition $addition): JsonResponse
    {
        $addition->load(['lot', 'vessel', 'performer']);

        return ApiResponse::success(new AdditionResource($addition));
    }

    /**
     * Get the SO2 running total for a lot.
     */
    public function so2Total(Request $request): JsonResponse
    {
        $request->validate([
            'lot_id' => ['required', 'uuid', 'exists:lots,id'],
        ]);

        $total = $this->additionService->getSo2RunningTotal(
            $request->input('lot_id'),
        );

        return ApiResponse::success([
            'lot_id' => $request->input('lot_id'),
            'so2_total_ppm' => $total,
        ]);
    }
}
