package com.vinesuite.shared.api

import com.vinesuite.shared.models.SyncEvent
import io.ktor.client.HttpClient
import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.http.ContentType
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpStatusCode
import io.ktor.http.headersOf
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlin.test.BeforeTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

class ApiClientTest {

    private lateinit var secureStorage: SecureStorage
    private lateinit var authManager: AuthManager

    private val jsonHeaders = headersOf(HttpHeaders.ContentType, ContentType.Application.Json.toString())

    @BeforeTest
    fun setup() {
        secureStorage = SecureStorage()
        authManager = AuthManager(secureStorage)
    }

    // ── Login ────────────────────────────────────────────────────

    @Test
    fun loginStoresTokenOnSuccess() = runTest {
        val client = createApiClient(MockEngine { request ->
            assertEquals("/api/v1/auth/login", request.url.encodedPath)
            assertEquals("tenant-abc", request.headers["X-Tenant-ID"])

            respond(
                content = """
                {
                    "data": {
                        "token": "test-sanctum-token-123",
                        "user": {
                            "id": "user-uuid-1",
                            "name": "Jane Winemaker",
                            "email": "jane@vineyard.com",
                            "role": "winemaker"
                        }
                    },
                    "meta": {},
                    "errors": []
                }
                """,
                status = HttpStatusCode.OK,
                headers = jsonHeaders,
            )
        })

        val result = client.login(
            email = "jane@vineyard.com",
            password = "password123",
            deviceName = "Test Device",
            tenantId = "tenant-abc",
        )

        assertTrue(result.isSuccess)
        val login = result.getOrThrow()
        assertEquals("test-sanctum-token-123", login.token)
        assertEquals("Jane Winemaker", login.user.name)
        assertEquals("winemaker", login.user.role)

        // Token should be stored
        assertTrue(authManager.isAuthenticated())
        assertEquals("test-sanctum-token-123", authManager.getBearerToken())
        assertEquals("user-uuid-1", authManager.getUserId())
        assertEquals("tenant-abc", authManager.getTenantId())
    }

    @Test
    fun loginReturnsFailureOnInvalidCredentials() = runTest {
        val client = createApiClient(MockEngine {
            respond(
                content = """
                {
                    "data": null,
                    "meta": {},
                    "errors": [{"field": "email", "message": "These credentials do not match our records."}]
                }
                """,
                status = HttpStatusCode.UnprocessableEntity,
                headers = jsonHeaders,
            )
        })

        val result = client.login(
            email = "wrong@email.com",
            password = "wrong",
            deviceName = "Test",
            tenantId = "tenant-abc",
        )

        assertTrue(result.isFailure)
        val error = result.exceptionOrNull() as ApiException
        assertEquals(422, error.statusCode)
        assertTrue(error.errors.first().message.contains("credentials"))
    }

    @Test
    fun loginHandlesNetworkError() = runTest {
        val client = createApiClient(MockEngine {
            throw java.net.ConnectException("Connection refused")
        })

        val result = client.login(
            email = "jane@vineyard.com",
            password = "password123",
            deviceName = "Test",
            tenantId = "tenant-abc",
        )

        assertTrue(result.isFailure)
        assertFalse(authManager.isAuthenticated())
    }

    // ── Auth state ───────────────────────────────────────────────

    @Test
    fun logoutClearsAuthState() = runTest {
        // Pre-populate auth state
        authManager.storeAuth(
            token = "existing-token",
            user = com.vinesuite.shared.api.models.UserInfo("u1", "Jane", "j@v.com", "winemaker"),
            tenantId = "tenant-abc",
        )
        assertTrue(authManager.isAuthenticated())

        val client = createApiClient(MockEngine {
            respond(
                content = """{"data": null, "meta": {}, "errors": []}""",
                status = HttpStatusCode.OK,
                headers = jsonHeaders,
            )
        })

        client.logout()

        assertFalse(authManager.isAuthenticated())
        assertNull(authManager.getBearerToken())
    }

