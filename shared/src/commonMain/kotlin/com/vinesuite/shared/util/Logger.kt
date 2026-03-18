package com.vinesuite.shared.util

/**
 * Lightweight logging interface for the shared core.
 *
 * Platform implementations:
 * - Android: android.util.Log
 * - iOS: os_log / NSLog
 * - JVM: println (tests) or SLF4J (server)
 *
 * Tag convention: "VineSuite.{Component}" e.g. "VineSuite.SyncEngine"
 */
interface Logger {
    fun debug(tag: String, message: String)
    fun info(tag: String, message: String)
    fun warn(tag: String, message: String)
    fun error(tag: String, message: String, throwable: Throwable? = null)
}

/**
 * No-op logger — used when no logging is configured.
 */
object NoOpLogger : Logger {
    override fun debug(tag: String, message: String) {}
    override fun info(tag: String, message: String) {}
    override fun warn(tag: String, message: String) {}
    override fun error(tag: String, message: String, throwable: Throwable?) {}
}

/**
 * Simple println logger — used in JVM tests and local dev.
 */
object PrintLogger : Logger {
    override fun debug(tag: String, message: String) {
        println("D/$tag: $message")
    }
    override fun info(tag: String, message: String) {
        println("I/$tag: $message")
    }
    override fun warn(tag: String, message: String) {
        println("W/$tag: $message")
    }
    override fun error(tag: String, message: String, throwable: Throwable?) {
        println("E/$tag: $message")
        throwable?.printStackTrace()
    }
}
