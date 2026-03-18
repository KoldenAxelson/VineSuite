package com.vinesuite.shared.sync

import app.cash.sqldelight.driver.jdbc.sqlite.JdbcSqliteDriver
import com.vinesuite.shared.database.VineSuiteDatabase
import com.vinesuite.shared.models.SyncEvent
import kotlinx.datetime.Clock
import kotlinx.datetime.Instant
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlin.test.AfterTest
import kotlin.test.BeforeTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNotEquals
import kotlin.test.assertNotNull
import kotlin.test.assertTrue

/**
 * Tests for EventQueue and EventFactory.
 * Validates the full outbox lifecycle: enqueue → pending → sync → purge.
 */
class EventQueueTest {

    private lateinit var driver: JdbcSqliteDriver
    private lateinit var database: VineSuiteDatabase
    private lateinit var eventFactory: EventFactory
    private lateinit var eventQueue: EventQueue

    /** Fixed clock for deterministic timestamps in tests. */
    private val fixedInstant = Instant.parse("2024-10-15T14:00:00Z")
    private val fixedClock = object : Clock {
        override fun now(): Instant = fixedInstant
    }

    @BeforeTest
    fun setup() {
        driver = JdbcSqliteDriver(JdbcSqliteDriver.IN_MEMORY)
        VineSuiteDatabase.Schema.create(driver)
        database = VineSuiteDatabase(driver)
        eventFactory = EventFactory(
            deviceId = "test-device-001",
            userId = "user-123",
            clock = fixedClock,
        )
        eventQueue = EventQueue(database, eventFactory)
    }

    @AfterTest
    fun teardown() {
        driver.close()
    }

    // ── EventFactory ─────────────────────────────────────────────

    @Test
    fun factoryCreatesEventWithUniqueIdempotencyKey() {
        val payload = buildJsonObject { put("amount", 25.0) }
        val event1 = eventFactory.create("lot", "lot-1", "addition", payload)
        val event2 = eventFactory.create("lot", "lot-1", "addition", payload)

        assertNotEquals(event1.idempotencyKey, event2.idempotencyKey)
    }

    @Test
    fun factoryUsesProvidedUserAndDevice() {
        val event = eventFactory.create(
            entityType = "vessel",
            entityId = "vessel-1",
            operationType = "transfer",
            payload = buildJsonObject { put("volume", 100) },
        )
        assertEquals("user-123", event.performedBy)
        assertEquals("test-device-001", event.deviceId)
    }

    @Test
    fun factoryUsesFixedClockTimestamp() {
        val event = eventFactory.create(
            entityType = "lot",
            entityId = "lot-1",
            operationType = "addition",
            payload = JsonObject(emptyMap()),
        )
        assertEquals("2024-10-15T14:00:00Z", event.performedAt)
    }

    @Test
    fun factoryAcceptsOverrideTimestamp() {
        val override = Instant.parse("2024-09-01T08:30:00Z")
        val event = eventFactory.create(
            entityType = "lot",
            entityId = "lot-1",
            operationType = "addition",
            payload = JsonObject(emptyMap()),
            performedAt = override,
        )
        assertEquals("2024-09-01T08:30:00Z", event.performedAt)
    }

    @Test
    fun factoryGeneratesUniqueEventIds() {
        val id1 = eventFactory.generateEventId()
        val id2 = eventFactory.generateEventId()
        assertNotEquals(id1, id2)
        assertTrue(id1.isNotBlank())
    }

    // ── EventQueue: enqueue ──────────────────────────────────────

    @Test
    fun enqueueWritesToOutbox() {
        val event = createTestEvent("lot", "lot-1", "addition")
        val eventId = eventQueue.enqueue(event)

        assertNotNull(eventId)
        val pending = eventQueue.getPendingEvents()
        assertEquals(1, pending.size)
        assertEquals(eventId, pending.first().id)
    }

    @Test
    fun enqueuePreservesAllFields() {
        val payload = buildJsonObject {
            put("amount", 25.5)
            put("unit", "g")
            put("product_id", "ap-1")
        }
        val event = eventFactory.create("lot", "lot-1", "addition", payload)
        val eventId = eventQueue.enqueue(event)

        val stored = database.outboxEventQueries.selectById(eventId).executeAsOne()
        assertEquals("lot", stored.entity_type)
        assertEquals("lot-1", stored.entity_id)
        assertEquals("addition", stored.operation_type)
        assertEquals("user-123", stored.performed_by)
        assertEquals("test-device-001", stored.device_id)
        assertEquals(event.idempotencyKey, stored.idempotency_key)
        assertEquals(0L, stored.synced)
        assertEquals(0L, stored.retry_count)
        // Payload is serialized JSON
        assertTrue(stored.payload.contains("25.5"))
    }

