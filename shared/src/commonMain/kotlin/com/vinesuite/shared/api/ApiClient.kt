package com.vinesuite.shared.api

import com.vinesuite.shared.api.models.ApiEnvelope
import com.vinesuite.shared.api.models.ApiError
import com.vinesuite.shared.api.models.LoginRequest
import com.vinesuite.shared.api.models.LoginResponse
import com.vinesuite.shared.api.models.SyncPullMeta
import com.vinesuite.shared.api.models.SyncPullResponse
import com.vinesuite.shared.api.models.SyncPushRequest
import com.vinesuite.shared.api.models.SyncPushResult
import com.vinesuite.shared.models.SyncEvent
import io.ktor.client.HttpClient
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.client.plugins.defaultRequest
import io.ktor.client.request.get
import io.ktor.client.request.header
import io.ktor.client.request.parameter
import io.ktor.client.request.post
import io.ktor.client.request.setBody
import io.ktor.client.statement.bodyAsText
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
 * Callers handle success/failure via Result.getOrNull() / Result.exceptionOrNull().
 *
 * Response parsing uses bodyAsText() + manual Json.decodeFromString rather
 * than Ktor's ContentNegotiation for responses. This avoids generic type
 * erasure issues with kotlinx-serialization and gives us full control
 * over the two-step envelope unwrapping.
 *
 * ContentNegotiation is still used for request body serialization.
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
            contentType(ContentType.Application.Json)
            setBody(json.encodeToString(LoginRequest.serializer(), LoginRequest(email, password, clientType, deviceName)))
        }
        val envelope = parseEnvelope(response.bodyAsText())
        val result = decodeData<LoginResponse>(response.status, envelope)
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
            contentType(ContentType.Application.Json)
            setBody(json.encodeToString(SyncPushRequest.serializer(), SyncPushRequest(events)))
        }
        val envelope = parseEnvelope(response.bodyAsText())
        decodeData<List<SyncPushResult>>(response.status, envelope)
    }

    // ── Sync Pull ────────────────────────────────────────────────

    suspend fun pullState(since: String? = null): Result<Pair<SyncPullResponse, SyncPullMeta>> = apiCall {
        val response = client.get("$baseUrl/sync/pull") {
            authHeaders()
            since?.let { parameter("since", it) }
        }
        val envelope = parseEnvelope(response.bodyAsText())

        if (response.status != HttpStatusCode.OK || envelope.errors.isNotEmpty()) {
            return@apiCall Result.failure(
                ApiException(statusCode = response.status.value, errors = envelope.errors)
            )
        }

        val data = envelope.data?.let {
            json.decodeFromJsonElement(SyncPullResponse.serializer(), it)
        } ?: return@apiCall Result.failure(
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

    private fun parseEnvelope(body: String): ApiEnvelope {
        return json.decodeFromString(ApiEnvelope.serializer(), body)
    }

    private inline fun <reified T> decodeData(status: HttpStatusCode, envelope: ApiEnvelope): Result<T> {
        if (status == HttpStatusCode.Unauthorized) {
            return Result.failure(ApiException(statusCode = 401, errors = envelope.errors))
        }
        if (envelope.errors.isNotEmpty()) {
            return Result.failure(ApiException(statusCode = status.value, errors = envelope.errors))
        }
        val dataElement = envelope.data
            ?: return Result.failure(ApiException(statusCode = status.value, errors = listOf(ApiError("No data in response"))))
        return try {
            Result.success(json.decodeFromJsonElement(kotlinx.serialization.serializer<T>(), dataElement))
        } catch (e: Exception) {
            Result.failure(ApiException(statusCode = status.value, errors = listOf(ApiError("Parse error: ${e.message}"))))
        }
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
