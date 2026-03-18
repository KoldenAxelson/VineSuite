package com.vinesuite.shared.api.models

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

// ── Sync Push (POST /api/v1/events/sync) ─────────────────────────

/**
 * Wrapper for batch event sync request.
 */
@Serializable
data class SyncPushRequest(
    val events: List<com.vinesuite.shared.models.SyncEvent>,
)

/**
 * Per-event result from the server after sync push.
 */
@Serializable
data class SyncPushResult(
    val index: Int,
    @SerialName("event_id")
    val eventId: String? = null,
    val status: String, // "accepted", "skipped", "failed"
    @SerialName("idempotency_key")
    val idempotencyKey: String,
    val error: String? = null,
)

// ── Sync Pull (GET /api/v1/sync/pull) ────────────────────────────

/**
 * Unified delta pull response. Contains all entities modified since
 * the provided timestamp.
 */
@Serializable
data class SyncPullResponse(
    val lots: List<PulledLot> = emptyList(),
    val vessels: List<PulledVessel> = emptyList(),
    @SerialName("work_orders")
    val workOrders: List<PulledWorkOrder> = emptyList(),
    val barrels: List<PulledBarrel> = emptyList(),
    @SerialName("raw_materials")
    val rawMaterials: List<PulledRawMaterial> = emptyList(),
)

@Serializable
data class SyncPullMeta(
    @SerialName("synced_at")
    val syncedAt: String,
    @SerialName("has_more")
    val hasMore: Boolean = false,
    val counts: SyncPullCounts? = null,
)

@Serializable
data class SyncPullCounts(
    val lots: Int = 0,
    val vessels: Int = 0,
    @SerialName("work_orders")
    val workOrders: Int = 0,
    val barrels: Int = 0,
    @SerialName("raw_materials")
    val rawMaterials: Int = 0,
)

// ── Pulled entity DTOs ───────────────────────────────────────────

@Serializable
data class PulledLot(
    val id: String,
    val name: String,
    val variety: String,
    val vintage: Int,
    @SerialName("source_type")
    val sourceType: String = "estate",
    @SerialName("volume_gallons")
    val volumeGallons: Double = 0.0,
    val status: String = "in_progress",
    @SerialName("parent_lot_id")
    val parentLotId: String? = null,
    @SerialName("updated_at")
    val updatedAt: String,
)

@Serializable
data class PulledVessel(
    val id: String,
    val name: String,
    val type: String,
    @SerialName("capacity_gallons")
    val capacityGallons: Double = 0.0,
    val material: String? = null,
    val location: String? = null,
    val status: String = "empty",
    @SerialName("current_volume")
    val currentVolume: Double = 0.0,
    @SerialName("current_lot")
    val currentLot: EmbeddedLotRef? = null,
    val barrel: EmbeddedBarrelRef? = null,
    @SerialName("updated_at")
    val updatedAt: String,
)

@Serializable
data class PulledBarrel(
    val id: String,
    @SerialName("vessel_id")
    val vesselId: String,
    val cooperage: String? = null,
    @SerialName("toast_level")
    val toastLevel: String? = null,
    @SerialName("oak_type")
    val oakType: String? = null,
    @SerialName("forest_origin")
    val forestOrigin: String? = null,
    @SerialName("volume_gallons")
    val volumeGallons: Double = 59.43,
    @SerialName("years_used")
    val yearsUsed: Int = 0,
    @SerialName("qr_code")
    val qrCode: String? = null,
    @SerialName("updated_at")
    val updatedAt: String,
)

@Serializable
data class PulledWorkOrder(
    val id: String,
    @SerialName("operation_type")
    val operationType: String,
    val status: String = "pending",
    val priority: String = "normal",
    @SerialName("due_date")
    val dueDate: String? = null,
    val notes: String? = null,
    @SerialName("completed_at")
    val completedAt: String? = null,
    val lot: EmbeddedLotRef? = null,
    val vessel: EmbeddedVesselRef? = null,
    @SerialName("assigned_to")
    val assignedTo: EmbeddedUserRef? = null,
    @SerialName("completed_by")
    val completedBy: EmbeddedUserRef? = null,
    @SerialName("updated_at")
    val updatedAt: String,
)

@Serializable
data class PulledRawMaterial(
    val id: String,
    val name: String,
    val category: String,
    @SerialName("unit_of_measure")
    val unitOfMeasure: String = "g",
    @SerialName("cost_per_unit")
    val costPerUnit: Double? = null,
    @SerialName("is_active")
    val isActive: Boolean = true,
    @SerialName("updated_at")
    val updatedAt: String,
)

// ── Embedded refs (nested objects in pull responses) ─────────────

@Serializable
data class EmbeddedLotRef(
    val id: String,
    val name: String,
    val variety: String,
    val vintage: Int,
)

@Serializable
data class EmbeddedBarrelRef(
    val id: String,
    val cooperage: String? = null,
    @SerialName("toast_level")
    val toastLevel: String? = null,
    @SerialName("oak_type")
    val oakType: String? = null,
    @SerialName("volume_gallons")
    val volumeGallons: Double = 59.43,
    @SerialName("years_used")
    val yearsUsed: Int = 0,
    @SerialName("qr_code")
    val qrCode: String? = null,
)

@Serializable
data class EmbeddedVesselRef(
    val id: String,
    val name: String,
    val type: String,
)

@Serializable
data class EmbeddedUserRef(
    val id: String,
    val name: String,
)
