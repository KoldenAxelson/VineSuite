package com.vinesuite.shared.sync

import com.vinesuite.shared.models.SyncEvent
import kotlinx.datetime.Clock
import kotlinx.datetime.Instant
import kotlinx.serialization.json.JsonObject
import kotlin.uuid.ExperimentalUuidApi
import kotlin.uuid.Uuid

/**
 * Creates properly formatted SyncEvents with client-generated UUIDs
 * and ISO 8601 timestamps.
 *
 * Every event gets:
 * - A unique UUID as its idempotency key (safety net for offline sync)
 * - The current timestamp as performed_at (or a caller-supplied one)
 * - The device ID from sync configuration
 *
 * Usage:
 *   val factory = EventFactory(deviceId = "device-abc", userId = "user-123")
 *   val event = factory.create(
 *       entityType = "lot",
 *       entityId = lotId,
 *       operationType = "addition",
 *       payload = buildJsonObject { put("amount", 25.0) }
 *   )
 */
class EventFactory(
    private val deviceId: String?,
    private val userId: String,
    private val clock: Clock = Clock.System,
) {
    /**
     * Create a new SyncEvent with auto-generated idempotency key and timestamp.
     *
     * @param entityType The entity type (lot, vessel, barrel, etc.)
     * @param entityId The UUID of the entity being acted on
     * @param operationType The operation (addition, transfer, racking, etc.)
     * @param payload The operation-specific JSON payload
     * @param performedAt Override timestamp (defaults to now). Must be ISO 8601.
     */
    @OptIn(ExperimentalUuidApi::class)
    fun create(
        entityType: String,
        entityId: String,
        operationType: String,
        payload: JsonObject,
        performedAt: Instant = clock.now(),
    ): SyncEvent {
        return SyncEvent(
            entityType = entityType,
            entityId = entityId,
            operationType = operationType,
            payload = payload,
            performedBy = userId,
            performedAt = performedAt.toString(),
            deviceId = deviceId,
            idempotencyKey = Uuid.random().toString(),
        )
    }

    /**
     * Generate a unique event ID for the local OutboxEvent table.
     */
    @OptIn(ExperimentalUuidApi::class)
    fun generateEventId(): String = Uuid.random().toString()
}