    @Test
    fun unauthorizedResponseClearsAuth() = runTest {
        authManager.storeAuth(
            token = "expired-token",
            user = com.vinesuite.shared.api.models.UserInfo("u1", "Jane", "j@v.com", "winemaker"),
            tenantId = "tenant-abc",
        )

        val client = createApiClient(MockEngine {
            respond(
                content = """{"data": null, "meta": {}, "errors": [{"message": "Unauthenticated."}]}""",
                status = HttpStatusCode.Unauthorized,
                headers = jsonHeaders,
            )
        })

        val result = client.pushEvents(emptyList())

        assertTrue(result.isFailure)
        val error = result.exceptionOrNull() as ApiException
        assertEquals(401, error.statusCode)
        // Auth should be cleared on 401
        assertFalse(authManager.isAuthenticated())
    }

    // ── Push Events ──────────────────────────────────────────────

    @Test
    fun pushEventsSendsBatchWithAuthHeaders() = runTest {
        authManager.storeAuth(
            token = "valid-token",
            user = com.vinesuite.shared.api.models.UserInfo("u1", "Jane", "j@v.com", "winemaker"),
            tenantId = "tenant-abc",
        )

        val client = createApiClient(MockEngine { request ->
            assertEquals("/api/v1/events/sync", request.url.encodedPath)
            assertEquals("Bearer valid-token", request.headers["Authorization"])
            assertEquals("tenant-abc", request.headers["X-Tenant-ID"])

            respond(
                content = """
                {
                    "data": [
                        {"index": 0, "event_id": "srv-evt-1", "status": "accepted", "idempotency_key": "idem-001"},
                        {"index": 1, "event_id": "srv-evt-2", "status": "accepted", "idempotency_key": "idem-002"}
                    ],
                    "meta": {"message": "Sync complete.", "accepted": 2, "skipped": 0, "failed": 0},
                    "errors": []
                }
                """,
                status = HttpStatusCode.OK,
                headers = jsonHeaders,
            )
        })

        val events = listOf(
            SyncEvent("lot", "lot-1", "addition", buildJsonObject { put("amount", 25.0) }, "u1", "2024-10-15T14:00:00Z", "device-1", "idem-001"),
            SyncEvent("vessel", "vessel-1", "transfer", buildJsonObject { put("volume", 100) }, "u1", "2024-10-15T14:01:00Z", "device-1", "idem-002"),
        )

        val result = client.pushEvents(events)

        assertTrue(result.isSuccess)
        val results = result.getOrThrow()
        assertEquals(2, results.size)
        assertEquals("accepted", results[0].status)
        assertEquals("accepted", results[1].status)
        assertEquals("srv-evt-1", results[0].eventId)
    }

    @Test
    fun pushEventsHandlesDuplicateIdempotencyKey() = runTest {
        authManager.storeAuth(
            token = "valid-token",
            user = com.vinesuite.shared.api.models.UserInfo("u1", "Jane", "j@v.com", "winemaker"),
            tenantId = "tenant-abc",
        )

        val client = createApiClient(MockEngine {
            respond(
                content = """
                {
                    "data": [
                        {"index": 0, "event_id": "existing-evt", "status": "skipped", "idempotency_key": "idem-dup"}
                    ],
                    "meta": {"message": "Sync complete.", "accepted": 0, "skipped": 1, "failed": 0},
                    "errors": []
                }
                """,
                status = HttpStatusCode.OK,
                headers = jsonHeaders,
            )
        })

        val events = listOf(
            SyncEvent("lot", "lot-1", "addition", JsonObject(emptyMap()), "u1", "2024-10-15T14:00:00Z", null, "idem-dup"),
        )

        val result = client.pushEvents(events)
        assertTrue(result.isSuccess)
        assertEquals("skipped", result.getOrThrow().first().status)
    }

