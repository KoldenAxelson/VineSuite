package com.vinesuite.shared.sync

import app.cash.sqldelight.driver.jdbc.sqlite.JdbcSqliteDriver
import com.vinesuite.shared.api.models.SyncPushResult
import com.vinesuite.shared.database.OutboxEvent
import com.vinesuite.shared.database.VineSuiteDatabase
import kotlinx.datetime.Clock
import kotlinx.datetime.Instant
import kotlin.test.AfterTest
import kotlin.test.BeforeTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

class ConflictResolverTest {

    private lateinit var driver: JdbcSqliteDriver
    private lateinit var database: VineSuiteDatabase
    private lateinit var conflictStore: ConflictStore
    private lateinit var conflictResolver: ConflictResolver
    private lateinit var eventFactory: EventFactory
    private lateinit var eventQueue: EventQueue

    private val fixedClock = object : Clock {
        override fun now(): Instant = Instant.parse("2024-10-15T14:00:00Z")
    }

    @BeforeTest
    fun setup() {
        driver = JdbcSqliteDriver(JdbcSqliteDriver.IN_MEMORY)
        VineSuiteDatabase.Schema.create(driver)
        database = VineSuiteDatabase(driver)
        conflictStore = ConflictStore(database, fixedClock)
        conflictResolver = ConflictResolver(conflictStore)
        eventFactory = EventFactory(deviceId = "device-1", userId = "user-1", clock = fixedClock)
        eventQueue = EventQueue(database, eventFactory)
    }

    @AfterTest
    fun teardown() {
        driver.close()
    }

    // ── Operation classification ─────────────────────────────────

    @Test
    fun additionIsAdditive() {
        assertTrue(conflictResolver.isAdditive("addition"))
        assertTrue(conflictResolver.isAdditive("lab_analysis"))
        assertTrue(conflictResolver.isAdditive("fermentation_reading"))
        assertTrue(conflictResolver.isAdditive("sensory_note"))
    }

    @Test
    fun transferIsDestructive() {
        assertTrue(conflictResolver.isDestructive("transfer"))
        assertTrue(conflictResolver.isDestructive("blend"))
        assertTrue(conflictResolver.isDestructive("rack"))
        assertTrue(conflictResolver.isDestructive("bottling"))
        assertTrue(conflictResolver.isDestructive("pressing"))
    }

    @Test
    fun unknownOperationIsNeitherAdditiveNorDestructive() {
        assertFalse(conflictResolver.isAdditive("unknown_op"))
        assertFalse(conflictResolver.isDestructive("unknown_op"))
    }

    // ── Additive operations: no conflicts ────────────────────────

    @Test
    fun additiveOperationFailureDoesNotCreateConflict() {
        val event = enqueueAndFetch("lot", "lot-1", "addition")
        val pushResult = SyncPushResult(index = 0, eventId = null, status = "failed", idempotencyKey = "k1", error = "Some error")

        val conflictCreated = conflictResolver.processPushResult(event, pushResult)

        assertFalse(conflictCreated)
        assertEquals(0, conflictStore.unresolvedCount())
    }

    @Test
    fun additiveOperationAcceptedDoesNotCreateConflict() {
        val event = enqueueAndFetch("lot", "lot-1", "addition")
        val pushResult = SyncPushResult(index = 0, eventId = "srv-1", status = "accepted", idempotencyKey = "k1")

        val conflictCreated = conflictResolver.processPushResult(event, pushResult)

        assertFalse(conflictCreated)
        assertEquals(0, conflictStore.unresolvedCount())
    }

    // ── Destructive operations: create conflicts on failure ──────

    @Test
    fun destructiveOperationFailureCreatesConflict() {
        val event = enqueueAndFetch("vessel", "vessel-1", "transfer")
        val pushResult = SyncPushResult(
            index = 0, eventId = null, status = "failed",
            idempotencyKey = "k1", error = "Insufficient volume: requested 300 gallons, only 150 available"
        )
        val serverState = """{"vessel_id": "vessel-1", "current_volume": 150.0}"""

        val conflictCreated = conflictResolver.processPushResult(event, pushResult, serverState)

        assertTrue(conflictCreated)
        assertEquals(1, conflictStore.unresolvedCount())

        val conflict = conflictStore.getUnresolved().first()
        assertEquals("vessel", conflict.entity_type)
        assertEquals("vessel-1", conflict.entity_id)
        assertEquals("transfer", conflict.operation_type)
        assertEquals("Insufficient volume: requested 300 gallons, only 150 available", conflict.error_message)
        assertEquals(serverState, conflict.server_state)
        assertEquals("unresolved", conflict.status)
    }

    @Test
    fun destructiveOperationAcceptedDoesNotCreateConflict() {
        val event = enqueueAndFetch("vessel", "vessel-1", "transfer")
        val pushResult = SyncPushResult(index = 0, eventId = "srv-1", status = "accepted", idempotencyKey = "k1")

        val conflictCreated = conflictResolver.processPushResult(event, pushResult)

        assertFalse(conflictCreated)
        assertEquals(0, conflictStore.unresolvedCount())
    }

    // ── Batch processing ─────────────────────────────────────────

    @Test
    fun batchWithMixedResults() {
        val event1 = enqueueAndFetch("lot", "lot-1", "addition")
        val event2 = enqueueAndFetch("vessel", "vessel-1", "transfer")
        val event3 = enqueueAndFetch("vessel", "vessel-2", "blend")
        val batch = listOf(event1, event2, event3)

        val results = listOf(
            SyncPushResult(index = 0, eventId = "srv-1", status = "accepted", idempotencyKey = "k1"),
            SyncPushResult(index = 1, eventId = null, status = "failed", idempotencyKey = "k2", error = "Volume conflict"),
            SyncPushResult(index = 2, eventId = "srv-3", status = "accepted", idempotencyKey = "k3"),
        )

        val conflictsCreated = conflictResolver.processBatchResults(batch, results)

        assertEquals(1, conflictsCreated)
        assertEquals(1, conflictStore.unresolvedCount())
        assertEquals("vessel-1", conflictStore.getUnresolved().first().entity_id)
    }

