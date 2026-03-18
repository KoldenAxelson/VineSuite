package com.vinesuite.shared.sync

import app.cash.sqldelight.driver.jdbc.sqlite.JdbcSqliteDriver
import com.vinesuite.shared.api.ApiClient
import com.vinesuite.shared.api.AuthManager
import com.vinesuite.shared.api.SecureStorage
import com.vinesuite.shared.api.models.UserInfo
import com.vinesuite.shared.database.VineSuiteDatabase
import com.vinesuite.shared.models.SyncEvent
import io.ktor.client.HttpClient
import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.client.engine.mock.toByteArray
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.client.plugins.defaultRequest
import io.ktor.http.ContentType
import io.ktor.http.contentType
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpStatusCode
import io.ktor.http.headersOf
import io.ktor.serialization.kotlinx.json.json
import kotlinx.coroutines.test.runTest
import kotlinx.datetime.Clock
import kotlinx.datetime.Instant
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlin.test.AfterTest
import kotlin.test.BeforeTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * Integration tests for SyncEngine — validates the full push → pull cycle.
 * Uses in-memory SQLite + Ktor MockEngine. No network calls.
 */
class SyncEngineTest {

    private lateinit var driver: JdbcSqliteDriver
    private lateinit var database: VineSuiteDatabase
    private lateinit var authManager: AuthManager
    private lateinit var eventFactory: EventFactory
    private lateinit var eventQueue: EventQueue

    private val jsonHeaders = headersOf(HttpHeaders.ContentType, ContentType.Application.Json.toString())

    private val fixedClock = object : Clock {
        override fun now(): Instant = Instant.parse("2024-10-15T14:00:00Z")
    }

    @BeforeTest
    fun setup() {
        driver = JdbcSqliteDriver(JdbcSqliteDriver.IN_MEMORY)
        VineSuiteDatabase.Schema.create(driver)
        database = VineSuiteDatabase(driver)

        val storage = SecureStorage()
        authManager = AuthManager(storage)
        authManager.storeAuth(
            token = "test-token",
            user = UserInfo("user-1", "Jane", "j@v.com", "winemaker"),
            tenantId = "tenant-1",
        )

        eventFactory = EventFactory(deviceId = "device-1", userId = "user-1", clock = fixedClock)
        eventQueue = EventQueue(database, eventFactory)
    }

    @AfterTest
    fun teardown() {
        driver.close()
    }

    // ── Full sync cycle ──────────────────────────────────────────