    @Test
    fun enqueueMultipleEventsPreservesOrder() {
        // Events with different timestamps
        val factory1 = EventFactory("dev", "user-1", object : Clock {
            override fun now() = Instant.parse("2024-10-15T14:00:00Z")
        })
        val factory2 = EventFactory("dev", "user-1", object : Clock {
            override fun now() = Instant.parse("2024-10-15T14:01:00Z")
        })
        val factory3 = EventFactory("dev", "user-1", object : Clock {
            override fun now() = Instant.parse("2024-10-15T14:02:00Z")
        })

        val queue = EventQueue(database, factory1)
        queue.enqueue(factory1.create("lot", "lot-1", "addition", JsonObject(emptyMap())))
        // Use different factories just for different timestamps, still enqueue through same queue
        val queue2 = EventQueue(database, factory2)
        queue2.enqueue(factory2.create("lot", "lot-1", "racking", JsonObject(emptyMap())))
        val queue3 = EventQueue(database, factory3)
        queue3.enqueue(factory3.create("lot", "lot-1", "transfer", JsonObject(emptyMap())))

        val pending = eventQueue.getPendingEvents()
        assertEquals(3, pending.size)
        assertEquals("addition", pending[0].operation_type)
        assertEquals("racking", pending[1].operation_type)
        assertEquals("transfer", pending[2].operation_type)
    }

    // ── EventQueue: pending count ────────────────────────────────

    @Test
    fun pendingCountReflectsUnsyncedEvents() {
        assertEquals(0, eventQueue.pendingCount())

        eventQueue.enqueue(createTestEvent("lot", "lot-1", "addition"))
        eventQueue.enqueue(createTestEvent("lot", "lot-2", "transfer"))
        assertEquals(2, eventQueue.pendingCount())
    }

    // ── EventQueue: batch retrieval ──────────────────────────────

    @Test
    fun getPendingBatchRespectsLimit() {
        repeat(10) { i ->
            eventQueue.enqueue(createTestEvent("lot", "lot-$i", "addition"))
        }
        val batch = eventQueue.getPendingBatch(3)
        assertEquals(3, batch.size)
    }

    // ── EventQueue: mark synced ──────────────────────────────────

    @Test
    fun markSyncedRemovesFromPending() {
        val id1 = eventQueue.enqueue(createTestEvent("lot", "lot-1", "addition"))
        val id2 = eventQueue.enqueue(createTestEvent("lot", "lot-2", "transfer"))

        eventQueue.markSynced(id1)

        val pending = eventQueue.getPendingEvents()
        assertEquals(1, pending.size)
        assertEquals(id2, pending.first().id)
        assertEquals(1, eventQueue.pendingCount())
    }

    @Test
    fun markSyncedBatchRemovesMultiple() {
        val id1 = eventQueue.enqueue(createTestEvent("lot", "lot-1", "addition"))
        val id2 = eventQueue.enqueue(createTestEvent("lot", "lot-2", "transfer"))
        val id3 = eventQueue.enqueue(createTestEvent("lot", "lot-3", "racking"))

        eventQueue.markSyncedBatch(listOf(id1, id3))

        val pending = eventQueue.getPendingEvents()
        assertEquals(1, pending.size)
        assertEquals(id2, pending.first().id)
    }

    // ── EventQueue: retry tracking ───────────────────────────────

    @Test
    fun recordFailureIncrementsRetryCount() {
        val id = eventQueue.enqueue(createTestEvent("lot", "lot-1", "addition"))

        eventQueue.recordFailure(id, "Network timeout")
        eventQueue.recordFailure(id, "Server 500")

        val event = database.outboxEventQueries.selectById(id).executeAsOne()
        assertEquals(2L, event.retry_count)
        assertEquals("Server 500", event.last_error)
    }

    @Test
    fun failedEventsStillInPending() {
        val id = eventQueue.enqueue(createTestEvent("lot", "lot-1", "addition"))
        eventQueue.recordFailure(id, "Network error")

        // Failed but not synced — still shows in pending
        val pending = eventQueue.getPendingEvents()
        assertEquals(1, pending.size)
    }

    @Test
    fun getRetryableExcludesPermanentlyFailed() {
        val id1 = eventQueue.enqueue(createTestEvent("lot", "lot-1", "addition"))
        val id2 = eventQueue.enqueue(createTestEvent("lot", "lot-2", "transfer"))

        // id1 fails 3 times — still retryable
        repeat(3) { eventQueue.recordFailure(id1, "error $it") }

        // id2 fails 5 times — permanently failed
        repeat(5) { eventQueue.recordFailure(id2, "error $it") }

        val retryable = eventQueue.getRetryable()
        assertEquals(1, retryable.size)
        assertEquals(id1, retryable.first().id)
    }

