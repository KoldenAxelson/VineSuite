package com.vinesuite.shared.api.models

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject

/**
 * Concrete API response envelopes — one per endpoint.
 *
 * Every VineSuite API response follows: { data, meta, errors }
 *
 * We use concrete classes (not generics) because kotlinx-serialization
 * erases generic type parameters at runtime, which breaks Ktor's
 * ContentNegotiation pipeline. Concrete types give the serializer
 * full type info and work cleanly through the entire Ktor plugin chain.
 */

@Serializable
data class ApiError(
    val message: String,
    val field: String? = null,
)

// ── Login ────────────────────────────────────────────────────────

@Serializable
data class LoginApiResponse(
    val data: LoginResponse? = null,
    val meta: JsonObject = JsonObject(emptyMap()),
    val errors: List<ApiError> = emptyList(),
)

// ── Sync Push ────────────────────────────────────────────────────

@Serializable
data class SyncPushApiResponse(
    val data: List<SyncPushResult>? = null,
    val meta: JsonObject = JsonObject(emptyMap()),
    val errors: List<ApiError> = emptyList(),
)

// ── Sync Pull ────────────────────────────────────────────────────

@Serializable
data class SyncPullApiResponse(
    val data: SyncPullResponse? = null,
    val meta: JsonObject = JsonObject(emptyMap()),
    val errors: List<ApiError> = emptyList(),
)

// ── Generic error-only envelope (for logout, etc.) ───────────────

@Serializable
data class EmptyApiResponse(
    val meta: JsonObject = JsonObject(emptyMap()),
    val errors: List<ApiError> = emptyList(),
)
