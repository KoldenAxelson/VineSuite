package com.vinesuite.shared.api

import kotlinx.cinterop.ExperimentalForeignApi
import kotlinx.cinterop.alloc
import kotlinx.cinterop.memScoped
import kotlinx.cinterop.ptr
import kotlinx.cinterop.value
import platform.CoreFoundation.CFAutorelease
import platform.CoreFoundation.CFDictionaryRef
import platform.CoreFoundation.CFStringRef
import platform.CoreFoundation.CFTypeRef
import platform.CoreFoundation.CFTypeRefVar
import platform.CoreFoundation.kCFBooleanTrue
import platform.Foundation.CFBridgingRelease
import platform.Foundation.CFBridgingRetain
import platform.Foundation.NSData
import platform.Foundation.NSString
import platform.Foundation.NSUTF8StringEncoding
import platform.Foundation.create
import platform.Foundation.dataUsingEncoding
import platform.Security.SecItemAdd
import platform.Security.SecItemCopyMatching
import platform.Security.SecItemDelete
import platform.Security.SecItemUpdate
import platform.Security.errSecItemNotFound
import platform.Security.errSecSuccess
import platform.Security.kSecAttrAccount
import platform.Security.kSecAttrService
import platform.Security.kSecClassGenericPassword
import platform.Security.kSecClass
import platform.Security.kSecMatchLimit
import platform.Security.kSecMatchLimitOne
import platform.Security.kSecReturnData
import platform.Security.kSecValueData

/**
 * iOS implementation — Keychain Services.
 *
 * Stores each key-value pair as a kSecClassGenericPassword item with:
 * - kSecAttrService = "com.vinesuite.cellar" (groups all app items)
 * - kSecAttrAccount = the key name
 * - kSecValueData = the value encoded as UTF-8
 *
 * Keychain items persist across app installs and are protected by the
 * device passcode / Secure Enclave (hardware-backed on modern devices).
 */
@OptIn(ExperimentalForeignApi::class)
actual class SecureStorage {

    companion object {
        private const val SERVICE_NAME = "com.vinesuite.cellar"
    }

    actual fun get(key: String): String? {
        val query = mapOf<Any?, Any?>(
            kSecClass to kSecClassGenericPassword,
            kSecAttrService to SERVICE_NAME,
            kSecAttrAccount to key,
            kSecReturnData to kCFBooleanTrue,
            kSecMatchLimit to kSecMatchLimitOne,
        )

        memScoped {
            val result = alloc<CFTypeRefVar>()
            val status = SecItemCopyMatching(query.toCFDictionary(), result.ptr)

            if (status != errSecSuccess) return null

            val data = CFBridgingRelease(result.value) as? NSData ?: return null
            return NSString.create(data = data, encoding = NSUTF8StringEncoding) as? String
        }
    }

    actual fun set(key: String, value: String) {
        val encoded = (value as NSString).dataUsingEncoding(NSUTF8StringEncoding) ?: return

        // Try update first — if the item exists, overwrite it
        val query = mapOf<Any?, Any?>(
            kSecClass to kSecClassGenericPassword,
            kSecAttrService to SERVICE_NAME,
            kSecAttrAccount to key,
        )
        val update = mapOf<Any?, Any?>(
            kSecValueData to encoded,
        )

        val status = SecItemUpdate(query.toCFDictionary(), update.toCFDictionary())

        if (status == errSecItemNotFound) {
            // Item doesn't exist yet — add it
            val addQuery = mapOf<Any?, Any?>(
                kSecClass to kSecClassGenericPassword,
                kSecAttrService to SERVICE_NAME,
                kSecAttrAccount to key,
                kSecValueData to encoded,
            )
            SecItemAdd(addQuery.toCFDictionary(), null)
        }
    }

    actual fun remove(key: String) {
        val query = mapOf<Any?, Any?>(
            kSecClass to kSecClassGenericPassword,
            kSecAttrService to SERVICE_NAME,
            kSecAttrAccount to key,
        )
        SecItemDelete(query.toCFDictionary())
    }

    actual fun clear() {
        // Delete ALL items for this service — no hardcoded key list needed
        val query = mapOf<Any?, Any?>(
            kSecClass to kSecClassGenericPassword,
            kSecAttrService to SERVICE_NAME,
        )
        SecItemDelete(query.toCFDictionary())
    }
}

/**
 * Bridge a Kotlin Map to a CFDictionaryRef for Security framework calls.
 *
 * Uses CFBridgingRetain to cross the Kotlin/ObjC boundary. The dictionary
 * is autoreleased — callers don't need to manage its lifecycle.
 */
@OptIn(ExperimentalForeignApi::class)
private fun Map<Any?, Any?>.toCFDictionary(): CFDictionaryRef? {
    val nsDict = this as? Map<*, *> ?: return null
    @Suppress("UNCHECKED_CAST")
    val bridged = CFBridgingRetain(nsDict as Any) as CFDictionaryRef
    CFAutorelease(bridged)
    return bridged
}
