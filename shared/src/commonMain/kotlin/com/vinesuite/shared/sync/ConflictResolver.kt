package com.vinesuite.shared.sync

import com.vinesuite.shared.api.models.SyncPushResult
import com.vinesuite.shared.database.OutboxEvent

/**
 * Determines how to handle event sync results based on operation type.
 *
 * Two categories:
 *
 * **Additive operations** (additions, lab analyses, nutrient additions, etc.)
 * always apply — even from multiple offline devices. No last-write-wins.
 * If the server accepts them, they're done. If the server rejects one,
 * it's a data issue (bad entity_id, etc.), not a conflict.
 *
 * **Destructive operations** (transfers, blending, bottling, etc.)
 * modify shared state (e.g., vessel volume). If two offline devices both
 * try to transfer 300 gallons from a 500-gallon vessel, the second one
 * will fail server-side with "insufficient volume". This is a real conflict
 * that needs user intervention.
 *
 * The resolver classifies each push result and routes conflicts to the
 * [ConflictStore] for user review.
 */
class ConflictResolver(
    private val conflictStore: ConflictStore,
) {
    companion object {
        /**
         * Additive operation types — these always apply, no conflict possible.
         * Multiple offline devices adding SO2 to the same lot is fine.
         */
        val ADDITIVE_OPERATIONS = setOf(
            "addition",
            "lab_analysis",
            "fermentation_reading",
            "fermentation_start",
            "fermentation_end",
            "sensory_note",
        )

        /**
         * Destructive operation types — these modify shared state and can conflict.
         * Server validates volume/state before applying.
         */
        val DESTRUCTIVE_OPERATIONS = setOf(
            "transfer",
            "blend",
            "rack",
            "bottling",
            "pressing",
            "filtering",
            "lot_split",
            "lot_merge",
        )
    }

    /**
     * Classify an operation as additive or destructive.
     */
    fun isDestructive(operationType: String): Boolean {
        return operationType in DESTRUCTIVE_OPERATIONS
    }

    fun isAdditive(operationType: String): Boolean {
        return operationType in ADDITIVE_OPERATIONS
    }

    /**
     * Process a push result for a single event.
     *
     * - Accepted/skipped: no action needed (handled by EventQueue.markSynced).
     * - Failed + additive: just a data error, increment retry. No conflict.
     * - Failed + destructive: real conflict. Store for user review.
     *
     * @return true if a conflict was created, false otherwise.
     */
    fun processPushResult(
        outboxEvent: OutboxEvent,
        pushResult: SyncPushResult,
        serverState: String = "{}",
    ): Boolean {
        if (pushResult.status != "failed") return false

        val operationType = outboxEvent.operation_type

        if (isDestructive(operationType)) {
            // This is a real conflict — store it for user review
            conflictStore.recordConflict(
                outboxEvent = outboxEvent,
                errorMessage = pushResult.error ?: "Server rejected operation",
                serverState = serverState,
            )
            return true
        }

        // Additive operation failure is not a conflict — it's a data issue.
        // The EventQueue retry mechanism handles these.
        return false
    }

    /**
     * Process all push results from a batch sync.
     *
     * @return Number of conflicts created.
     */
    fun processBatchResults(
        batch: List<OutboxEvent>,
        results: List<SyncPushResult>,
        serverState: String = "{}",
    ): Int {
        var conflictsCreated = 0
        for (result in results) {
            val event = batch.getOrNull(result.index) ?: continue
            if (processPushResult(event, result, serverState)) {
                conflictsCreated++
            }
        }
        return conflictsCreated
    }
}
