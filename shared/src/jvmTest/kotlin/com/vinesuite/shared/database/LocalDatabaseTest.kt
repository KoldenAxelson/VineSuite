package com.vinesuite.shared.database

import app.cash.sqldelight.driver.jdbc.sqlite.JdbcSqliteDriver
import kotlin.test.AfterTest
import kotlin.test.BeforeTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * Comprehensive JVM tests for all SQLDelight local tables.
 * Uses in-memory SQLite — fast, no filesystem, no emulator.
 */
class LocalDatabaseTest {

    private lateinit var driver: JdbcSqliteDriver
    private lateinit var database: VineSuiteDatabase

    @BeforeTest
    fun setup() {
        driver = JdbcSqliteDriver(JdbcSqliteDriver.IN_MEMORY)
        VineSuiteDatabase.Schema.create(driver)
        database = VineSuiteDatabase(driver)
    }

    @AfterTest
    fun teardown() {
        driver.close()
    }

    // ── LocalLot ─────────────────────────────────────────────────

    @Test
    fun lotInsertAndSelectById() {
        database.localLotQueries.insert(
            id = "lot-1", name = "Cab Sauv Block A", variety = "Cabernet Sauvignon",
            vintage = 2024, source_type = "estate", volume_gallons = 500.0,
            status = "in_progress", parent_lot_id = null, updated_at = "2024-10-01T10:00:00Z"
        )
        val lot = database.localLotQueries.selectById("lot-1").executeAsOneOrNull()
        assertNotNull(lot)
        assertEquals("Cab Sauv Block A", lot.name)
        assertEquals("Cabernet Sauvignon", lot.variety)
        assertEquals(2024, lot.vintage)
        assertEquals(500.0, lot.volume_gallons)
    }

