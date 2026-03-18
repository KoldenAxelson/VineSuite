package com.vinesuite.shared.api

import com.vinesuite.shared.api.models.UserInfo

/**
 * Manages authentication state: Sanctum token storage, user context,
 * and tenant identification.
 *
 * Token lifecycle:
 * 1. Login → store token + user info
 * 2. Every API call → getBearerToken() for Authorization header
 * 3. 401 response → clearAuth() → redirect to login
 * 4. Logout → clearAuth()
 */
class AuthManager(private val storage: SecureStorage) {

    companion object {
        const val KEY_TOKEN = "auth_token"
        const val KEY_USER_ID = "auth_user_id"
        const val KEY_USER_NAME = "auth_user_name"
        const val KEY_USER_EMAIL = "auth_user_email"
        const val KEY_USER_ROLE = "auth_user_role"
        const val KEY_TENANT_ID = "auth_tenant_id"
    }

    /**
     * Store login credentials after successful authentication.
     */
    fun storeAuth(token: String, user: UserInfo, tenantId: String) {
        storage.set(KEY_TOKEN, token)
        storage.set(KEY_USER_ID, user.id)
        storage.set(KEY_USER_NAME, user.name)
        storage.set(KEY_USER_EMAIL, user.email)
        storage.set(KEY_USER_ROLE, user.role)
        storage.set(KEY_TENANT_ID, tenantId)
    }

    /**
     * Get the Bearer token for API calls.
     * Returns null if not authenticated.
     */
    fun getBearerToken(): String? = storage.get(KEY_TOKEN)

    /**
     * Check if the user is currently authenticated.
     */
    fun isAuthenticated(): Boolean = getBearerToken() != null

    /**
     * Get the current user's ID (needed for event attribution).
     */
    fun getUserId(): String? = storage.get(KEY_USER_ID)

    /**
     * Get the tenant ID for the X-Tenant-ID header.
     */
    fun getTenantId(): String? = storage.get(KEY_TENANT_ID)

    /**
     * Get cached user info (for offline display).
     */
    fun getCachedUser(): UserInfo? {
        val id = storage.get(KEY_USER_ID) ?: return null
        val name = storage.get(KEY_USER_NAME) ?: return null
        val email = storage.get(KEY_USER_EMAIL) ?: return null
        val role = storage.get(KEY_USER_ROLE) ?: return null
        return UserInfo(id = id, name = name, email = email, role = role)
    }

    /**
     * Clear all auth state (logout or 401).
     */
    fun clearAuth() {
        storage.clear()
    }
}
