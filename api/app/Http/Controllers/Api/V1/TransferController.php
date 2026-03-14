<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Resources\TransferResource;
use App\Http\Responses\ApiResponse;
use App\Models\Transfer;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(
        protected TransferService $transferService,
    ) {}

    /**
     * List transfers with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Transfer::query()
            ->with(['lot', 'fromVessel', 'toVessel', 'performer']);

        if ($request->filled('lot_id')) {
            $query->forLot($request->input('lot_id'));
        }

        if ($request->filled('transfer_type')) {
            $query->ofType($request->input('transfer_type'));
        }

        if ($request->filled('vessel_id')) {
            $query->involvingVessel($request->input('vessel_id'));
        }

        if ($request->filled('performed_from') && $request->filled('performed_to')) {
            $query->performedBetween(
                $request->input('performed_from'),
                $request->input('performed_to'),
            );
        }

        $query->orderByDesc('performed_at');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (Transfer $transfer) => new TransferResource($transfer));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Execute a transfer.
     */
    public function store(StoreTransferRequest $request): JsonResponse
    {
        $transfer = $this->transferService->executeTransfer(
            data: $request->validated(),
            performedBy: $request->user()->id,
        );

        return ApiResponse::created(new TransferResource($transfer));
    }

    /**
     * Show transfer detail.
     */
    public function show(Transfer $transfer): JsonResponse
    {
        $transfer->load(['lot', 'fromVessel', 'toVessel', 'performer']);

        return ApiResponse::success(new TransferResource($transfer));
    }
}
