package com.vinesuite.shared.sync

/**
 * JVM implementation — assumes connectivity.
 * Tests control "offline" behavior via fake ConnectivityMonitor implementations.
 */
class JvmConnectivityMonitor : ConnectivityMonitor {
    override fun isConnected(): Boolean = true
}