    @Test
    fun getPermanentlyFailedReturnsMaxRetryEvents() {
        val id1 = eventQueue.enqueue(createTestEvent("lot", "lot-1", "addition"))
        val id2 = eventQueue.enqueue(createTestEvent("lot", "lot-2", "transfer"))

        repeat(5) { eventQueue.recordFailure(id1, "error") }
        repeat(3) { eventQueue.recordFailure(id2, "error") }

        val failed = eventQueue.getPermanentlyFailed()
        assertEquals(1, failed.size)
        assertEquals(id1, failed.first().id)
    }

    @Test
    fun resetRetryAllowsReprocessing() {
        val id = eventQueue.enqueue(createTestEvent("lot", "lot-1", "addition"))
        repeat(5) { eventQueue.recordFailure(id, "error") }

        assertEquals(1, eventQueue.getPermanentlyFailed().size)

        eventQueue.resetRetry(id)

        assertEquals(0, eventQueue.getPermanentlyFailed().size)
        val retryable = eventQueue.getRetryable()
        // After reset, retry_count is 0 and event has no failures, so getRetryable
        // (which filters retry_count > 0 from selectFailed) won't include it.
        // But it IS in getPendingEvents since synced = 0.
        assertEquals(1, eventQueue.getPendingEvents().size)
    }

    // ── EventQueue: purge ────────────────────────────────────────

    @Test
    fun purgeSyncedEventsDeletesOldSynced() {
        val id1 = eventQueue.enqueue(createTestEvent("lot", "lot-1", "addition"))
        val id2 = eventQueue.enqueue(createTestEvent("lot", "lot-2", "transfer"))

        // Sync both
        eventQueue.markSynced(id1)
        eventQueue.markSynced(id2)

        // Purge events created before a future cutoff
        eventQueue.purgeSyncedEvents("2025-01-01T00:00:00Z")

        val all = database.outboxEventQueries.selectAll().executeAsList()
        assertEquals(0, all.size)
    }

    @Test
    fun purgeSyncedEventsKeepsUnsynced() {
        val id1 = eventQueue.enqueue(createTestEvent("lot", "lot-1", "addition"))
        eventQueue.enqueue(createTestEvent("lot", "lot-2", "transfer"))

        // Only sync first
        eventQueue.markSynced(id1)

        // Purge all synced
        eventQueue.purgeSyncedEvents("2025-01-01T00:00:00Z")

        val all = database.outboxEventQueries.selectAll().executeAsList()
        assertEquals(1, all.size) // unsynced event remains
    }

    // ── EventQueue: round-trip serialization ─────────────────────

    @Test
    fun toSyncEventReconstructsFromOutbox() {
        val payload = buildJsonObject {
            put("amount", 25.5)
            put("unit", "g")
        }
        val original = eventFactory.create("lot", "lot-1", "addition", payload)
        val eventId = eventQueue.enqueue(original)

        val stored = database.outboxEventQueries.selectById(eventId).executeAsOne()
        val reconstructed = eventQueue.toSyncEvent(stored)

        assertEquals(original.entityType, reconstructed.entityType)
        assertEquals(original.entityId, reconstructed.entityId)
        assertEquals(original.operationType, reconstructed.operationType)
        assertEquals(original.performedBy, reconstructed.performedBy)
        assertEquals(original.performedAt, reconstructed.performedAt)
        assertEquals(original.deviceId, reconstructed.deviceId)
        assertEquals(original.idempotencyKey, reconstructed.idempotencyKey)
        // Payload round-trips through JSON serialization
        assertEquals(
            original.payload["amount"].toString(),
            reconstructed.payload["amount"].toString()
        )
    }

    // ── Bulk: offline scenario ───────────────────────────────────

    @Test
    fun bulkEnqueueAndSyncFiftyEvents() {
        // Simulate offline: enqueue 50 events
        val ids = (1..50).map { i ->
            eventQueue.enqueue(createTestEvent("lot", "lot-$i", "addition"))
        }

        assertEquals(50, eventQueue.pendingCount())

        // Simulate coming online: batch sync
        val batch1 = eventQueue.getPendingBatch(25)
        assertEquals(25, batch1.size)
        eventQueue.markSyncedBatch(batch1.map { it.id })

        assertEquals(25, eventQueue.pendingCount())

        val batch2 = eventQueue.getPendingBatch(25)
        assertEquals(25, batch2.size)
        eventQueue.markSyncedBatch(batch2.map { it.id })

        assertEquals(0, eventQueue.pendingCount())
    }

    // ── Helpers ──────────────────────────────────────────────────

    private fun createTestEvent(
        entityType: String,
        entityId: String,
        operationType: String,
    ): SyncEvent {
        return eventFactory.create(
            entityType = entityType,
            entityId = entityId,
            operationType = operationType,
            payload = buildJsonObject { put("test", true) },
        )
    }
}
