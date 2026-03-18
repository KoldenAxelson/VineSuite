package com.vinesuite.shared.api

/**
 * Platform-specific secure key-value storage.
 *
 * - Android: EncryptedSharedPreferences
 * - iOS: Keychain
 * - JVM: In-memory (for tests)
 */
expect class SecureStorage {
    fun get(key: String): String?
    fun set(key: String, value: String)
    fun remove(key: String)
    fun clear()
}
