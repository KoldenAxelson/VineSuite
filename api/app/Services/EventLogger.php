<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Support\LogContext;
use Illuminate\Database\Eloquent\Collection;
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
                Log::debug('EventLogger: duplicate idempotency key, returning existing event', LogContext::with([
                    'idempotency_key' => $idempotencyKey,
                    'event_id' => $existing->id,
                ]));

                return $existing;
            }
        }

        $event = Event::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation_type' => $operationType,
            'event_source' => $this->resolveSource($operationType),
            'payload' => $payload,
            'performed_by' => $performedBy,
            'performed_at' => $performedAt ?? now(),
            'synced_at' => $isSynced ? now() : null,
            'device_id' => $deviceId,
            'idempotency_key' => $idempotencyKey,
        ]);

        Log::info('EventLogger: event created', LogContext::with([
            'event_id' => $event->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation_type' => $operationType,
        ], $performedBy));

        return $event;
    }

    /**
     * Derive the event_source from the operation type prefix.
     *
     * Callers never set event_source directly — it's resolved automatically
     * from the operation type naming convention. This keeps the API surface
     * unchanged and prevents inconsistency.
     *
     * Prefix-to-source mappings are defined in config/event-sources.php.
     * New modules add their prefixes to that config file instead of modifying
     * this method — keeping EventLogger closed for modification.
     *
     * @see config/event-sources.php
     * @see docs/references/event-source-partitioning.md
     */
    private function resolveSource(string $operationType): string
    {
        /** @var array<string, array<int, string>> $sourceMap */
        $sourceMap = config('event-sources', []);

        foreach ($sourceMap as $source => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($operationType, $prefix)) {
                    return $source;
                }
            }
        }

        return 'production';
    }

    /**
     * Retrieve the full event stream for an entity, ordered by performed_at.
     *
     * @param  string  $entityType  Entity type
     * @param  string  $entityId  Entity UUID
     * @return Collection<int, Event>
     */
    public function getEntityStream(string $entityType, string $entityId): Collection
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
     * @return Collection<int, Event>
     */
    public function getByOperationType(string $operationType, \DateTimeInterface|string $from, \DateTimeInterface|string $to): Collection
    {
        return Event::ofType($operationType)
            ->performedBetween($from, $to)
            ->orderBy('performed_at')
            ->get();
    }
}
