<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EventProcessor — processes a batch of events from mobile sync.
 *
 * Handles each event individually within its own transaction.
 * One bad event doesn't reject the entire batch.
 * Duplicate idempotency keys are skipped (not an error).
 *
 * Future: will dispatch event-specific handlers to update materialized state tables.
 */
class EventProcessor
{
    public function __construct(
        protected EventLogger $eventLogger,
    ) {}

    /**
     * Process a batch of events from mobile sync.
     *
     * Returns a results array with status per event:
     * - 'accepted' — event was created successfully
     * - 'skipped' — duplicate idempotency_key, existing event returned
     * - 'failed' — error processing event
     *
     * @param  array  $events  Array of event data from EventSyncRequest
     * @param  string  $userId  UUID of the authenticated user
     * @return array{results: array, accepted: int, skipped: int, failed: int}
     */
    public function processBatch(array $events, string $userId): array
    {
        $results = [];
        $accepted = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($events as $index => $eventData) {
            try {
                $result = $this->processEvent($eventData, $userId);

                $results[] = [
                    'index' => $index,
                    'event_id' => $result['event']->id,
                    'status' => $result['status'],
                    'idempotency_key' => $eventData['idempotency_key'],
                ];

                if ($result['status'] === 'accepted') {
                    $accepted++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;

                Log::error('EventProcessor: failed to process event', [
                    'index' => $index,
                    'idempotency_key' => $eventData['idempotency_key'] ?? null,
                    'error' => $e->getMessage(),
                    'tenant_id' => tenant('id'),
                ]);

                $results[] = [
                    'index' => $index,
                    'event_id' => null,
                    'status' => 'failed',
                    'idempotency_key' => $eventData['idempotency_key'] ?? null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::info('EventProcessor: batch processed', [
            'total' => count($events),
            'accepted' => $accepted,
            'skipped' => $skipped,
            'failed' => $failed,
            'user_id' => $userId,
            'tenant_id' => tenant('id'),
        ]);

        return [
            'results' => $results,
            'accepted' => $accepted,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * Process a single event within its own DB transaction.
     *
     * @return array{event: Event, status: string}
     */
    protected function processEvent(array $eventData, string $userId): array
    {
        return DB::transaction(function () use ($eventData, $userId) {
            // Check if this idempotency key already exists
            $existing = Event::where('idempotency_key', $eventData['idempotency_key'])->first();

            if ($existing) {
                return ['event' => $existing, 'status' => 'skipped'];
            }

            $event = $this->eventLogger->log(
                entityType: $eventData['entity_type'],
                entityId: $eventData['entity_id'],
                operationType: $eventData['operation_type'],
                payload: $eventData['payload'],
                performedBy: $userId,
                performedAt: $eventData['performed_at'],
                deviceId: $eventData['device_id'] ?? null,
                idempotencyKey: $eventData['idempotency_key'],
                isSynced: true,
            );

            // Future: dispatch to event-specific handlers here
            // e.g., $this->dispatchHandler($event);

            return ['event' => $event, 'status' => 'accepted'];
        });
    }
}
