package com.vinesuite.shared.sync

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Manages sync timing and triggers.
 *
 * Sync triggers (per spec):
 * - Immediate: after any local operation (when online)
 * - Periodic: every [backgroundIntervalMs] when backgrounded (default 5 min)
 * - Reconnect: full sync immediately when connectivity is restored
 *
 * The scheduler owns the coroutine lifecycle. Call [start] to begin
 * periodic syncing, [stop] to cancel, [syncNow] for an immediate trigger.
 */
class SyncScheduler(
    private val syncEngine: SyncEngine,
    private val connectivityMonitor: ConnectivityMonitor,
    private val backgroundIntervalMs: Long = 5 * 60 * 1000L, // 5 minutes
) {
    private var periodicJob: Job? = null

    /**
     * Start periodic background syncing.
     * Call from the app's main scope (e.g., Application.onCreate or AppDelegate).
     */
    fun start(scope: CoroutineScope) {
        stop()
        periodicJob = scope.launch {
            while (true) {
                delay(backgroundIntervalMs)
                if (connectivityMonitor.isConnected()) {
                    syncEngine.sync()
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
            syncEngine.sync()
        }
    }

    val isRunning: Boolean
        get() = periodicJob?.isActive == true
}