    @Test
    fun fullSyncCyclePushThenPull() = runTest {
        // Enqueue 2 events
        eventQueue.enqueue(createEvent("lot", "lot-1", "addition"))
        eventQueue.enqueue(createEvent("vessel", "vessel-1", "transfer"))
        assertEquals(2, eventQueue.pendingCount())

        var requestCount = 0
        val engine = createSyncEngine(MockEngine { request ->
            requestCount++
            when {
                request.url.encodedPath.contains("events/sync") -> respond(
                    content = """
                    {
                        "data": [
                            {"index": 0, "event_id": "srv-1", "status": "accepted", "idempotency_key": "k1"},
                            {"index": 1, "event_id": "srv-2", "status": "accepted", "idempotency_key": "k2"}
                        ],
                        "meta": {"accepted": 2, "skipped": 0, "failed": 0},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                request.url.encodedPath.contains("sync/pull") -> respond(
                    content = """
                    {
                        "data": {
                            "lots": [{"id": "lot-1", "name": "Cab Sauv", "variety": "Cabernet Sauvignon", "vintage": 2024, "source_type": "estate", "volume_gallons": 450.0, "status": "in_progress", "updated_at": "2024-10-15T14:05:00Z"}],
                            "vessels": [{"id": "vessel-1", "name": "Tank 1", "type": "tank", "capacity_gallons": 1000.0, "status": "in_use", "current_volume": 450.0, "updated_at": "2024-10-15T14:05:00Z"}],
                            "work_orders": [],
                            "barrels": [],
                            "raw_materials": []
                        },
                        "meta": {"synced_at": "2024-10-15T14:05:00Z", "has_more": false},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                else -> error("Unexpected request: ${request.url}")
            }
        })

        val result = engine.sync()

        assertEquals(SyncResultStatus.SUCCESS, result.status)

        // Push: both events accepted
        assertEquals(2, result.push?.accepted)
        assertEquals(0, eventQueue.pendingCount())

        // Pull: local database updated
        val lot = database.localLotQueries.selectById("lot-1").executeAsOneOrNull()
        assertNotNull(lot)
        assertEquals("Cab Sauv", lot.name)
        assertEquals(450.0, lot.volume_gallons)

        val vessel = database.localVesselQueries.selectById("vessel-1").executeAsOneOrNull()
        assertNotNull(vessel)
        assertEquals("Tank 1", vessel.name)

        // Sync state stores last sync timestamp
        val lastSync = database.syncStateQueries.selectByKey(SyncEngine.SYNC_STATE_LAST_SYNC).executeAsOneOrNull()
        assertEquals("2024-10-15T14:05:00Z", lastSync)

        // 2 requests: one push, one pull
        assertEquals(2, requestCount)
    }

    // ── Push only (no pending events) ────────────────────────────

    @Test
    fun syncWithNoPendingEventsSkipsPush() = runTest {
        var requestCount = 0
        val engine = createSyncEngine(MockEngine { request ->
            requestCount++
            respond(
                content = """
                {
                    "data": {"lots": [], "vessels": [], "work_orders": [], "barrels": [], "raw_materials": []},
                    "meta": {"synced_at": "2024-10-15T14:00:00Z", "has_more": false},
                    "errors": []
                }
                """,
                status = HttpStatusCode.OK,
                headers = jsonHeaders,
            )
        })

        val result = engine.sync()

        assertEquals(SyncResultStatus.SUCCESS, result.status)
        assertEquals(0, result.push?.accepted)
        // Only pull request, no push
        assertEquals(1, requestCount)
    }

    // ── Push with partial failure ────────────────────────────────

    @Test
    fun pushHandlesPerEventFailure() = runTest {
        eventQueue.enqueue(createEvent("lot", "lot-1", "addition"))
        eventQueue.enqueue(createEvent("lot", "lot-2", "addition"))

        val engine = createSyncEngine(MockEngine { request ->
            when {
                request.url.encodedPath.contains("events/sync") -> respond(
                    content = """
                    {
                        "data": [
                            {"index": 0, "event_id": "srv-1", "status": "accepted", "idempotency_key": "k1"},
                            {"index": 1, "event_id": null, "status": "failed", "idempotency_key": "k2", "error": "Validation error"}
                        ],
                        "meta": {"accepted": 1, "skipped": 0, "failed": 1},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                request.url.encodedPath.contains("sync/pull") -> respond(
                    content = """
                    {
                        "data": {"lots": [], "vessels": [], "work_orders": [], "barrels": [], "raw_materials": []},
                        "meta": {"synced_at": "2024-10-15T14:00:00Z", "has_more": false},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                else -> error("Unexpected")
            }
        })

        val result = engine.sync()

        assertEquals(SyncResultStatus.SUCCESS, result.status)
        assertEquals(1, result.push?.accepted)
        assertEquals(1, result.push?.failed)
        // One event still pending (the failed one)
        assertEquals(1, eventQueue.pendingCount())
    }

    // ── Push failure (network/server error) ──────────────────────

    @Test
    fun pushNetworkFailureReturnsError() = runTest {
        eventQueue.enqueue(createEvent("lot", "lot-1", "addition"))

        val engine = createSyncEngine(MockEngine {
            throw java.net.ConnectException("Connection refused")
        })

        val result = engine.sync()

        assertEquals(SyncResultStatus.PUSH_FAILED, result.status)
        assertNotNull(result.push?.error)
        assertNull(result.pull)
        // Event still pending
        assertEquals(1, eventQueue.pendingCount())
        assertEquals(SyncState.ERROR, engine.state.value)
    }

    // ── Pull failure (push succeeds) ─────────────────────────────

    @Test
    fun pullFailurePreservesPushSuccess() = runTest {
        eventQueue.enqueue(createEvent("lot", "lot-1", "addition"))

        var callIndex = 0
        val engine = createSyncEngine(MockEngine {
            callIndex++
            when (callIndex) {
                1 -> respond(
                    content = """
                    {
                        "data": [{"index": 0, "event_id": "srv-1", "status": "accepted", "idempotency_key": "k1"}],
                        "meta": {"accepted": 1},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                else -> respond(
                    content = """{"data": null, "meta": {}, "errors": [{"message": "Internal server error"}]}""",
                    status = HttpStatusCode.InternalServerError,
                    headers = jsonHeaders,
                )
            }
        })

        val result = engine.sync()

        assertEquals(SyncResultStatus.PULL_FAILED, result.status)
        // Push succeeded — event is synced
        assertEquals(1, result.push?.accepted)
        assertEquals(0, eventQueue.pendingCount())
        // Pull failed
        assertNotNull(result.pull?.error)
        assertEquals(SyncState.ERROR, engine.state.value)
    }

    // ── Offline ──────────────────────────────────────────────────

    @Test
    fun syncWhenOfflineReturnsOfflineStatus() = runTest {
        val offlineMonitor = object : ConnectivityMonitor {
            override fun isConnected(): Boolean = false
        }

        val conflictStore = ConflictStore(database, fixedClock)
        val conflictResolver = ConflictResolver(conflictStore)
        val engine = SyncEngine(
            database = database,
            apiClient = createApiClient(MockEngine { error("Should not be called") }),
            eventQueue = eventQueue,
            conflictResolver = conflictResolver,
            connectivityMonitor = offlineMonitor,
        )

        val result = engine.sync()
        assertEquals(SyncResultStatus.OFFLINE, result.status)
    }

    // ── Paginated pull ───────────────────────────────────────────

    @Test
    fun pullHandlesPagination() = runTest {
        var pullCount = 0
        val engine = createSyncEngine(MockEngine { request ->
            when {
                request.url.encodedPath.contains("sync/pull") -> {
                    pullCount++
                    when (pullCount) {
                        1 -> respond(
                            content = """
                            {
                                "data": {
                                    "lots": [{"id": "lot-1", "name": "Lot 1", "variety": "Merlot", "vintage": 2024, "updated_at": "2024-10-15T14:00:00Z"}],
                                    "vessels": [], "work_orders": [], "barrels": [], "raw_materials": []
                                },
                                "meta": {"synced_at": "2024-10-15T14:00:00Z", "has_more": true},
                                "errors": []
                            }
                            """,
                            status = HttpStatusCode.OK,
                            headers = jsonHeaders,
                        )
                        2 -> respond(
                            content = """
                            {
                                "data": {
                                    "lots": [{"id": "lot-2", "name": "Lot 2", "variety": "Pinot Noir", "vintage": 2024, "updated_at": "2024-10-15T14:01:00Z"}],
                                    "vessels": [], "work_orders": [], "barrels": [], "raw_materials": []
                                },
                                "meta": {"synced_at": "2024-10-15T14:01:00Z", "has_more": false},
                                "errors": []
                            }
                            """,
                            status = HttpStatusCode.OK,
                            headers = jsonHeaders,
                        )
                        else -> error("Too many pull requests")
                    }
                }
                else -> error("Unexpected request")
            }
        })

        val result = engine.sync()

        assertEquals(SyncResultStatus.SUCCESS, result.status)
        assertEquals(2, result.pull?.entitiesUpdated)

        // Both lots should be in local database
        val lots = database.localLotQueries.selectAll().executeAsList()
        assertEquals(2, lots.size)

        // Last sync should be from the final page
        val lastSync = database.syncStateQueries.selectByKey(SyncEngine.SYNC_STATE_LAST_SYNC).executeAsOneOrNull()
        assertEquals("2024-10-15T14:01:00Z", lastSync)
    }

    // ── Delta pull uses stored timestamp ─────────────────────────

    @Test
    fun pullSendsStoredSinceTimestamp() = runTest {
        // Pre-set a last sync timestamp
        database.syncStateQueries.upsert(
            key = SyncEngine.SYNC_STATE_LAST_SYNC,
            value_ = "2024-10-10T00:00:00Z",
        )

        var capturedSince: String? = null
        val engine = createSyncEngine(MockEngine { request ->
            when {
                request.url.encodedPath.contains("sync/pull") -> {
                    capturedSince = request.url.parameters["since"]
                    respond(
                        content = """
                        {
                            "data": {"lots": [], "vessels": [], "work_orders": [], "barrels": [], "raw_materials": []},
                            "meta": {"synced_at": "2024-10-15T14:00:00Z", "has_more": false},
                            "errors": []
                        }
                        """,
                        status = HttpStatusCode.OK,
                        headers = jsonHeaders,
                    )
                }
                else -> error("Unexpected")
            }
        })

        engine.sync()
        assertEquals("2024-10-10T00:00:00Z", capturedSince)
    }

    // ── State transitions ────────────────────────────────────────

    @Test
    fun stateReturnsToIdleAfterSuccess() = runTest {
        val engine = createSyncEngine(MockEngine {
            respond(
                content = """
                {
                    "data": {"lots": [], "vessels": [], "work_orders": [], "barrels": [], "raw_materials": []},
                    "meta": {"synced_at": "2024-10-15T14:00:00Z", "has_more": false},
                    "errors": []
                }
                """,
                status = HttpStatusCode.OK,
                headers = jsonHeaders,
            )
        })

        assertEquals(SyncState.IDLE, engine.state.value)
        engine.sync()
        assertEquals(SyncState.IDLE, engine.state.value)
    }

    // ── Idempotent push (skipped events) ─────────────────────────

    @Test
    fun pushHandlesSkippedDuplicates() = runTest {
        eventQueue.enqueue(createEvent("lot", "lot-1", "addition"))

        val engine = createSyncEngine(MockEngine { request ->
            when {
                request.url.encodedPath.contains("events/sync") -> respond(
                    content = """
                    {
                        "data": [{"index": 0, "event_id": "existing", "status": "skipped", "idempotency_key": "k1"}],
                        "meta": {"accepted": 0, "skipped": 1},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                request.url.encodedPath.contains("sync/pull") -> respond(
                    content = """
                    {
                        "data": {"lots": [], "vessels": [], "work_orders": [], "barrels": [], "raw_materials": []},
                        "meta": {"synced_at": "2024-10-15T14:00:00Z", "has_more": false},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                else -> error("Unexpected")
            }
        })

        val result = engine.sync()

        assertEquals(SyncResultStatus.SUCCESS, result.status)
        assertEquals(0, result.push?.accepted)
        assertEquals(1, result.push?.skipped)
        // Skipped events are still marked as synced (they already exist on the server)
        assertEquals(0, eventQueue.pendingCount())
    }

    // ── Offline scenario: 50 events → online → sync ────────────

    @Test
    fun fiftyEventsOfflineThenSyncAll() = runTest {
        // Simulate offline: enqueue 50 events
        repeat(50) { i ->
            eventQueue.enqueue(createEvent("lot", "lot-$i", "addition"))
        }
        assertEquals(50, eventQueue.pendingCount())

        var pushCallCount = 0
        val engine = createSyncEngine(MockEngine { request ->
            when {
                request.url.encodedPath.contains("events/sync") -> {
                    pushCallCount++
                    // Deserialize the request to get actual event count
                    val bodyText = String(request.body.toByteArray())
                    val pushRequest = kotlinx.serialization.json.Json {
                        ignoreUnknownKeys = true
                    }.decodeFromString(
                        com.vinesuite.shared.api.models.SyncPushRequest.serializer(),
                        bodyText,
                    )
                    val eventCount = pushRequest.events.size

                    // Build per-event accepted results from actual event data
                    val results = pushRequest.events.mapIndexed { i, event ->
                        """{"index": $i, "event_id": "srv-$pushCallCount-$i", "status": "accepted", "idempotency_key": "${event.idempotencyKey}"}"""
                    }.joinToString(",")

                    respond(
                        content = """{"data": [$results], "meta": {"accepted": $eventCount}, "errors": []}""",
                        status = HttpStatusCode.OK,
                        headers = jsonHeaders,
                    )
                }
                request.url.encodedPath.contains("sync/pull") -> respond(
                    content = """
                    {
                        "data": {"lots": [], "vessels": [], "work_orders": [], "barrels": [], "raw_materials": []},
                        "meta": {"synced_at": "2024-10-15T15:00:00Z", "has_more": false},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                else -> error("Unexpected: ${request.url}")
            }
        })

        val result = engine.sync()

        assertEquals(SyncResultStatus.SUCCESS, result.status)
        assertEquals(50, result.push?.accepted)
        assertEquals(0, eventQueue.pendingCount())
        // Should push in one batch (50 = DEFAULT_BATCH_SIZE)
        assertEquals(1, pushCallCount)
    }

    // ── Pull with all entity types ───────────────────────────────

    @Test
    fun pullUpdatesAllEntityTypes() = runTest {
        val engine = createSyncEngine(MockEngine { request ->
            when {
                request.url.encodedPath.contains("sync/pull") -> respond(
                    content = """
                    {
                        "data": {
                            "lots": [{"id": "lot-1", "name": "Cab Sauv", "variety": "Cabernet Sauvignon", "vintage": 2024, "source_type": "estate", "volume_gallons": 500.0, "status": "in_progress", "updated_at": "2024-10-15T14:00:00Z"}],
                            "vessels": [{"id": "v-1", "name": "Tank 1", "type": "tank", "capacity_gallons": 1000.0, "status": "in_use", "current_volume": 500.0, "updated_at": "2024-10-15T14:00:00Z"}],
                            "work_orders": [{"id": "wo-1", "operation_type": "racking", "status": "pending", "priority": "high", "due_date": "2024-10-20", "updated_at": "2024-10-15T14:00:00Z"}],
                            "barrels": [{"id": "b-1", "vessel_id": "v-1", "cooperage": "Seguin Moreau", "toast_level": "medium", "oak_type": "french", "volume_gallons": 59.43, "years_used": 2, "updated_at": "2024-10-15T14:00:00Z"}],
                            "raw_materials": [{"id": "rm-1", "name": "Potassium Metabisulfite", "category": "additive", "unit_of_measure": "g", "is_active": true, "updated_at": "2024-10-15T14:00:00Z"}]
                        },
                        "meta": {"synced_at": "2024-10-15T14:05:00Z", "has_more": false},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                else -> error("Unexpected")
            }
        })

        val result = engine.sync()

        assertEquals(SyncResultStatus.SUCCESS, result.status)
        assertEquals(5, result.pull?.entitiesUpdated)

        // Verify each entity type was persisted
        assertNotNull(database.localLotQueries.selectById("lot-1").executeAsOneOrNull())
        assertNotNull(database.localVesselQueries.selectById("v-1").executeAsOneOrNull())
        assertNotNull(database.localWorkOrderQueries.selectById("wo-1").executeAsOneOrNull())
        assertNotNull(database.localBarrelQueries.selectById("b-1").executeAsOneOrNull())
        assertNotNull(database.localAdditionProductQueries.selectById("rm-1").executeAsOneOrNull())

        // Verify work order details
        val wo = database.localWorkOrderQueries.selectById("wo-1").executeAsOne()
        assertEquals("racking", wo.operation_type)
        assertEquals("high", wo.priority)
        assertEquals("2024-10-20", wo.due_date)
    }

    // ── 401 during push clears auth ──────────────────────────────

    @Test
    fun pushWith401ClearsAuthAndFails() = runTest {
        eventQueue.enqueue(createEvent("lot", "lot-1", "addition"))

        val engine = createSyncEngine(MockEngine {
            respond(
                content = """{"data": null, "meta": {}, "errors": [{"message": "Unauthenticated."}]}""",
                status = HttpStatusCode.Unauthorized,
                headers = jsonHeaders,
            )
        })

        val result = engine.sync()

        assertEquals(SyncResultStatus.PUSH_FAILED, result.status)
        // Auth should be cleared
        assertFalse(authManager.isAuthenticated())
    }

    // ── Conflict integration ─────────────────────────────────────

    @Test
    fun failedDestructiveOpCreatesConflict() = runTest {
        // Enqueue a destructive transfer event
        eventQueue.enqueue(createEvent("vessel", "vessel-1", "transfer"))

        val engine = createSyncEngine(MockEngine { request ->
            when {
                request.url.encodedPath.contains("events/sync") -> respond(
                    content = """
                    {
                        "data": [{"index": 0, "event_id": null, "status": "failed", "idempotency_key": "k1", "error": "Insufficient volume: requested 300 gallons, only 150 available"}],
                        "meta": {"accepted": 0, "failed": 1},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                request.url.encodedPath.contains("sync/pull") -> respond(
                    content = """
                    {
                        "data": {"lots": [], "vessels": [], "work_orders": [], "barrels": [], "raw_materials": []},
                        "meta": {"synced_at": "2024-10-15T14:00:00Z", "has_more": false},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                else -> error("Unexpected")
            }
        })

        val result = engine.sync()

        assertEquals(SyncResultStatus.SUCCESS, result.status)
        assertEquals(1, result.push?.failed)

        // A conflict should have been created for the destructive transfer
        val conflicts = database.localConflictQueries.selectUnresolved().executeAsList()
        assertEquals(1, conflicts.size)
        assertEquals("vessel", conflicts.first().entity_type)
        assertEquals("vessel-1", conflicts.first().entity_id)
        assertEquals("transfer", conflicts.first().operation_type)
        assertTrue(conflicts.first().error_message.contains("Insufficient volume"))
    }

    @Test
    fun failedAdditiveOpDoesNotCreateConflict() = runTest {
        // Enqueue an additive event
        eventQueue.enqueue(createEvent("lot", "lot-1", "addition"))

        val engine = createSyncEngine(MockEngine { request ->
            when {
                request.url.encodedPath.contains("events/sync") -> respond(
                    content = """
                    {
                        "data": [{"index": 0, "event_id": null, "status": "failed", "idempotency_key": "k1", "error": "Validation error"}],
                        "meta": {"accepted": 0, "failed": 1},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                request.url.encodedPath.contains("sync/pull") -> respond(
                    content = """
                    {
                        "data": {"lots": [], "vessels": [], "work_orders": [], "barrels": [], "raw_materials": []},
                        "meta": {"synced_at": "2024-10-15T14:00:00Z", "has_more": false},
                        "errors": []
                    }
                    """,
                    status = HttpStatusCode.OK,
                    headers = jsonHeaders,
                )
                else -> error("Unexpected")
            }
        })

        val result = engine.sync()

        // Additive failure should NOT create a conflict
        val conflicts = database.localConflictQueries.selectUnresolved().executeAsList()
        assertEquals(0, conflicts.size)
        // But the event should still be marked as failed
        assertEquals(1, result.push?.failed)
    }

    // ── Helpers ──────────────────────────────────────────────────

    private fun createSyncEngine(mockEngine: MockEngine): SyncEngine {
        val conflictStore = ConflictStore(database, fixedClock)
        val conflictResolver = ConflictResolver(conflictStore)
        return SyncEngine(
            database = database,
            apiClient = createApiClient(mockEngine),
            eventQueue = eventQueue,
            conflictResolver = conflictResolver,
            connectivityMonitor = JvmConnectivityMonitor(),
        )
    }

    private fun createApiClient(mockEngine: MockEngine): ApiClient {
        val httpClient = HttpClient(mockEngine) {
            install(ContentNegotiation) {
                json(kotlinx.serialization.json.Json {
                    ignoreUnknownKeys = true
                    isLenient = true
                    encodeDefaults = true
                })
            }
            defaultRequest {
                contentType(ContentType.Application.Json)
            }
        }
        return ApiClient(
            baseUrl = "https://api.vinesuite.com/api/v1",
            authManager = authManager,
            httpClient = httpClient,
        )
    }

    private fun createEvent(entityType: String, entityId: String, operationType: String): SyncEvent {
        return eventFactory.create(
            entityType = entityType,
            entityId = entityId,
            operationType = operationType,
            payload = buildJsonObject { put("test", true) },
        )
    }
}
