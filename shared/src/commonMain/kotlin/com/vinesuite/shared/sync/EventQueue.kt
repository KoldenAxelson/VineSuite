package com.vinesuite.shared.sync

import com.vinesuite.shared.database.OutboxEvent
import com.vinesuite.shared.database.VineSuiteDatabase
import com.vinesuite.shared.models.SyncEvent
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject

/**
 * Local event outbox — the write-side of the offline sync pattern.
 *
 * Every user operation writes an event here via [enqueue]. Events wait
 * in the local SQLite OutboxEvent table until the SyncEngine pushes
 * them to the server.
 *
 * Lifecycle:
 * 1. User performs an action → enqueue(event)
 * 2. SyncEngine calls getPendingEvents() → POST to server
 * 3. Server confirms → markSynced(eventIds)
 * 4. If server rejects → incrementRetry(eventId, error)
 * 5. After 5 retries → event flagged as permanently failed
 * 6. Periodic cleanup: purgeSyncedEvents(olderThan)
 */
class EventQueue(
    private val database: VineSuiteDatabase,
    private val eventFactory: EventFactory,
) {
    companion object {
        /** Events that fail this many times stop being retried automatically. */
        const val MAX_RETRY_COUNT = 5

        /** Default batch size for sync pushes. Server accepts max 100. */
        const val DEFAULT_BATCH_SIZE = 50L
    }

    /**
     * Enqueue a new event into the local outbox.
     *
     * The event is written to SQLite immediately. It will be synced
     * to the server the next time the SyncEngine runs.
     *
     * @return The local outbox event ID.
     */
    fun enqueue(event: SyncEvent): String {
        val eventId = eventFactory.generateEventId()
        val payloadJson = Json.encodeToString(JsonObject.serializer(), event.payload)

        database.outboxEventQueries.insert(
            id = eventId,
            entity_type = event.entityType,
            entity_id = event.entityId,
            operation_type = event.operationType,
            payload = payloadJson,
            performed_by = event.performedBy,
            performed_at = event.performedAt,
            device_id = event.deviceId,
            idempotency_key = event.idempotencyKey,
            created_at = event.performedAt,
        )

        return eventId
    }

    /**
     * Get all unsynced events, ordered by performed_at (FIFO).
     * Used by the SyncEngine for the push phase.
     */
    fun getPendingEvents(): List<OutboxEvent> {
        return database.outboxEventQueries.selectUnsynced().executeAsList()
    }

    /**
     * Get a batch of unsynced events for controlled push sizes.
     * Default batch matches half the server's max (100).
     */
    fun getPendingBatch(limit: Long = DEFAULT_BATCH_SIZE): List<OutboxEvent> {
        return database.outboxEventQueries.selectUnsyncedBatch(limit).executeAsList()
    }

    /**
     * Mark a single event as synced after server confirmation.
     */
    fun markSynced(eventId: String) {
        database.outboxEventQueries.markSynced(eventId)
    }

    /**
     * Mark multiple events as synced in one operation.
     * Used after a successful batch push.
     */
    fun markSyncedBatch(eventIds: List<String>) {
        database.outboxEventQueries.transaction {
            eventIds.forEach { id ->
                database.outboxEventQueries.markSynced(id)
            }
        }
    }

    /**
     * Record a sync failure for an event.
     * Increments retry_count and stores the error message.
     */
    fun recordFailure(eventId: String, error: String) {
        database.outboxEventQueries.incrementRetry(last_error = error, id = eventId)
    }

    /**
     * Reset retry state for an event (e.g., after manual user intervention).
     */
    fun resetRetry(eventId: String) {
        database.outboxEventQueries.resetRetry(eventId)
    }

    /**
     * Reset all retryable failed events so they'll be picked up by the
     * next sync cycle. Returns the number of events reset.
     *
     * Only resets events under MAX_RETRY_COUNT — permanently failed
     * events (5+ retries) are excluded. Use resetRetry(id) for those.
     */
    fun retryFailed(): Int {
        val retryable = getRetryable()
        database.outboxEventQueries.transaction {
            retryable.forEach { event ->
                database.outboxEventQueries.resetRetry(event.id)
            }
        }
        return retryable.size
    }

    /**
     * Get events that have exceeded the max retry count.
     * These need manual user review — not retried automatically.
     */
    fun getPermanentlyFailed(): List<OutboxEvent> {
        return database.outboxEventQueries.selectPermanentlyFailed().executeAsList()
    }

    /**
     * Get events that have failed at least once but haven't hit the max.
     * These will be retried by the SyncEngine.
     */
    fun getRetryable(): List<OutboxEvent> {
        return database.outboxEventQueries.selectFailed().executeAsList()
            .filter { it.retry_count < MAX_RETRY_COUNT }
    }

    /**
     * Count of events waiting to be synced.
     * Used by the UI to show sync status indicator.
     */
    fun pendingCount(): Long {
        return database.outboxEventQueries.countUnsynced().executeAsOne()
    }

    /**
     * Purge old synced events to reclaim storage.
     * Only deletes events that have been successfully synced.
     *
     * @param olderThan ISO 8601 timestamp cutoff
     */
    fun purgeSyncedEvents(olderThan: String) {
        database.outboxEventQueries.deleteOlderThan(olderThan)
    }

    /**
     * Convert an OutboxEvent row back to a SyncEvent for API submission.
     */
    fun toSyncEvent(outboxEvent: OutboxEvent): SyncEvent {
        return SyncEvent(
            entityType = outboxEvent.entity_type,
            entityId = outboxEvent.entity_id,
            operationType = outboxEvent.operation_type,
            payload = Json.decodeFromString(JsonObject.serializer(), outboxEvent.payload),
            performedBy = outboxEvent.performed_by,
            performedAt = outboxEvent.performed_at,
            deviceId = outboxEvent.device_id,
            idempotencyKey = outboxEvent.idempotency_key,
        )
    }
}
