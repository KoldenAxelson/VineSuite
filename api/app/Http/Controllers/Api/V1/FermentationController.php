<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFermentationEntryRequest;
use App\Http\Requests\StoreFermentationRoundRequest;
use App\Http\Resources\FermentationEntryResource;
use App\Http\Resources\FermentationRoundResource;
use App\Http\Responses\ApiResponse;
use App\Models\FermentationRound;
use App\Services\FermentationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FermentationController extends Controller
{
    public function __construct(
        protected FermentationService $fermentationService,
    ) {}

    /**
     * List fermentation rounds for a lot.
     */
    public function index(Request $request, string $lotId): JsonResponse
    {
        $query = FermentationRound::query()
            ->with(['lot'])
            ->withCount('entries')
            ->where('lot_id', $lotId);

        if ($request->filled('fermentation_type')) {
            $query->ofType($request->input('fermentation_type'));
        }

        if ($request->filled('status')) {
            $query->withStatus($request->input('status'));
        }

        $query->orderBy('round_number')->orderByDesc('inoculation_date');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (FermentationRound $round) => new FermentationRoundResource($round));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Create a new fermentation round for a lot.
     */
    public function store(StoreFermentationRoundRequest $request, string $lotId): JsonResponse
    {
        $data = $request->validated();
        $data['lot_id'] = $lotId;

        $round = $this->fermentationService->createRound(
            data: $data,
            createdBy: $request->user()->id,
        );

        $round->load('lot');
        $round->loadCount('entries');

        return ApiResponse::created(new FermentationRoundResource($round));
    }

    /**
     * Show a single fermentation round with entries.
     */
    public function show(string $lotId, FermentationRound $fermentationRound): JsonResponse
    {
        $fermentationRound->load(['lot', 'entries.performer']);
        $fermentationRound->loadCount('entries');

        return ApiResponse::success(new FermentationRoundResource($fermentationRound));
    }

    /**
     * Add a daily entry to a fermentation round.
     */
    public function addEntry(StoreFermentationEntryRequest $request, string $roundId): JsonResponse
    {
        $data = $request->validated();
        $data['fermentation_round_id'] = $roundId;

        $entry = $this->fermentationService->addEntry(
            data: $data,
            performedBy: $request->user()->id,
        );

        $entry->load('performer');

        return ApiResponse::created(new FermentationEntryResource($entry));
    }

    /**
     * List entries for a fermentation round.
     */
    public function entries(Request $request, string $roundId): JsonResponse
    {
        $query = \App\Models\FermentationEntry::query()
            ->with('performer')
            ->where('fermentation_round_id', $roundId);

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->recordedBetween(
                $request->input('date_from'),
                $request->input('date_to'),
            );
        }

        $query->orderBy('entry_date')->orderBy('created_at');

        $paginator = $query->paginate($request->integer('per_page', 50));
        $paginator->through(fn (\App\Models\FermentationEntry $entry) => new FermentationEntryResource($entry));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Mark a fermentation round as completed.
     */
    public function complete(Request $request, string $roundId): JsonResponse
    {
        $round = FermentationRound::findOrFail($roundId);

        $request->validate([
            'completion_date' => ['nullable', 'date'],
        ]);

        $round = $this->fermentationService->completeRound(
            round: $round,
            completedBy: $request->user()->id,
            completionDate: $request->input('completion_date'),
        );

        $round->load('lot');
        $round->loadCount('entries');

        return ApiResponse::success(new FermentationRoundResource($round));
    }

    /**
     * Mark a fermentation round as stuck.
     */
    public function markStuck(Request $request, string $roundId): JsonResponse
    {
        $round = FermentationRound::findOrFail($roundId);

        $round = $this->fermentationService->markStuck(
            round: $round,
            reportedBy: $request->user()->id,
        );

        $round->load('lot');
        $round->loadCount('entries');

        return ApiResponse::success(new FermentationRoundResource($round));
    }
}
