package com.vinesuite.shared.api

import platform.Foundation.NSUserDefaults

/**
 * iOS implementation — NSUserDefaults for now.
 * TODO: Migrate to Keychain Services for production security.
 */
actual class SecureStorage {
    private val defaults = NSUserDefaults.standardUserDefaults

    actual fun get(key: String): String? = defaults.stringForKey(key)

    actual fun set(key: String, value: String) {
        defaults.setObject(value, forKey = key)
    }

    actual fun remove(key: String) {
        defaults.removeObjectForKey(key)
    }

    actual fun clear() {
        // Clear known keys — NSUserDefaults doesn't support full wipe easily
        listOf(AuthManager.KEY_TOKEN, AuthManager.KEY_USER_ID, AuthManager.KEY_TENANT_ID).forEach {
            defaults.removeObjectForKey(it)
        }
    }
}
