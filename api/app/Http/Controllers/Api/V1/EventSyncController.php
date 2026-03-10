<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventSyncRequest;
use App\Services\EventProcessor;
use Illuminate\Http\JsonResponse;

/**
 * Batch event sync endpoint for mobile apps.
 *
 * POST /api/v1/events/sync
 *
 * Accepts an array of events, validates each, writes to the event log,
 * and returns sync confirmation with per-event status.
 *
 * Requires authenticated Sanctum token with cellar_app or pos_app abilities.
 */
class EventSyncController extends Controller
{
    public function __construct(
        protected EventProcessor $eventProcessor,
    ) {}

    public function __invoke(EventSyncRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $events = $request->validated('events');

        $result = $this->eventProcessor->processBatch($events, $userId);

        return response()->json([
            'message' => 'Sync complete.',
            'accepted' => $result['accepted'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'results' => $result['results'],
        ]);
    }
}
