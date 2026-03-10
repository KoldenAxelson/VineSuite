# KMP Shared Core

## Phase
Phase 4

## Dependencies
- `01-foundation.md` — API endpoints, event sync endpoint, auth (Sanctum tokens)
- `02-production-core.md` — API endpoints that the mobile apps consume (lots, vessels, work orders, additions, transfers, barrels)
- `03-lab-fermentation.md` — API endpoints for lab and fermentation data entry

## Goal
Build the Kotlin Multiplatform shared core that powers both the Cellar App and POS App. This is the most technically complex piece of the entire platform. It includes: the local SQLite database (SQLDelight), the event queue (offline outbox), the sync engine (POST events → confirm → pull state), the Ktor API client, and conflict resolution logic. Written in Kotlin, tested on JVM (no emulator needed), shared across Android and iOS. Getting this right means both apps work reliably offline. Getting it wrong breaks both.

## Data Models (SQLDelight — Local SQLite)

These are local mirrors of server-side data, optimized for offline access:

- **LocalLot** — `id` (TEXT/UUID), `name`, `variety`, `vintage`, `volume_gallons` (REAL), `status`, `updated_at`
- **LocalVessel** — `id`, `name`, `type`, `capacity_gallons`, `status`, `current_lot_id`, `current_volume`, `updated_at`
- **LocalBarrel** — `id`, `vessel_id`, `cooperage`, `toast_level`, `oak_type`, `qr_code`, `updated_at`
- **LocalWorkOrder** — `id`, `operation_type`, `lot_id`, `vessel_id`, `assigned_to`, `due_date`, `status`, `notes`, `updated_at`
- **LocalAdditionProduct** — `id`, `name`, `category`, `default_rate`, `default_unit`
- **LocalUserProfile** — `id`, `name`, `email`, `role`, `permissions` (TEXT/JSON)
- **OutboxEvent** — `id` (TEXT/UUID), `entity_type`, `entity_id`, `operation_type`, `payload` (TEXT/JSON), `performed_by`, `performed_at` (TEXT/ISO8601), `device_id`, `idempotency_key`, `synced` (INTEGER 0/1), `retry_count` (INTEGER), `last_error` (TEXT nullable), `created_at`
- **SyncState** — `key` (TEXT PK), `value` (TEXT) — stores last sync timestamp, device ID, etc.

## Sub-Tasks

### 1. KMP project scaffolding
**Description:** Set up the Kotlin Multiplatform project structure with shared module, Android target, iOS target, and JVM test target.
**Files to create:**
- `shared/build.gradle.kts` — KMP configuration with targets: jvm (for testing), android, iosArm64, iosSimulatorArm64
- `shared/src/commonMain/kotlin/com/vinesuite/shared/` — package structure
- `shared/src/commonTest/kotlin/` — shared test sources
- `shared/src/jvmTest/kotlin/` — JVM-specific test harness
- `settings.gradle.kts` — include shared module
**Acceptance criteria:**
- `./gradlew :shared:jvmTest` runs and passes (even if tests are empty stubs)
- Kotlin compiles for all three targets without errors
- Dependencies resolve: SQLDelight, Ktor, kotlinx-serialization, kotlinx-coroutines, kotlinx-datetime
**Gotchas:** Pin KMP and plugin versions carefully — version mismatches between Kotlin, SQLDelight, and Ktor cause hard-to-debug build failures. Use a version catalog (libs.versions.toml).

### 2. SQLDelight database schema
**Description:** Define the local SQLite schema using SQLDelight `.sq` files. These create type-safe Kotlin data classes and query functions.
**Files to create:**
- `shared/src/commonMain/sqldelight/com/vinesuite/shared/database/LocalLot.sq`
- `shared/src/commonMain/sqldelight/com/vinesuite/shared/database/LocalVessel.sq`
- `shared/src/commonMain/sqldelight/com/vinesuite/shared/database/LocalBarrel.sq`
- `shared/src/commonMain/sqldelight/com/vinesuite/shared/database/LocalWorkOrder.sq`
- `shared/src/commonMain/sqldelight/com/vinesuite/shared/database/LocalAdditionProduct.sq`
- `shared/src/commonMain/sqldelight/com/vinesuite/shared/database/OutboxEvent.sq`
- `shared/src/commonMain/sqldelight/com/vinesuite/shared/database/SyncState.sq`
- `shared/build.gradle.kts` — add SQLDelight plugin + configuration
**Acceptance criteria:**
- SQLDelight generates Kotlin data classes for all tables
- Query functions work for: insert, select by ID, select all, select filtered, update status
- OutboxEvent queries: insert, selectUnsynced, markSynced, deleteOlderThan
- Schema migration supported (for future schema changes)
- All queries tested on JVM
**Gotchas:** SQLDelight uses platform-specific drivers (AndroidSqliteDriver, NativeSqliteDriver). Create a `DatabaseDriverFactory` expect/actual class to handle this.

