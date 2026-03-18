package com.vinesuite.shared.sync

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlin.math.min

/**
 * Manages sync timing and triggers.
 *
 * Sync triggers (per spec):
 * - Immediate: after any local operation (when online)
 * - Periodic: every [backgroundIntervalMs] when backgrounded (default 5 min)
 * - Reconnect: full sync immediately when connectivity is restored
 *
 * Backoff: on consecutive failures, the periodic interval doubles
 * (5 min → 10 min → 20 min → capped at [maxBackoffMs]). Resets to
 * normal on success or manual [syncNow].
 *
 * The scheduler owns the coroutine lifecycle. Call [start] to begin
 * periodic syncing, [stop] to cancel, [syncNow] for an immediate trigger.
 */
class SyncScheduler(
    private val syncEngine: SyncEngine,
    private val connectivityMonitor: ConnectivityMonitor,
    private val backgroundIntervalMs: Long = 5 * 60 * 1000L, // 5 minutes
    private val maxBackoffMs: Long = 30 * 60 * 1000L, // 30 minutes
) {
    private var periodicJob: Job? = null
    private var consecutiveFailures: Int = 0

    /**
     * Current interval accounting for backoff.
     */
    val currentIntervalMs: Long
        get() {
            if (consecutiveFailures == 0) return backgroundIntervalMs
            val backoff = backgroundIntervalMs * (1L shl min(consecutiveFailures, 10))
            return min(backoff, maxBackoffMs)
        }

    /**
     * Start periodic background syncing.
     * Call from the app's main scope (e.g., Application.onCreate or AppDelegate).
     */
    fun start(scope: CoroutineScope) {
        stop()
        consecutiveFailures = 0
        periodicJob = scope.launch {
            while (true) {
                delay(currentIntervalMs)
                if (connectivityMonitor.isConnected()) {
                    val result = syncEngine.sync()
                    if (result.status == SyncResultStatus.SUCCESS) {
                        consecutiveFailures = 0
                    } else {
                        consecutiveFailures++
                    }
                }
            }
        }
    }

    /**
     * Stop periodic syncing.
     */
    fun stop() {
        periodicJob?.cancel()
        periodicJob = null
    }

    /**
     * Trigger an immediate sync (e.g., after a local operation or on reconnect).
     * Non-blocking — launches in the provided scope.
     * Resets backoff on success.
     *
     * @return The Job for the sync, or null if already running.
     */
    fun syncNow(scope: CoroutineScope): Job? {
        if (syncEngine.state.value == SyncState.PUSHING ||
            syncEngine.state.value == SyncState.PULLING
        ) {
            return null
        }
        return scope.launch {
            val result = syncEngine.sync()
            if (result.status == SyncResultStatus.SUCCESS) {
                consecutiveFailures = 0
            }
        }
    }

    val isRunning: Boolean
        get() = periodicJob?.isActive == true
}