    // ── ConflictStore: resolve and dismiss ───────────────────────

    @Test
    fun resolveConflict() {
        val event = enqueueAndFetch("vessel", "vessel-1", "transfer")
        val pushResult = SyncPushResult(index = 0, eventId = null, status = "failed", idempotencyKey = "k1", error = "Volume conflict")
        conflictResolver.processPushResult(event, pushResult)

        val conflict = conflictStore.getUnresolved().first()
        conflictStore.resolve(conflict.id)

        assertEquals(0, conflictStore.unresolvedCount())
        val resolved = conflictStore.getById(conflict.id)
        assertNotNull(resolved)
        assertEquals("resolved", resolved.status)
        assertNotNull(resolved.resolved_at)
    }

    @Test
    fun dismissConflict() {
        val event = enqueueAndFetch("vessel", "vessel-1", "transfer")
        val pushResult = SyncPushResult(index = 0, eventId = null, status = "failed", idempotencyKey = "k1", error = "Volume conflict")
        conflictResolver.processPushResult(event, pushResult)

        val conflict = conflictStore.getUnresolved().first()
        conflictStore.dismiss(conflict.id)

        assertEquals(0, conflictStore.unresolvedCount())
        val dismissed = conflictStore.getById(conflict.id)
        assertNotNull(dismissed)
        assertEquals("dismissed", dismissed.status)
    }

    // ── ConflictStore: entity lookup ─────────────────────────────

    @Test
    fun getConflictsByEntity() {
        // Create conflicts for two different vessels
        val event1 = enqueueAndFetch("vessel", "vessel-1", "transfer")
        val event2 = enqueueAndFetch("vessel", "vessel-2", "transfer")
        conflictResolver.processPushResult(event1, failedResult(0))
        conflictResolver.processPushResult(event2, failedResult(1))

        val vessel1Conflicts = conflictStore.getByEntity("vessel", "vessel-1")
        assertEquals(1, vessel1Conflicts.size)
        assertEquals("vessel-1", vessel1Conflicts.first().entity_id)
    }

    // ── ConflictStore: purge resolved ────────────────────────────

    @Test
    fun purgeResolvedKeepsUnresolved() {
        val event1 = enqueueAndFetch("vessel", "vessel-1", "transfer")
        val event2 = enqueueAndFetch("vessel", "vessel-2", "transfer")
        conflictResolver.processPushResult(event1, failedResult(0))
        conflictResolver.processPushResult(event2, failedResult(1))

        // Resolve one, leave the other
        val conflicts = conflictStore.getUnresolved()
        conflictStore.resolve(conflicts[0].id)

        conflictStore.purgeResolved()

        assertEquals(1, conflictStore.unresolvedCount())
        // The resolved one should be gone
        assertNull(conflictStore.getById(conflicts[0].id))
        // The unresolved one should remain
        assertNotNull(conflictStore.getById(conflicts[1].id))
    }

    // ── ConflictStore: stores full context ───────────────────────

    @Test
    fun conflictStoresAttemptedPayloadAndServerState() {
        val event = enqueueAndFetch("vessel", "vessel-1", "transfer")
        val serverState = """{"vessel_id": "vessel-1", "current_volume": 50.0, "lot_id": "lot-1"}"""

        conflictResolver.processPushResult(
            outboxEvent = event,
            pushResult = SyncPushResult(index = 0, eventId = null, status = "failed", idempotencyKey = "k1", error = "Only 50 gallons available"),
            serverState = serverState,
        )

        val conflict = conflictStore.getUnresolved().first()
        // Attempted payload should be the original event payload
        assertEquals(event.payload, conflict.attempted_payload)
        // Server state should be the state at rejection time
        assertEquals(serverState, conflict.server_state)
        // Error should be the server's message
        assertEquals("Only 50 gallons available", conflict.error_message)
        // Outbox event ID should link back to the original event
        assertEquals(event.id, conflict.outbox_event_id)
    }

    // ── Multiple conflicts for same entity ───────────────────────

    @Test
    fun multipleConflictsForSameEntity() {
        val event1 = enqueueAndFetch("vessel", "vessel-1", "transfer")
        val event2 = enqueueAndFetch("vessel", "vessel-1", "rack")
        conflictResolver.processPushResult(event1, failedResult(0, "Transfer conflict"))
        conflictResolver.processPushResult(event2, failedResult(1, "Rack conflict"))

        assertEquals(2, conflictStore.unresolvedCount())
        val conflicts = conflictStore.getByEntity("vessel", "vessel-1")
        assertEquals(2, conflicts.size)
    }

    // ── Helpers ──────────────────────────────────────────────────

    private fun enqueueAndFetch(entityType: String, entityId: String, operationType: String): OutboxEvent {
        val syncEvent = eventFactory.create(
            entityType = entityType,
            entityId = entityId,
            operationType = operationType,
            payload = kotlinx.serialization.json.buildJsonObject {
                put("volume", kotlinx.serialization.json.JsonPrimitive(300.0))
            },
        )
        val eventId = eventQueue.enqueue(syncEvent)
        return database.outboxEventQueries.selectById(eventId).executeAsOne()
    }

    private fun failedResult(index: Int, error: String = "Server rejected"): SyncPushResult {
        return SyncPushResult(
            index = index,
            eventId = null,
            status = "failed",
            idempotencyKey = "k-$index",
            error = error,
        )
    }
}
