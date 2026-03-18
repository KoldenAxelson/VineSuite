package com.vinesuite.shared.sync

/**
 * Network connectivity check.
 *
 * Interface (not expect/actual) so tests can provide fake implementations.
 * Each platform supplies a concrete class:
 * - JVM: JvmConnectivityMonitor (always true)
 * - Android: AndroidConnectivityMonitor (ConnectivityManager)
 * - iOS: IosConnectivityMonitor (NWPathMonitor)
 */
interface ConnectivityMonitor {
    fun isConnected(): Boolean
}