### 3. Event queue (outbox pattern)
**Description:** Build the local event outbox. Every user operation writes an event to the local OutboxEvent table with a client-generated UUID idempotency key. Events wait here until synced to the server.
**Files to create:**
- `shared/src/commonMain/kotlin/com/vinesuite/shared/sync/EventQueue.kt`
- `shared/src/commonMain/kotlin/com/vinesuite/shared/sync/EventFactory.kt` — creates properly formatted events with UUIDs and timestamps
- `shared/src/commonMain/kotlin/com/vinesuite/shared/models/SyncEvent.kt` — data class matching server event schema
**Acceptance criteria:**
- `EventQueue.enqueue(event)` writes to local SQLite OutboxEvent table
- Each event gets a unique UUID `idempotency_key` generated client-side
- `EventQueue.getPendingEvents()` returns unsynced events ordered by `performed_at`
- `EventQueue.markSynced(eventIds)` marks events as synced
- `EventQueue.retryFailed()` re-queues failed events with incremented retry count
- Events that fail 5+ times are flagged for manual review (not retried endlessly)
**Gotchas:** The idempotency key is the safety net for offline sync. Even if the POST succeeds but the response is lost (timeout), the retry will be deduplicated server-side. Generate UUIDs using kotlinx-uuid or platform UUID.

### 4. Ktor API client
**Description:** Build the HTTP client that communicates with the Laravel API. Handles auth token management, request/response serialization, and the batch sync endpoint.
**Files to create:**
- `shared/src/commonMain/kotlin/com/vinesuite/shared/api/ApiClient.kt`
- `shared/src/commonMain/kotlin/com/vinesuite/shared/api/AuthManager.kt` — stores/refreshes Sanctum token
- `shared/src/commonMain/kotlin/com/vinesuite/shared/api/models/` — API request/response DTOs
- `shared/src/commonMain/kotlin/com/vinesuite/shared/api/endpoints/` — endpoint-specific methods
**Acceptance criteria:**
- Login with email/password → receive and store Sanctum token
- Token stored securely (platform keychain via expect/actual)
- All API calls include Bearer token in Authorization header
- Batch sync endpoint: POST array of events, receive confirmation
- Pull state: GET current lots, vessels, work orders (paginated)
- Handles 401 (token expired) → re-auth or redirect to login
- Handles network errors gracefully (no crashes, returns Result type)
- Uses kotlinx-serialization for JSON
**Gotchas:** Token storage: Android uses EncryptedSharedPreferences, iOS uses Keychain. Create an expect/actual `SecureStorage` class. Base URL must be configurable (local dev vs. staging vs. production).

### 5. Sync engine
**Description:** The core synchronization logic. Manages the full sync cycle: push pending events → receive confirmation → pull latest state → update local database.
**Files to create:**
- `shared/src/commonMain/kotlin/com/vinesuite/shared/sync/SyncEngine.kt`
- `shared/src/commonMain/kotlin/com/vinesuite/shared/sync/SyncScheduler.kt` — manages sync timing
- `shared/src/commonMain/kotlin/com/vinesuite/shared/sync/ConnectivityMonitor.kt` — expect/actual for network state
**Acceptance criteria:**
- **Push phase:** Collect all unsynced events from outbox → POST to /api/v1/events/sync → mark confirmed events as synced
- **Pull phase:** GET latest state (lots, vessels, work orders modified since last sync) → update local SQLite
- **Sync triggers:** Immediate on any operation (when online), every 5 minutes when backgrounded, immediate full sync on reconnect
- **Conflict handling:** Additive operations (additions) apply all. Destructive operations (transfers) return conflict if volume insufficient — queue for manual resolution
- **Sync state:** Last successful sync timestamp stored in SyncState table
- **Progress observable:** Sync status exposed as a Flow/StateFlow for UI to show sync indicator
**Gotchas:** Sync must be atomic per operation — if push succeeds but pull fails, don't lose the push confirmation. Use a state machine: IDLE → PUSHING → PULLING → COMPLETE / ERROR. Background sync on iOS requires BGAppRefreshTask registration.

