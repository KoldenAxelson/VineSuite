package com.vinesuite.shared.models

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject

/**
 * Event data class matching the server's EventSyncRequest schema.
 *
 * This is what gets serialized and POSTed to `POST /api/v1/events/sync`.
 * The server validates:
 * - entity_type, entity_id (UUID), operation_type — required strings
 * - payload — required JSON object
 * - performed_at — ISO 8601, within last 30 days, not in the future
 * - idempotency_key — required, unique per event (client-generated UUID)
 * - device_id — optional
 */
@Serializable
data class SyncEvent(
    @SerialName("entity_type")
    val entityType: String,

    @SerialName("entity_id")
    val entityId: String,

    @SerialName("operation_type")
    val operationType: String,

    val payload: JsonObject,

    @SerialName("performed_by")
    val performedBy: String,

    @SerialName("performed_at")
    val performedAt: String,

    @SerialName("device_id")
    val deviceId: String? = null,

    @SerialName("idempotency_key")
    val idempotencyKey: String,
)
