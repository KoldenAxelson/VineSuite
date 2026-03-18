package com.vinesuite.shared.api

import com.vinesuite.shared.api.models.ApiError
import com.vinesuite.shared.api.models.EmptyApiResponse
import com.vinesuite.shared.api.models.LoginApiResponse
import com.vinesuite.shared.api.models.LoginRequest
import com.vinesuite.shared.api.models.LoginResponse
import com.vinesuite.shared.api.models.SyncPullApiResponse
import com.vinesuite.shared.api.models.SyncPullMeta
import com.vinesuite.shared.api.models.SyncPullResponse
import com.vinesuite.shared.api.models.SyncPushApiResponse
import com.vinesuite.shared.api.models.SyncPushRequest
import com.vinesuite.shared.api.models.SyncPushResult
import com.vinesuite.shared.models.SyncEvent
import io.ktor.client.HttpClient
import io.ktor.client.call.body
import io.ktor.client.plugins.HttpTimeout
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.client.plugins.defaultRequest
import io.ktor.client.request.get
import io.ktor.client.request.header
import io.ktor.client.request.parameter
import io.ktor.client.request.post
import io.ktor.client.request.setBody
import io.ktor.http.ContentType
import io.ktor.http.HttpStatusCode
import io.ktor.http.contentType
import io.ktor.serialization.kotlinx.json.json
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonPrimitive

/**
 * VineSuite API client — wraps Ktor HttpClient with auth, tenant headers,
 * JSON serialization, and typed Result returns.
 *
 * All public methods return [Result] — never throw on network/HTTP errors.
 *
 * Uses concrete response classes per endpoint (LoginApiResponse,
 * SyncPushApiResponse, etc.) instead of a generic envelope. This gives
 * kotlinx-serialization full type info and works cleanly through Ktor's
 * entire ContentNegotiation plugin pipeline.
 */
class ApiClient(
    private val baseUrl: String,
    private val authManager: AuthManager,
    httpClient: HttpClient? = null,
) {
    private val json = Json {
        ignoreUnknownKeys = true
        isLenient = true
        encodeDefaults = true
    }

    private val client: HttpClient = httpClient ?: HttpClient {
        install(ContentNegotiation) {
            json(this@ApiClient.json)
        }
        install(HttpTimeout) {
            requestTimeoutMillis = 30_000
            connectTimeoutMillis = 10_000
            socketTimeoutMillis = 30_000
        }
        defaultRequest {
            contentType(ContentType.Application.Json)
        }
    }

    // ── Auth ──────────────────────────────────────────────────────

    suspend fun login(
        email: String,
        password: String,
        clientType: String = "cellar_app",
        deviceName: String,
        tenantId: String,
    ): Result<LoginResponse> = apiCall {
        val response = client.post("$baseUrl/auth/login") {
            header("X-Tenant-ID", tenantId)
            setBody(LoginRequest(email, password, clientType, deviceName))
        }
        val envelope = response.body<LoginApiResponse>()
        val result = unwrap(response.status, envelope.errors, envelope.data)
        result.getOrNull()?.let { login ->
            authManager.storeAuth(login.token, login.user, tenantId)
        }
        result
    }

    suspend fun logout(): Result<Unit> = apiCall {
        client.post("$baseUrl/auth/logout") {
            authHeaders()
        }
        authManager.clearAuth()
        Result.success(Unit)
    }

    // ── Sync Push ────────────────────────────────────────────────

    suspend fun pushEvents(events: List<SyncEvent>): Result<List<SyncPushResult>> = apiCall {
        val response = client.post("$baseUrl/events/sync") {
            authHeaders()
            setBody(SyncPushRequest(events))
        }
        val envelope = response.body<SyncPushApiResponse>()
        unwrap(response.status, envelope.errors, envelope.data)
    }

    // ── Sync Pull ────────────────────────────────────────────────

    suspend fun pullState(since: String? = null): Result<Pair<SyncPullResponse, SyncPullMeta>> = apiCall {
        val response = client.get("$baseUrl/sync/pull") {
            authHeaders()
            since?.let { parameter("since", it) }
        }
        val envelope = response.body<SyncPullApiResponse>()

        if (response.status != HttpStatusCode.OK || envelope.errors.isNotEmpty()) {
            return@apiCall Result.failure(
                ApiException(statusCode = response.status.value, errors = envelope.errors)
            )
        }

        val data = envelope.data ?: return@apiCall Result.failure(
            ApiException(statusCode = response.status.value, errors = listOf(ApiError("Empty pull response")))
        )

        val syncedAt = envelope.meta["synced_at"]?.jsonPrimitive?.content
            ?: return@apiCall Result.failure(
                ApiException(statusCode = response.status.value, errors = listOf(ApiError("Missing synced_at in meta")))
            )
        val hasMore = envelope.meta["has_more"]?.jsonPrimitive?.content?.toBooleanStrictOrNull() ?: false

        Result.success(Pair(data, SyncPullMeta(syncedAt = syncedAt, hasMore = hasMore)))
    }

    // ── Internal helpers ─────────────────────────────────────────

    /**
     * Unwrap a concrete envelope's data field into a Result.
     * Checks for 401 and error lists before returning the data.
     */
    private fun <T> unwrap(status: HttpStatusCode, errors: List<ApiError>, data: T?): Result<T> {
        if (status == HttpStatusCode.Unauthorized) {
            return Result.failure(ApiException(statusCode = 401, errors = errors))
        }
        if (errors.isNotEmpty()) {
            return Result.failure(ApiException(statusCode = status.value, errors = errors))
        }
        data ?: return Result.failure(
            ApiException(statusCode = status.value, errors = listOf(ApiError("No data in response")))
        )
        return Result.success(data)
    }

    private suspend fun <T> apiCall(block: suspend () -> Result<T>): Result<T> {
        return try {
            val result = block()
            result.exceptionOrNull()?.let { error ->
                if (error is ApiException && error.statusCode == 401) {
                    authManager.clearAuth()
                }
            }
            result
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    private fun io.ktor.client.request.HttpRequestBuilder.authHeaders() {
        authManager.getBearerToken()?.let { token ->
            header("Authorization", "Bearer $token")
        }
        authManager.getTenantId()?.let { tenantId ->
            header("X-Tenant-ID", tenantId)
        }
    }
}

class ApiException(
    val statusCode: Int,
    val errors: List<ApiError> = emptyList(),
) : Exception("API error $statusCode: ${errors.firstOrNull()?.message ?: "Unknown"}")