### 6. Conflict resolution logic
**Description:** Implement the conflict resolution strategy for offline operations. Additive operations always apply. Destructive operations validate volume on the server and surface conflicts.
**Files to create:**
- `shared/src/commonMain/kotlin/com/vinesuite/shared/sync/ConflictResolver.kt`
- `shared/src/commonMain/kotlin/com/vinesuite/shared/sync/ConflictStore.kt` — stores unresolved conflicts locally
**Acceptance criteria:**
- Additive operations (SO2 addition, nutrient addition) from multiple offline devices all apply — no last-write-wins for these
- Destructive operations (transfer X gallons from vessel A): server validates volume. If insufficient, returns conflict with details
- Conflicts stored locally with enough context for the user to resolve (what was attempted, what the server says the current state is)
- UI can display unresolved conflicts and let user retry or dismiss
**Gotchas:** Clock drift: if two devices have drifted clocks, `performed_at` ordering may be wrong. Mitigate by checking NTP on app launch and warning if drift > 30 seconds.

### 7. NTP clock drift check
**Description:** On app launch, check device clock against NTP. Warn the user if drift exceeds 30 seconds (this affects event ordering for conflict resolution).
**Files to create:**
- `shared/src/commonMain/kotlin/com/vinesuite/shared/util/ClockDriftChecker.kt`
**Acceptance criteria:**
- On app launch, query an NTP server (or use system NTP APIs)
- If drift > 30 seconds, show a warning to the user (not a block)
- Log the drift amount for debugging
**Gotchas:** NTP check requires network. If offline at launch, skip the check. Don't block the app — just warn.

### 8. Comprehensive JVM test suite
**Description:** Write exhaustive tests for the shared core. These run on JVM — fast, no emulator, should be in CI.
**Files to create:**
- `shared/src/jvmTest/kotlin/com/vinesuite/shared/sync/EventQueueTest.kt`
- `shared/src/jvmTest/kotlin/com/vinesuite/shared/sync/SyncEngineTest.kt`
- `shared/src/jvmTest/kotlin/com/vinesuite/shared/sync/ConflictResolverTest.kt`
- `shared/src/jvmTest/kotlin/com/vinesuite/shared/api/ApiClientTest.kt` (with mock server)
- `shared/src/jvmTest/kotlin/com/vinesuite/shared/database/LocalDatabaseTest.kt`
**Acceptance criteria:**
- Event queue: enqueue, dequeue, mark synced, retry failed, max retry limit
- Sync engine: full cycle test with mock API (push → confirm → pull → update local)
- Conflict resolution: additive ops apply all, destructive ops detect conflicts
- API client: auth flow, token refresh, error handling, batch sync serialization
- Database: all CRUD operations, queries return correct data
- Offline scenario: enqueue 50 events offline → come online → all sync correctly
- **TARGET: 90%+ code coverage on the shared core**
**Gotchas:** Use MockEngine (Ktor) for API client tests. Use in-memory SQLite (JdbcSqliteDriver) for database tests. No network calls in tests.

## API Endpoints (Consumed, Not Created)
The shared core consumes these endpoints (created in earlier phases):

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/v1/auth/login` | Login, receive token |
| POST | `/api/v1/events/sync` | Batch push events |
| GET | `/api/v1/lots` | Pull current lots |
| GET | `/api/v1/vessels` | Pull current vessels |
| GET | `/api/v1/work-orders` | Pull work orders |
| GET | `/api/v1/barrels` | Pull barrel registry |
| GET | `/api/v1/raw-materials` | Pull addition product library |

## Events
The shared core generates these events locally (pushed to server via sync):

All event types from `02-production-core.md` and `03-lab-fermentation.md` — the shared core is the client-side event producer.

## Testing Notes
- **All tests run on JVM** — fast CI, no emulator. This is a hard requirement.
- **Mock API server** for sync tests (Ktor MockEngine)
- **In-memory SQLite** for database tests
- **Offline simulation:** Tests should simulate: enqueue events → attempt sync while "offline" (mock returns error) → come "online" → verify sync succeeds
- **Conflict scenarios:** Multiple devices modifying the same vessel volume concurrently
- **Idempotency:** Push the same event twice, verify server handles it correctly (via mock)
- **CRITICAL:** This is the foundation both native apps stand on. 90%+ coverage is non-negotiable.