    @Test
    fun pushEventsHandlesPartialFailure() = runTest {
        authManager.storeAuth(
            token = "valid-token",
            user = com.vinesuite.shared.api.models.UserInfo("u1", "Jane", "j@v.com", "winemaker"),
            tenantId = "tenant-abc",
        )

        val client = createApiClient(MockEngine {
            respond(
                content = """
                {
                    "data": [
                        {"index": 0, "event_id": "srv-1", "status": "accepted", "idempotency_key": "idem-1"},
                        {"index": 1, "event_id": null, "status": "failed", "idempotency_key": "idem-2", "error": "Invalid entity_id"}
                    ],
                    "meta": {"accepted": 1, "skipped": 0, "failed": 1},
                    "errors": []
                }
                """,
                status = HttpStatusCode.OK,
                headers = jsonHeaders,
            )
        })

        val events = listOf(
            SyncEvent("lot", "lot-1", "addition", JsonObject(emptyMap()), "u1", "2024-10-15T14:00:00Z", null, "idem-1"),
            SyncEvent("lot", "bad-id", "addition", JsonObject(emptyMap()), "u1", "2024-10-15T14:01:00Z", null, "idem-2"),
        )

        val result = client.pushEvents(events)
        assertTrue(result.isSuccess)
        val results = result.getOrThrow()
        assertEquals("accepted", results[0].status)
        assertEquals("failed", results[1].status)
        assertEquals("Invalid entity_id", results[1].error)
    }

    // ── Pull State ───────────────────────────────────────────────

    @Test
    fun pullStateReturnsEntitiesAndMeta() = runTest {
        authManager.storeAuth(
            token = "valid-token",
            user = com.vinesuite.shared.api.models.UserInfo("u1", "Jane", "j@v.com", "winemaker"),
            tenantId = "tenant-abc",
        )

        val client = createApiClient(MockEngine { request ->
            assertEquals("/api/v1/sync/pull", request.url.encodedPath)
            assertNull(request.url.parameters["since"]) // initial full sync

            respond(
                content = """
                {
                    "data": {
                        "lots": [
                            {"id": "lot-1", "name": "Cab Sauv 2024", "variety": "Cabernet Sauvignon", "vintage": 2024, "source_type": "estate", "volume_gallons": 500.0, "status": "in_progress", "parent_lot_id": null, "updated_at": "2024-10-15T14:00:00Z"}
                        ],
                        "vessels": [
                            {"id": "vessel-1", "name": "Tank 1", "type": "tank", "capacity_gallons": 1000.0, "status": "in_use", "current_volume": 500.0, "current_lot": {"id": "lot-1", "name": "Cab Sauv 2024", "variety": "Cabernet Sauvignon", "vintage": 2024}, "updated_at": "2024-10-15T14:00:00Z"}
                        ],
                        "work_orders": [],
                        "barrels": [],
                        "raw_materials": [
                            {"id": "rm-1", "name": "Potassium Metabisulfite", "category": "additive", "unit_of_measure": "g", "is_active": true, "updated_at": "2024-10-01T10:00:00Z"}
                        ]
                    },
                    "meta": {"synced_at": "2024-10-15T14:05:00Z", "has_more": false, "counts": {"lots": 1, "vessels": 1, "work_orders": 0, "barrels": 0, "raw_materials": 1}},
                    "errors": []
                }
                """,
                status = HttpStatusCode.OK,
                headers = jsonHeaders,
            )
        })

        val result = client.pullState()
        assertTrue(result.isSuccess)

        val (data, meta) = result.getOrThrow()
        assertEquals(1, data.lots.size)
        assertEquals("Cab Sauv 2024", data.lots.first().name)
        assertEquals(1, data.vessels.size)
        assertEquals("Tank 1", data.vessels.first().name)
        assertNotNull(data.vessels.first().currentLot)
        assertEquals(1, data.rawMaterials.size)

        assertEquals("2024-10-15T14:05:00Z", meta.syncedAt)
        assertFalse(meta.hasMore)
    }