    @Test
    fun lotSelectByStatus() {
        database.localLotQueries.insert(
            id = "lot-1", name = "Lot A", variety = "Merlot", vintage = 2024,
            source_type = "estate", volume_gallons = 100.0, status = "in_progress",
            parent_lot_id = null, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localLotQueries.insert(
            id = "lot-2", name = "Lot B", variety = "Merlot", vintage = 2024,
            source_type = "estate", volume_gallons = 200.0, status = "aging",
            parent_lot_id = null, updated_at = "2024-10-01T10:00:00Z"
        )
        val inProgress = database.localLotQueries.selectByStatus("in_progress").executeAsList()
        assertEquals(1, inProgress.size)
        assertEquals("lot-1", inProgress.first().id)
    }

    @Test
    fun lotUpdateStatus() {
        database.localLotQueries.insert(
            id = "lot-1", name = "Lot A", variety = "Pinot Noir", vintage = 2024,
            source_type = "estate", volume_gallons = 300.0, status = "in_progress",
            parent_lot_id = null, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localLotQueries.updateStatus(
            status = "aging", updated_at = "2024-11-01T10:00:00Z", id = "lot-1"
        )
        val lot = database.localLotQueries.selectById("lot-1").executeAsOne()
        assertEquals("aging", lot.status)
        assertEquals("2024-11-01T10:00:00Z", lot.updated_at)
    }

    @Test
    fun lotUpdateVolume() {
        database.localLotQueries.insert(
            id = "lot-1", name = "Lot A", variety = "Chardonnay", vintage = 2024,
            source_type = "estate", volume_gallons = 500.0, status = "in_progress",
            parent_lot_id = null, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localLotQueries.updateVolume(
            volume_gallons = 450.0, updated_at = "2024-10-15T10:00:00Z", id = "lot-1"
        )
        val lot = database.localLotQueries.selectById("lot-1").executeAsOne()
        assertEquals(450.0, lot.volume_gallons)
    }

    @Test
    fun lotSelectByVarietyAndVintage() {
        database.localLotQueries.insert(
            id = "lot-1", name = "Lot A", variety = "Zinfandel", vintage = 2023,
            source_type = "estate", volume_gallons = 100.0, status = "in_progress",
            parent_lot_id = null, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localLotQueries.insert(
            id = "lot-2", name = "Lot B", variety = "Zinfandel", vintage = 2024,
            source_type = "estate", volume_gallons = 200.0, status = "in_progress",
            parent_lot_id = null, updated_at = "2024-10-01T10:00:00Z"
        )
        val results = database.localLotQueries.selectByVarietyAndVintage("Zinfandel", 2024).executeAsList()
        assertEquals(1, results.size)
        assertEquals("lot-2", results.first().id)
    }

    @Test
    fun lotDeleteById() {
        database.localLotQueries.insert(
            id = "lot-1", name = "Lot A", variety = "Merlot", vintage = 2024,
            source_type = "estate", volume_gallons = 100.0, status = "in_progress",
            parent_lot_id = null, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localLotQueries.deleteById("lot-1")
        assertNull(database.localLotQueries.selectById("lot-1").executeAsOneOrNull())
    }

    // ── LocalVessel ──────────────────────────────────────────────

    @Test
    fun vesselInsertAndSelectById() {
        database.localVesselQueries.insert(
            id = "vessel-1", name = "Tank 1", type = "tank", capacity_gallons = 1000.0,
            material = "stainless", location = "Cellar A", status = "empty",
            current_lot_id = null, current_volume = 0.0, updated_at = "2024-10-01T10:00:00Z"
        )
        val vessel = database.localVesselQueries.selectById("vessel-1").executeAsOneOrNull()
        assertNotNull(vessel)
        assertEquals("Tank 1", vessel.name)
        assertEquals("tank", vessel.type)
        assertEquals(1000.0, vessel.capacity_gallons)
    }

    @Test
    fun vesselSelectAvailable() {
        database.localVesselQueries.insert(
            id = "vessel-1", name = "Tank 1", type = "tank", capacity_gallons = 1000.0,
            material = null, location = null, status = "empty",
            current_lot_id = null, current_volume = 0.0, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localVesselQueries.insert(
            id = "vessel-2", name = "Tank 2", type = "tank", capacity_gallons = 500.0,
            material = null, location = null, status = "in_use",
            current_lot_id = "lot-1", current_volume = 400.0, updated_at = "2024-10-01T10:00:00Z"
        )
        val available = database.localVesselQueries.selectAvailable().executeAsList()
        assertEquals(1, available.size)
        assertEquals("vessel-1", available.first().id)
    }

    @Test
    fun vesselUpdateVolume() {
        database.localVesselQueries.insert(
            id = "vessel-1", name = "Tank 1", type = "tank", capacity_gallons = 1000.0,
            material = null, location = null, status = "empty",
            current_lot_id = null, current_volume = 0.0, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localVesselQueries.updateVolume(
            current_volume = 800.0, current_lot_id = "lot-1",
            updated_at = "2024-10-15T10:00:00Z", id = "vessel-1"
        )
        val vessel = database.localVesselQueries.selectById("vessel-1").executeAsOne()
        assertEquals(800.0, vessel.current_volume)
        assertEquals("lot-1", vessel.current_lot_id)
    }

    // ── LocalBarrel ──────────────────────────────────────────────

    @Test
    fun barrelInsertAndSelectByVessel() {
        // Barrel requires a parent vessel
        database.localVesselQueries.insert(
            id = "vessel-b1", name = "Barrel 1", type = "barrel", capacity_gallons = 59.43,
            material = "oak", location = "Barrel Hall", status = "in_use",
            current_lot_id = "lot-1", current_volume = 59.0, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localBarrelQueries.insert(
            id = "barrel-1", vessel_id = "vessel-b1", cooperage = "Seguin Moreau",
            toast_level = "medium", oak_type = "french", forest_origin = "Allier",
            volume_gallons = 59.43, years_used = 2, qr_code = "QR-001",
            updated_at = "2024-10-01T10:00:00Z"
        )
        val barrel = database.localBarrelQueries.selectByVesselId("vessel-b1").executeAsOneOrNull()
        assertNotNull(barrel)
        assertEquals("Seguin Moreau", barrel.cooperage)
        assertEquals("french", barrel.oak_type)
        assertEquals(2, barrel.years_used)
    }

    @Test
    fun barrelSelectByQrCode() {
        database.localVesselQueries.insert(
            id = "vessel-b1", name = "Barrel 1", type = "barrel", capacity_gallons = 59.43,
            material = null, location = null, status = "in_use",
            current_lot_id = null, current_volume = 0.0, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localBarrelQueries.insert(
            id = "barrel-1", vessel_id = "vessel-b1", cooperage = "Tonnellerie",
            toast_level = "heavy", oak_type = "american", forest_origin = null,
            volume_gallons = 59.43, years_used = 0, qr_code = "QR-SCAN-123",
            updated_at = "2024-10-01T10:00:00Z"
        )
        val result = database.localBarrelQueries.selectByQrCode("QR-SCAN-123").executeAsOneOrNull()
        assertNotNull(result)
        assertEquals("barrel-1", result.id)
    }

    // ── LocalWorkOrder ───────────────────────────────────────────

    @Test
    fun workOrderInsertAndSelectPending() {
        database.localWorkOrderQueries.insert(
            id = "wo-1", operation_type = "racking", lot_id = "lot-1",
            vessel_id = "vessel-1", assigned_to = "user-1", due_date = "2024-10-15",
            status = "pending", priority = "high", notes = "Rack off lees",
            completed_at = null, completed_by = null, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localWorkOrderQueries.insert(
            id = "wo-2", operation_type = "addition", lot_id = "lot-1",
            vessel_id = null, assigned_to = "user-1", due_date = "2024-10-20",
            status = "completed", priority = "normal", notes = null,
            completed_at = "2024-10-18T14:00:00Z", completed_by = "user-1",
            updated_at = "2024-10-18T14:00:00Z"
        )
        val pending = database.localWorkOrderQueries.selectPending().executeAsList()
        assertEquals(1, pending.size)
        assertEquals("wo-1", pending.first().id)
    }

    @Test
    fun workOrderMarkCompleted() {
        database.localWorkOrderQueries.insert(
            id = "wo-1", operation_type = "racking", lot_id = "lot-1",
            vessel_id = null, assigned_to = "user-1", due_date = "2024-10-15",
            status = "pending", priority = "normal", notes = null,
            completed_at = null, completed_by = null, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localWorkOrderQueries.markCompleted(
            completed_at = "2024-10-15T16:30:00Z", completed_by = "user-1",
            updated_at = "2024-10-15T16:30:00Z", id = "wo-1"
        )
        val wo = database.localWorkOrderQueries.selectById("wo-1").executeAsOne()
        assertEquals("completed", wo.status)
        assertEquals("user-1", wo.completed_by)
    }

    @Test
    fun workOrderPriorityOrdering() {
        database.localWorkOrderQueries.insert(
            id = "wo-low", operation_type = "cleaning", lot_id = null,
            vessel_id = null, assigned_to = null, due_date = "2024-10-15",
            status = "pending", priority = "low", notes = null,
            completed_at = null, completed_by = null, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localWorkOrderQueries.insert(
            id = "wo-high", operation_type = "racking", lot_id = null,
            vessel_id = null, assigned_to = null, due_date = "2024-10-15",
            status = "pending", priority = "high", notes = null,
            completed_at = null, completed_by = null, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localWorkOrderQueries.insert(
            id = "wo-normal", operation_type = "addition", lot_id = null,
            vessel_id = null, assigned_to = null, due_date = "2024-10-15",
            status = "pending", priority = "normal", notes = null,
            completed_at = null, completed_by = null, updated_at = "2024-10-01T10:00:00Z"
        )
        val pending = database.localWorkOrderQueries.selectPending().executeAsList()
        assertEquals(3, pending.size)
        assertEquals("wo-high", pending[0].id)
        assertEquals("wo-normal", pending[1].id)
        assertEquals("wo-low", pending[2].id)
    }

    // ── LocalAdditionProduct ─────────────────────────────────────

    @Test
    fun additionProductInsertAndSelectByCategory() {
        database.localAdditionProductQueries.insert(
            id = "ap-1", name = "Potassium Metabisulfite", category = "additive",
            default_rate = 0.5, default_unit = "g"
        )
        database.localAdditionProductQueries.insert(
            id = "ap-2", name = "EC-1118", category = "yeast",
            default_rate = 1.0, default_unit = "g"
        )
        val additives = database.localAdditionProductQueries.selectByCategory("additive").executeAsList()
        assertEquals(1, additives.size)
        assertEquals("Potassium Metabisulfite", additives.first().name)
    }

    @Test
    fun additionProductSearch() {
        database.localAdditionProductQueries.insert(
            id = "ap-1", name = "Potassium Metabisulfite", category = "additive",
            default_rate = 0.5, default_unit = "g"
        )
        database.localAdditionProductQueries.insert(
            id = "ap-2", name = "Tartaric Acid", category = "acid",
            default_rate = 1.0, default_unit = "g"
        )
        val results = database.localAdditionProductQueries.search("Tartaric").executeAsList()
        assertEquals(1, results.size)
        assertEquals("ap-2", results.first().id)
    }

    // ── LocalUserProfile ─────────────────────────────────────────

    @Test
    fun userProfileInsertAndSelectByEmail() {
        database.localUserProfileQueries.insert(
            id = "user-1", name = "Jane Winemaker", email = "jane@vineyard.com",
            role = "winemaker", permissions = """["lots.edit","vessels.edit","work_orders.complete"]"""
        )
        val user = database.localUserProfileQueries.selectByEmail("jane@vineyard.com").executeAsOneOrNull()
        assertNotNull(user)
        assertEquals("Jane Winemaker", user.name)
        assertEquals("winemaker", user.role)
        assertTrue(user.permissions.contains("lots.edit"))
    }

    @Test
    fun userProfileSelectByRole() {
        database.localUserProfileQueries.insert(
            id = "user-1", name = "Jane", email = "jane@vineyard.com",
            role = "winemaker", permissions = "[]"
        )
        database.localUserProfileQueries.insert(
            id = "user-2", name = "Bob", email = "bob@vineyard.com",
            role = "cellar_hand", permissions = "[]"
        )
        val winemakers = database.localUserProfileQueries.selectByRole("winemaker").executeAsList()
        assertEquals(1, winemakers.size)
        assertEquals("user-1", winemakers.first().id)
    }

    // ── OutboxEvent ──────────────────────────────────────────────

    @Test
    fun outboxInsertAndSelectUnsynced() {
        database.outboxEventQueries.insert(
            id = "evt-1", entity_type = "lot", entity_id = "lot-1",
            operation_type = "addition", payload = """{"amount": 25, "unit": "g"}""",
            performed_by = "user-1", performed_at = "2024-10-15T14:00:00Z",
            device_id = "device-abc", idempotency_key = "idem-001",
            created_at = "2024-10-15T14:00:00Z"
        )
        database.outboxEventQueries.insert(
            id = "evt-2", entity_type = "vessel", entity_id = "vessel-1",
            operation_type = "transfer", payload = """{"volume": 100}""",
            performed_by = "user-1", performed_at = "2024-10-15T14:05:00Z",
            device_id = "device-abc", idempotency_key = "idem-002",
            created_at = "2024-10-15T14:05:00Z"
        )
        val unsynced = database.outboxEventQueries.selectUnsynced().executeAsList()
        assertEquals(2, unsynced.size)
        // Ordered by performed_at ASC
        assertEquals("evt-1", unsynced[0].id)
        assertEquals("evt-2", unsynced[1].id)
    }

    @Test
    fun outboxMarkSynced() {
        database.outboxEventQueries.insert(
            id = "evt-1", entity_type = "lot", entity_id = "lot-1",
            operation_type = "addition", payload = "{}",
            performed_by = "user-1", performed_at = "2024-10-15T14:00:00Z",
            device_id = null, idempotency_key = "idem-001",
            created_at = "2024-10-15T14:00:00Z"
        )
        database.outboxEventQueries.markSynced("evt-1")
        val unsynced = database.outboxEventQueries.selectUnsynced().executeAsList()
        assertEquals(0, unsynced.size)
    }

    @Test
    fun outboxCountUnsynced() {
        database.outboxEventQueries.insert(
            id = "evt-1", entity_type = "lot", entity_id = "lot-1",
            operation_type = "addition", payload = "{}",
            performed_by = "user-1", performed_at = "2024-10-15T14:00:00Z",
            device_id = null, idempotency_key = "idem-001",
            created_at = "2024-10-15T14:00:00Z"
        )
        database.outboxEventQueries.insert(
            id = "evt-2", entity_type = "lot", entity_id = "lot-1",
            operation_type = "transfer", payload = "{}",
            performed_by = "user-1", performed_at = "2024-10-15T14:05:00Z",
            device_id = null, idempotency_key = "idem-002",
            created_at = "2024-10-15T14:05:00Z"
        )
        database.outboxEventQueries.markSynced("evt-1")
        val count = database.outboxEventQueries.countUnsynced().executeAsOne()
        assertEquals(1L, count)
    }

    @Test
    fun outboxIncrementRetry() {
        database.outboxEventQueries.insert(
            id = "evt-1", entity_type = "lot", entity_id = "lot-1",
            operation_type = "addition", payload = "{}",
            performed_by = "user-1", performed_at = "2024-10-15T14:00:00Z",
            device_id = null, idempotency_key = "idem-001",
            created_at = "2024-10-15T14:00:00Z"
        )
        database.outboxEventQueries.incrementRetry(last_error = "Network timeout", id = "evt-1")
        database.outboxEventQueries.incrementRetry(last_error = "Server 500", id = "evt-1")
        val event = database.outboxEventQueries.selectById("evt-1").executeAsOne()
        assertEquals(2, event.retry_count)
        assertEquals("Server 500", event.last_error)
    }

    @Test
    fun outboxSelectPermanentlyFailed() {
        database.outboxEventQueries.insert(
            id = "evt-1", entity_type = "lot", entity_id = "lot-1",
            operation_type = "addition", payload = "{}",
            performed_by = "user-1", performed_at = "2024-10-15T14:00:00Z",
            device_id = null, idempotency_key = "idem-001",
            created_at = "2024-10-15T14:00:00Z"
        )
        // Simulate 5 retries
        repeat(5) {
            database.outboxEventQueries.incrementRetry(last_error = "Failed attempt ${it + 1}", id = "evt-1")
        }
        val failed = database.outboxEventQueries.selectPermanentlyFailed().executeAsList()
        assertEquals(1, failed.size)
        assertEquals(5, failed.first().retry_count)
    }

    @Test
    fun outboxSelectUnsyncedBatch() {
        // Insert 5 events
        repeat(5) { i ->
            database.outboxEventQueries.insert(
                id = "evt-$i", entity_type = "lot", entity_id = "lot-1",
                operation_type = "addition", payload = "{}",
                performed_by = "user-1", performed_at = "2024-10-15T14:0${i}:00Z",
                device_id = null, idempotency_key = "idem-$i",
                created_at = "2024-10-15T14:0${i}:00Z"
            )
        }
        // Batch of 3
        val batch = database.outboxEventQueries.selectUnsyncedBatch(3).executeAsList()
        assertEquals(3, batch.size)
    }

    @Test
    fun outboxDeleteOlderThan() {
        database.outboxEventQueries.insert(
            id = "evt-old", entity_type = "lot", entity_id = "lot-1",
            operation_type = "addition", payload = "{}",
            performed_by = "user-1", performed_at = "2024-01-01T10:00:00Z",
            device_id = null, idempotency_key = "idem-old",
            created_at = "2024-01-01T10:00:00Z"
        )
        database.outboxEventQueries.markSynced("evt-old")
        database.outboxEventQueries.insert(
            id = "evt-new", entity_type = "lot", entity_id = "lot-1",
            operation_type = "transfer", payload = "{}",
            performed_by = "user-1", performed_at = "2024-10-15T10:00:00Z",
            device_id = null, idempotency_key = "idem-new",
            created_at = "2024-10-15T10:00:00Z"
        )
        database.outboxEventQueries.markSynced("evt-new")
        // Delete synced events older than cutoff
        database.outboxEventQueries.deleteOlderThan("2024-06-01T00:00:00Z")
        val remaining = database.outboxEventQueries.selectAll().executeAsList()
        assertEquals(1, remaining.size)
        assertEquals("evt-new", remaining.first().id)
    }

    // ── SyncState ────────────────────────────────────────────────

    @Test
    fun syncStateUpsertAndSelect() {
        database.syncStateQueries.upsert(key = "last_sync", value_ = "2024-10-15T14:00:00Z")
        val value = database.syncStateQueries.selectByKey("last_sync").executeAsOneOrNull()
        assertEquals("2024-10-15T14:00:00Z", value)
    }

    @Test
    fun syncStateUpsertOverwrites() {
        database.syncStateQueries.upsert(key = "device_id", value_ = "device-old")
        database.syncStateQueries.upsert(key = "device_id", value_ = "device-new")
        val value = database.syncStateQueries.selectByKey("device_id").executeAsOneOrNull()
        assertEquals("device-new", value)
    }

    @Test
    fun syncStateDeleteByKey() {
        database.syncStateQueries.upsert(key = "temp_key", value_ = "temp_value")
        database.syncStateQueries.deleteByKey("temp_key")
        assertNull(database.syncStateQueries.selectByKey("temp_key").executeAsOneOrNull())
    }

    // ── Cross-table: deleteAll resets everything ─────────────────

    @Test
    fun deleteAllClearsAllTables() {
        // Insert one record into each table
        database.localLotQueries.insert(
            id = "lot-1", name = "Lot", variety = "Merlot", vintage = 2024,
            source_type = "estate", volume_gallons = 100.0, status = "in_progress",
            parent_lot_id = null, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localVesselQueries.insert(
            id = "vessel-1", name = "Tank", type = "tank", capacity_gallons = 1000.0,
            material = null, location = null, status = "empty",
            current_lot_id = null, current_volume = 0.0, updated_at = "2024-10-01T10:00:00Z"
        )
        database.localAdditionProductQueries.insert(
            id = "ap-1", name = "SO2", category = "additive", default_rate = 0.5, default_unit = "g"
        )
        database.localUserProfileQueries.insert(
            id = "user-1", name = "Jane", email = "jane@v.com", role = "winemaker", permissions = "[]"
        )
        database.syncStateQueries.upsert(key = "k", value_ = "v")

        // Clear all
        database.localLotQueries.deleteAll()
        database.localVesselQueries.deleteAll()
        database.localAdditionProductQueries.deleteAll()
        database.localUserProfileQueries.deleteAll()
        database.syncStateQueries.deleteAll()

        assertEquals(0, database.localLotQueries.selectAll().executeAsList().size)
        assertEquals(0, database.localVesselQueries.selectAll().executeAsList().size)
        assertEquals(0, database.localAdditionProductQueries.selectAll().executeAsList().size)
        assertEquals(0, database.localUserProfileQueries.selectAll().executeAsList().size)
        assertEquals(0, database.syncStateQueries.selectAll().executeAsList().size)
    }
}
