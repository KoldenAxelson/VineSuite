package com.vinesuite.shared.sync

import com.vinesuite.shared.database.LocalConflict
import com.vinesuite.shared.database.OutboxEvent
import com.vinesuite.shared.database.VineSuiteDatabase
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import app.cash.sqldelight.coroutines.asFlow
import app.cash.sqldelight.coroutines.mapToList
import kotlinx.coroutines.Dispatchers
import kotlinx.datetime.Clock
import kotlin.uuid.ExperimentalUuidApi
import kotlin.uuid.Uuid

/**
 * Persists unresolved sync conflicts locally for user review.
 *
 * Each conflict stores:
 * - What was attempted (the outbox event payload)
 * - What the server says (the current server state at rejection time)
 * - Why it failed (the error message from the server)
 *
 * The UI can display these and let the user:
 * - **Retry**: re-enqueue the event (after the server state has changed)
 * - **Dismiss**: acknowledge and discard the conflict
 */
class ConflictStore(
    private val database: VineSuiteDatabase,
    private val clock: Clock = Clock.System,
) {
    /**
     * Record a new conflict from a failed destructive operation.
     */
    @OptIn(ExperimentalUuidApi::class)
    fun recordConflict(
        outboxEvent: OutboxEvent,
        errorMessage: String,
        serverState: String,
    ) {
        database.localConflictQueries.insert(
            id = Uuid.random().toString(),
            outbox_event_id = outboxEvent.id,
            entity_type = outboxEvent.entity_type,
            entity_id = outboxEvent.entity_id,
            operation_type = outboxEvent.operation_type,
            attempted_payload = outboxEvent.payload,
            server_state = serverState,
            error_message = errorMessage,
            created_at = clock.now().toString(),
        )
    }

    /**
     * Get all unresolved conflicts.
     */
    fun getUnresolved(): List<LocalConflict> {
        return database.localConflictQueries.selectUnresolved().executeAsList()
    }

    /**
     * Observe unresolved conflicts as a reactive Flow.
     * UI can collect this to update the conflict badge/list.
     */
    fun observeUnresolved(): Flow<List<LocalConflict>> {
        return database.localConflictQueries.selectUnresolved()
            .asFlow()
            .mapToList(Dispatchers.Default)
    }

    /**
     * Count of unresolved conflicts (for badge display).
     */
    fun unresolvedCount(): Long {
        return database.localConflictQueries.countUnresolved().executeAsOne()
    }

    /**
     * Get a single conflict by ID.
     */
    fun getById(conflictId: String): LocalConflict? {
        return database.localConflictQueries.selectById(conflictId).executeAsOneOrNull()
    }

    /**
     * Get all conflicts for a specific entity.
     */
    fun getByEntity(entityType: String, entityId: String): List<LocalConflict> {
        return database.localConflictQueries.selectByEntity(entityType, entityId).executeAsList()
    }

    /**
     * Mark a conflict as resolved (user chose to retry and it succeeded,
     * or the issue was resolved externally).
     */
    fun resolve(conflictId: String) {
        database.localConflictQueries.markResolved(
            resolved_at = clock.now().toString(),
            id = conflictId,
        )
    }

    /**
     * Dismiss a conflict (user acknowledged it and chose not to retry).
     */
    fun dismiss(conflictId: String) {
        database.localConflictQueries.markDismissed(
            resolved_at = clock.now().toString(),
            id = conflictId,
        )
    }

    /**
     * Clean up resolved/dismissed conflicts to reclaim storage.
     */
    fun purgeResolved() {
        database.localConflictQueries.deleteResolved()
    }
}
