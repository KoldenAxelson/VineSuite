<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSensoryNoteRequest;
use App\Http\Resources\SensoryNoteResource;
use App\Http\Responses\ApiResponse;
use App\Models\SensoryNote;
use App\Services\SensoryNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SensoryNoteController extends Controller
{
    public function __construct(
        protected SensoryNoteService $sensoryNoteService,
    ) {}

    /**
     * List sensory notes for a lot.
     */
    public function index(Request $request, string $lotId): JsonResponse
    {
        $query = SensoryNote::query()
            ->with(['taster'])
            ->where('lot_id', $lotId);

        if ($request->filled('taster_id')) {
            $query->byTaster($request->input('taster_id'));
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->recordedBetween(
                $request->input('date_from'),
                $request->input('date_to'),
            );
        }

        $query->orderByDesc('date')->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('per_page', 25));
        $paginator->through(fn (SensoryNote $note) => new SensoryNoteResource($note));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Create a sensory note for a lot.
     */
    public function store(StoreSensoryNoteRequest $request, string $lotId): JsonResponse
    {
        $data = $request->validated();
        $data['lot_id'] = $lotId;

        $note = $this->sensoryNoteService->createNote(
            data: $data,
            tasterId: $request->user()->id,
        );

        $note->load(['lot', 'taster']);

        return ApiResponse::created(new SensoryNoteResource($note));
    }

    /**
     * Show a single sensory note.
     */
    public function show(string $lotId, SensoryNote $sensoryNote): JsonResponse
    {
        $sensoryNote->load(['lot', 'taster']);

        return ApiResponse::success(new SensoryNoteResource($sensoryNote));
    }
}
