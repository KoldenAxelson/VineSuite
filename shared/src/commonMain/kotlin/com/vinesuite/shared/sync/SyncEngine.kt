package com.vinesuite.shared.sync

import com.vinesuite.shared.api.ApiClient
import com.vinesuite.shared.api.ApiException
import com.vinesuite.shared.api.models.PulledBarrel
import com.vinesuite.shared.api.models.PulledLot
import com.vinesuite.shared.api.models.PulledRawMaterial
import com.vinesuite.shared.api.models.PulledVessel
import com.vinesuite.shared.api.models.PulledWorkOrder
import com.vinesuite.shared.database.VineSuiteDatabase
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

/**
 * Core synchronization engine — manages the full sync cycle:
 *
 *   IDLE → PUSHING → PULLING → COMPLETE / ERROR
 *
 * Push phase: Collect unsynced events from outbox → POST to server →
 *   mark confirmed events as synced, record failures.
 *
 * Pull phase: GET latest state (since last sync) → upsert into local SQLite.
 *
 * Atomic per phase: if push succeeds but pull fails, push confirmations
 * are NOT lost. The next sync resumes from where it left off.
 *
 * Sync status is exposed as a [StateFlow] so the UI can show a sync indicator.
 */
class SyncEngine(
    private val database: VineSuiteDatabase,
    private val apiClient: ApiClient,
    private val eventQueue: EventQueue,
    private val conflictResolver: ConflictResolver,
    private val connectivityMonitor: ConnectivityMonitor,
    private val logger: com.vinesuite.shared.util.Logger = com.vinesuite.shared.util.NoOpLogger,
) {
    companion object {
        const val SYNC_STATE_LAST_SYNC = "last_sync_timestamp"
        private const val TAG = "VineSuite.SyncEngine"
    }

    private val syncMutex = Mutex()

    private val _state = MutableStateFlow(SyncState.IDLE)
    val state: StateFlow<SyncState> = _state.asStateFlow()

    private val _lastError = MutableStateFlow<String?>(null)
    val lastError: StateFlow<String?> = _lastError.asStateFlow()

    /**
     * Run a full sync cycle: push → pull.
     *
     * Returns a [SyncResult] summarizing what happened.
     * Never throws — errors are captured in the result.
     * Thread-safe: concurrent calls return ALREADY_RUNNING.
     */
    suspend fun sync(): SyncResult {
        if (!syncMutex.tryLock()) {
            return SyncResult(status = SyncResultStatus.ALREADY_RUNNING)
        }
        try {
            return doSync()
        } finally {
            syncMutex.unlock()
        }
    }

    private suspend fun doSync(): SyncResult {

        if (!connectivityMonitor.isConnected()) {
            logger.debug(TAG, "Sync skipped: offline")
            return SyncResult(status = SyncResultStatus.OFFLINE)
        }

        logger.info(TAG, "Sync started")
        _lastError.value = null
        var pushResult: PushResult? = null
        var pullResult: PullResult? = null

        // ── Push phase ───────────────────────────────────────────
        try {
            _state.value = SyncState.PUSHING
            pushResult = pushEvents()
            logger.info(TAG, "Push complete: ${pushResult.accepted} accepted, ${pushResult.skipped} skipped, ${pushResult.failed} failed")
        } catch (e: Exception) {
            _state.value = SyncState.ERROR
            _lastError.value = "Push failed: ${e.message}"
            logger.error(TAG, "Push failed: ${e.message}", e)
            return SyncResult(
                status = SyncResultStatus.PUSH_FAILED,
                push = PushResult(error = e.message),
            )
        }

        // ── Pull phase ───────────────────────────────────────────
        try {
            _state.value = SyncState.PULLING
            pullResult = pullState()
            logger.info(TAG, "Pull complete: ${pullResult.entitiesUpdated} entities updated")
        } catch (e: Exception) {
            _state.value = SyncState.ERROR
            _lastError.value = "Pull failed: ${e.message}"
            logger.error(TAG, "Pull failed: ${e.message}", e)
            return SyncResult(
                status = SyncResultStatus.PULL_FAILED,
                push = pushResult,
                pull = PullResult(error = e.message),
            )
        }

        _state.value = SyncState.IDLE
        logger.info(TAG, "Sync complete")
        return SyncResult(
            status = SyncResultStatus.SUCCESS,
            push = pushResult,
            pull = pullResult,
        )
    }

    // ── Push ─────────────────────────────────────────────────────

    private suspend fun pushEvents(): PushResult {
        var totalAccepted = 0
        var totalSkipped = 0
        var totalFailed = 0
        val processedIds = mutableSetOf<String>()

        // Push in batches until outbox is drained (or only failed events remain)
        while (true) {
            val batch = eventQueue.getPendingBatch(EventQueue.DEFAULT_BATCH_SIZE)
                .filter { it.id !in processedIds }
            if (batch.isEmpty()) break

            val syncEvents = batch.map { eventQueue.toSyncEvent(it) }
            val result = apiClient.pushEvents(syncEvents)

            result.fold(
                onSuccess = { results ->
                    for (pushResult in results) {
                        val outboxEvent = batch.getOrNull(pushResult.index) ?: continue
                        processedIds.add(outboxEvent.id)
                        when (pushResult.status) {
                            "accepted", "skipped" -> {
                                eventQueue.markSynced(outboxEvent.id)
                                if (pushResult.status == "accepted") totalAccepted++
                                else totalSkipped++
                            }
                            "failed" -> {
                                eventQueue.recordFailure(outboxEvent.id, pushResult.error ?: "Unknown server error")
                                // Destructive ops → create user-visible conflict
                                conflictResolver.processPushResult(outboxEvent, pushResult)
                                totalFailed++
                            }
                        }
                    }
                },
                onFailure = { error ->
                    // Batch-level failure (network, 401, 500, etc.)
                    val message = error.message ?: "Unknown error"
                    batch.forEach {
                        processedIds.add(it.id)
                        eventQueue.recordFailure(it.id, message)
                    }
                    throw error
                },
            )
        }

        return PushResult(accepted = totalAccepted, skipped = totalSkipped, failed = totalFailed)
    }

    // ── Pull ─────────────────────────────────────────────────────

    private suspend fun pullState(): PullResult {
        val since = database.syncStateQueries
            .selectByKey(SYNC_STATE_LAST_SYNC)
            .executeAsOneOrNull()

        var totalEntities = 0
        var currentSince = since

        // Paginated pull — keep pulling while has_more is true
        do {
            val result = apiClient.pullState(since = currentSince)

            val (data, meta) = result.getOrElse { throw it }

            // Upsert pulled entities into local database
            database.transaction {
                data.lots.forEach { upsertLot(it) }
                data.vessels.forEach { upsertVessel(it) }
                data.barrels.forEach { upsertBarrel(it) }
                data.workOrders.forEach { upsertWorkOrder(it) }
                data.rawMaterials.forEach { upsertRawMaterial(it) }
            }

            totalEntities += data.lots.size + data.vessels.size +
                data.barrels.size + data.workOrders.size + data.rawMaterials.size

            // Store the synced_at timestamp for the next pull
            database.syncStateQueries.upsert(
                key = SYNC_STATE_LAST_SYNC,
                value_ = meta.syncedAt,
            )

            currentSince = meta.syncedAt
        } while (meta.hasMore)

        return PullResult(entitiesUpdated = totalEntities)
    }

    // ── Upsert helpers ───────────────────────────────────────────

    private fun upsertLot(lot: PulledLot) {
        database.localLotQueries.insert(
            id = lot.id,
            name = lot.name,
            variety = lot.variety,
            vintage = lot.vintage.toLong(),
            source_type = lot.sourceType,
            volume_gallons = lot.volumeGallons,
            status = lot.status,
            parent_lot_id = lot.parentLotId,
            updated_at = lot.updatedAt,
        )
    }

    private fun upsertVessel(vessel: PulledVessel) {
        database.localVesselQueries.insert(
            id = vessel.id,
            name = vessel.name,
            type = vessel.type,
            capacity_gallons = vessel.capacityGallons,
            material = vessel.material,
            location = vessel.location,
            status = vessel.status,
            current_lot_id = vessel.currentLot?.id,
            current_volume = vessel.currentVolume,
            updated_at = vessel.updatedAt,
        )
    }

    private fun upsertBarrel(barrel: PulledBarrel) {
        database.localBarrelQueries.insert(
            id = barrel.id,
            vessel_id = barrel.vesselId,
            cooperage = barrel.cooperage,
            toast_level = barrel.toastLevel,
            oak_type = barrel.oakType,
            forest_origin = barrel.forestOrigin,
            volume_gallons = barrel.volumeGallons,
            years_used = barrel.yearsUsed.toLong(),
            qr_code = barrel.qrCode,
            updated_at = barrel.updatedAt,
        )
    }

    private fun upsertWorkOrder(wo: PulledWorkOrder) {
        database.localWorkOrderQueries.insert(
            id = wo.id,
            operation_type = wo.operationType,
            lot_id = wo.lot?.id,
            vessel_id = wo.vessel?.id,
            assigned_to = wo.assignedTo?.id,
            due_date = wo.dueDate,
            status = wo.status,
            priority = wo.priority,
            notes = wo.notes,
            completed_at = wo.completedAt,
            completed_by = wo.completedBy?.id,
            updated_at = wo.updatedAt,
        )
    }

    private fun upsertRawMaterial(rm: PulledRawMaterial) {
        database.localAdditionProductQueries.insert(
            id = rm.id,
            name = rm.name,
            category = rm.category,
            default_rate = rm.costPerUnit,
            default_unit = rm.unitOfMeasure,
        )
    }
}

// ── State machine ────────────────────────────────────────────────

enum class SyncState {
    IDLE,
    PUSHING,
    PULLING,
    ERROR,
}

// ── Result types ─────────────────────────────────────────────────

data class SyncResult(
    val status: SyncResultStatus,
    val push: PushResult? = null,
    val pull: PullResult? = null,
)

enum class SyncResultStatus {
    SUCCESS,
    PUSH_FAILED,
    PULL_FAILED,
    ALREADY_RUNNING,
    OFFLINE,
}

data class PushResult(
    val accepted: Int = 0,
    val skipped: Int = 0,
    val failed: Int = 0,
    val error: String? = null,
)

data class PullResult(
    val entitiesUpdated: Int = 0,
    val error: String? = null,
)
