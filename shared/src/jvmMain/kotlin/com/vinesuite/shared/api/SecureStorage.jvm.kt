package com.vinesuite.shared.api

/**
 * JVM implementation — in-memory storage for tests and local dev.
 * NOT secure. Production apps use Android/iOS implementations.
 */
actual class SecureStorage {
    private val store = mutableMapOf<String, String>()

    actual fun get(key: String): String? = store[key]
    actual fun set(key: String, value: String) { store[key] = value }
    actual fun remove(key: String) { store.remove(key) }
    actual fun clear() { store.clear() }
}