    @Test
    fun pullStateWithSinceParameter() = runTest {
        authManager.storeAuth(
            token = "valid-token",
            user = com.vinesuite.shared.api.models.UserInfo("u1", "Jane", "j@v.com", "winemaker"),
            tenantId = "tenant-abc",
        )

        val client = createApiClient(MockEngine { request ->
            assertEquals("2024-10-15T14:00:00Z", request.url.parameters["since"])

            respond(
                content = """
                {
                    "data": {"lots": [], "vessels": [], "work_orders": [], "barrels": [], "raw_materials": []},
                    "meta": {"synced_at": "2024-10-15T14:10:00Z", "has_more": false},
                    "errors": []
                }
                """,
                status = HttpStatusCode.OK,
                headers = jsonHeaders,
            )
        })

        val result = client.pullState(since = "2024-10-15T14:00:00Z")
        assertTrue(result.isSuccess)
        assertEquals("2024-10-15T14:10:00Z", result.getOrThrow().second.syncedAt)
    }

    @Test
    fun pullStateHandlesHasMorePagination() = runTest {
        authManager.storeAuth(
            token = "valid-token",
            user = com.vinesuite.shared.api.models.UserInfo("u1", "Jane", "j@v.com", "winemaker"),
            tenantId = "tenant-abc",
        )

        val client = createApiClient(MockEngine {
            respond(
                content = """
                {
                    "data": {"lots": [{"id": "lot-1", "name": "L1", "variety": "Merlot", "vintage": 2024, "updated_at": "2024-10-15T14:00:00Z"}], "vessels": [], "work_orders": [], "barrels": [], "raw_materials": []},
                    "meta": {"synced_at": "2024-10-15T14:00:00Z", "has_more": true},
                    "errors": []
                }
                """,
                status = HttpStatusCode.OK,
                headers = jsonHeaders,
            )
        })

        val result = client.pullState()
        assertTrue(result.isSuccess)
        assertTrue(result.getOrThrow().second.hasMore)
    }

    @Test
    fun pullStateHandlesServerError() = runTest {
        authManager.storeAuth(
            token = "valid-token",
            user = com.vinesuite.shared.api.models.UserInfo("u1", "Jane", "j@v.com", "winemaker"),
            tenantId = "tenant-abc",
        )

        val client = createApiClient(MockEngine {
            respond(
                content = """{"data": null, "meta": {}, "errors": [{"message": "Internal server error"}]}""",
                status = HttpStatusCode.InternalServerError,
                headers = jsonHeaders,
            )
        })

        val result = client.pullState()
        assertTrue(result.isFailure)
        val error = result.exceptionOrNull() as ApiException
        assertEquals(500, error.statusCode)
    }

    // ── AuthManager ──────────────────────────────────────────────

    @Test
    fun authManagerCachedUserRoundTrips() {
        val user = com.vinesuite.shared.api.models.UserInfo("u1", "Jane", "j@v.com", "winemaker")
        authManager.storeAuth("token", user, "tenant-1")

        val cached = authManager.getCachedUser()
        assertNotNull(cached)
        assertEquals("u1", cached.id)
        assertEquals("Jane", cached.name)
        assertEquals("j@v.com", cached.email)
        assertEquals("winemaker", cached.role)
    }

    @Test
    fun authManagerClearRemovesEverything() {
        authManager.storeAuth(
            "token",
            com.vinesuite.shared.api.models.UserInfo("u1", "Jane", "j@v.com", "winemaker"),
            "tenant-1",
        )
        authManager.clearAuth()

        assertFalse(authManager.isAuthenticated())
        assertNull(authManager.getUserId())
        assertNull(authManager.getTenantId())
        assertNull(authManager.getCachedUser())
    }

    // ── Helpers ──────────────────────────────────────────────────

    private fun createApiClient(engine: MockEngine): ApiClient {
        val httpClient = HttpClient(engine)
        return ApiClient(
            baseUrl = "https://api.vinesuite.com/api/v1",
            authManager = authManager,
            httpClient = httpClient,
        )
    }
}
