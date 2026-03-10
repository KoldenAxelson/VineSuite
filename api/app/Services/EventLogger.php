<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\Log;

/**
 * EventLogger — the single entry point for writing events to the event log.
 *
 * All modules write events through this service. It handles:
 * - Creating immutable event records
 * - Idempotency key deduplication (duplicate submissions return existing event)
 * - Setting synced_at for server-received events
 * - Structured logging with tenant_id
 *
 * Usage:
 *   $logger = app(EventLogger::class);
 *   $event = $logger->log(
 *       entityType: 'lot',
 *       entityId: $lot->id,
 *       operationType: 'addition',
 *       payload: ['volume_gallons' => 500, 'grape_variety' => 'Cabernet Sauvignon'],
 *       performedBy: auth()->id(),
 *       performedAt: now(),
 *   );
 */
class EventLogger
{
    /**
     * Log an event to the append-only event log.
     *
     * If an event with the same idempotency_key already exists, the existing event
     * is returned instead of creating a duplicate. This is critical for offline sync safety.
     *
     * @param  string  $entityType  Entity type (lot, vessel, barrel, inventory_item, order)
     * @param  string  $entityId  UUID of the entity
     * @param  string  $operationType  Operation type (addition, transfer, rack, bottle, blend, sale, etc.)
     * @param  array<string, mixed>  $payload  Operation-specific data
     * @param  string|null  $performedBy  UUID of the user (null for system events)
     * @param  \DateTimeInterface|string  $performedAt  Client timestamp
     * @param  string|null  $deviceId  Device identifier for conflict detection
     * @param  string|null  $idempotencyKey  Unique key to prevent duplicate event writes
     * @param  bool  $isSynced  Whether this event came from a mobile sync (sets synced_at)
     * @return Event The created or existing event
     */
    public function log(
        string $entityType,
        string $entityId,
        string $operationType,
        array $payload,
        ?string $performedBy = null,
        \DateTimeInterface|string|null $performedAt = null,
        ?string $deviceId = null,
        ?string $idempotencyKey = null,
        bool $isSynced = false,
    ): Event {
        // Check for existing event with same idempotency key
        if ($idempotencyKey !== null) {
            $existing = Event::where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                Log::debug('EventLogger: duplicate idempotency key, returning existing event', [
                    'idempotency_key' => $idempotencyKey,
                    'event_id' => $existing->id,
                    'tenant_id' => tenant('id'),
                ]);

                return $existing;
            }
        }

        $event = Event::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation_type' => $operationType,
            'payload' => $payload,
            'performed_by' => $performedBy,
            'performed_at' => $performedAt ?? now(),
            'synced_at' => $isSynced ? now() : null,
            'device_id' => $deviceId,
            'idempotency_key' => $idempotencyKey,
        ]);

        Log::info('EventLogger: event created', [
            'event_id' => $event->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation_type' => $operationType,
            'performed_by' => $performedBy,
            'tenant_id' => tenant('id'),
        ]);

        return $event;
    }

    /**
     * Retrieve the full event stream for an entity, ordered by performed_at.
     *
     * @param  string  $entityType  Entity type
     * @param  string  $entityId  Entity UUID
     * @return \Illuminate\Database\Eloquent\Collection<int, Event>
     */
    public function getEntityStream(string $entityType, string $entityId): \Illuminate\Database\Eloquent\Collection
    {
        return Event::forEntity($entityType, $entityId)
            ->orderBy('performed_at')
            ->get();
    }

    /**
     * Retrieve events by operation type within a time range.
     * Useful for TTB reporting aggregations.
     *
     * @param  string  $operationType  Operation type to filter
     * @param  \DateTimeInterface|string  $from  Start of range
     * @param  \DateTimeInterface|string  $to  End of range
     * @return \Illuminate\Database\Eloquent\Collection<int, Event>
     */
    public function getByOperationType(string $operationType, \DateTimeInterface|string $from, \DateTimeInterface|string $to): \Illuminate\Database\Eloquent\Collection
    {
        return Event::ofType($operationType)
            ->performedBetween($from, $to)
            ->orderBy('performed_at')
            ->get();
    }
}
