# KMP Shared Core

> The Kotlin Multiplatform module that powers both mobile apps. Contains the local database, event outbox, sync engine, API client, and conflict resolution — everything needed for offline-first cellar and POS operations.

> Diagram: `docs/diagrams/kmp-shared-core.mermaid`

---

## Project Structure

The shared core lives at `shared/` in the repo root. It's a self-contained Gradle project, not part of the Laravel build.

```
shared/
├── build.gradle.kts              # KMP plugin config, all targets + dependencies
├── settings.gradle.kts           # Root project: vinesuite-shared
├── gradle.properties             # JVM args, caching, KMP flags
├── gradle/
│   ├── libs.versions.toml        # Version catalog (single source of truth)
│   └── wrapper/                  # Gradle 8.10.2
├── src/
│   ├── commonMain/kotlin/com/vinesuite/shared/
│   │   ├── api/                  # ApiClient, AuthManager, SecureStorage, DTOs
│   │   ├── database/             # DatabaseDriverFactory, DatabaseFactory
│   │   ├── models/               # SyncEvent
│   │   ├── sync/                 # SyncEngine, EventQueue, EventFactory, ConflictResolver, ConflictStore
│   │   └── util/                 # ClockDriftChecker
│   ├── commonMain/sqldelight/    # .sq schema files (9 tables)
│   ├── jvmMain/                  # JVM actuals (in-memory drivers, test storage)
│   ├── androidMain/              # Android actuals (encrypted prefs, connectivity)
│   ├── iosMain/                  # iOS actuals (Keychain TODO, NWPathMonitor TODO)
│   └── jvmTest/                  # 116 JVM tests (MockEngine + in-memory SQLite)
```

---

## Targets

| Target | Purpose | Compiler | Needs SDK? |
|---|---|---|---|
| `jvm` | Tests + local dev | Kotlin/JVM | No (just Java 17+) |
| `androidTarget` | Android app | Kotlin/JVM + AGP | Yes (`ANDROID_HOME`) |
| `iosArm64` | iOS device | Kotlin/Native | No (compiles on macOS) |
| `iosSimulatorArm64` | iOS simulator | Kotlin/Native | No (compiles on macOS) |

The JVM target is the workhorse for development — all tests run here, fast, no emulator. Android and iOS targets are consumed by the native app projects.

---

## Dependencies (Version Catalog)

All versions pinned in `gradle/libs.versions.toml`:

| Library | Version | Purpose |
|---|---|---|
| Kotlin | 2.0.21 | Language + K2 compiler |
| SQLDelight | 2.0.2 | Type-safe SQLite (schema + queries) |
| Ktor | 2.3.12 | HTTP client (platform engines) |
| kotlinx-serialization | 1.7.3 | JSON serialization |
| kotlinx-coroutines | 1.9.0 | Async + Flow |
| kotlinx-datetime | 0.6.1 | Cross-platform time |
| Kover | 0.8.3 | Code coverage |
| AGP | 8.2.2 | Android Gradle Plugin |

---

## Local Database (SQLDelight)

Nine tables, defined as `.sq` files. SQLDelight generates type-safe Kotlin data classes and query functions at compile time.

### Entity mirrors (synced from server via pull)

| Table | Mirrors | Key queries |
|---|---|---|
| `LocalLot` | `lots` | selectByStatus, selectByVarietyAndVintage, updateVolume |
| `LocalVessel` | `vessels` | selectAvailable, selectByType, updateVolume (with current_lot_id) |
| `LocalBarrel` | `barrels` | selectByQrCode, selectByVesselId (FK cascade on vessel delete) |
| `LocalWorkOrder` | `work_orders` | selectPending (priority-ordered), markCompleted, selectByAssignee |
| `LocalAdditionProduct` | `raw_materials` | selectByCategory, search (LIKE) |
| `LocalUserProfile` | `users` | selectByEmail, selectByRole |

All entity tables use `INSERT OR REPLACE` — the server's latest state overwrites the local row on each sync pull.

### Sync infrastructure

| Table | Purpose |
|---|---|
| `OutboxEvent` | Event outbox. Append-only. Every user operation writes here. Fields: entity_type, entity_id, operation_type, payload (JSON), idempotency_key (UNIQUE), synced flag, retry_count. |
| `SyncState` | Key-value store. Stores `last_sync_timestamp`, device ID, etc. `INSERT OR REPLACE` upsert. |
| `LocalConflict` | Unresolved sync conflicts. Stores attempted payload, server state at rejection, error message. Status: unresolved → resolved/dismissed. |

### Platform drivers

| Platform | Driver | Notes |
|---|---|---|
| JVM | `JdbcSqliteDriver(IN_MEMORY)` | Tests. PRAGMA foreign_keys = ON enabled. |
| Android | `AndroidSqliteDriver` | File-backed. Context required. |
| iOS | `NativeSqliteDriver` | File-backed. Named "vinesuite.db". |

Instantiation: `DatabaseFactory.create(driverFactory)` — pass a platform-specific `DatabaseDriverFactory`.

---

## Event Outbox

The outbox pattern is the foundation of offline-first. Every user action follows this flow:

```
User taps "Add SO2" →
  EventFactory.create(entityType="lot", operationType="addition", payload={...}) →
    EventQueue.enqueue(syncEvent) →
      INSERT INTO OutboxEvent (synced=0, retry_count=0)
```

The event sits in the outbox until the next sync cycle pushes it to the server.

### EventFactory

Creates `SyncEvent` objects with auto-generated UUID idempotency keys and ISO 8601 timestamps. Accepts an injectable `Clock` (fixed clocks in tests, `Clock.System` in production).

### EventQueue

| Method | What it does |
|---|---|
| `enqueue(event)` | Write to OutboxEvent table. Returns local event ID. |
| `getPendingEvents()` | All unsynced events, ordered by performed_at (FIFO). |
| `getPendingBatch(limit)` | Subset for controlled sync pushes. Default 50. |
| `markSynced(eventId)` | Set synced=1. Called after server confirms. |
| `markSyncedBatch(ids)` | Same, in a transaction. |
| `recordFailure(id, error)` | Increment retry_count, store last_error. |
| `pendingCount()` | For UI sync badge. |
| `getPermanentlyFailed()` | Events with retry_count >= 5. Need manual review. |
| `purgeSyncedEvents(olderThan)` | Cleanup. Only deletes synced events. |
| `toSyncEvent(outboxEvent)` | Reconstruct API-ready SyncEvent from outbox row. |

### SyncEvent (the wire format)

Matches the server's `EventSyncRequest` validation exactly:

```kotlin
SyncEvent(
    entityType = "lot",           // required, max 50 chars
    entityId = "uuid-...",        // required, UUID format
    operationType = "addition",   // required, max 50 chars
    payload = JsonObject,         // required, JSON object
    performedBy = "user-uuid",    // user attribution
    performedAt = "ISO8601",      // within last 30 days, not future
    deviceId = "device-...",      // optional
    idempotencyKey = "uuid-...",  // required, client-generated UUID
)
```

---

## API Client

`ApiClient` wraps Ktor HttpClient. All methods return `Result<T>` — no exceptions from the public API.

### Endpoints

| Method | Server endpoint | Returns |
|---|---|---|
| `login(email, password, ...)` | `POST /auth/login` | `Result<LoginResponse>` — stores token on success |
| `logout()` | `POST /auth/logout` | `Result<Unit>` — clears local auth |
| `pushEvents(events)` | `POST /events/sync` | `Result<List<SyncPushResult>>` — per-event status |
| `pullState(since?)` | `GET /sync/pull?since=` | `Result<Pair<SyncPullResponse, SyncPullMeta>>` |

### Auth flow

1. `login()` → server returns Sanctum token → `AuthManager.storeAuth()` stores in `SecureStorage`
2. Every subsequent request → `authHeaders()` adds `Authorization: Bearer <token>` + `X-Tenant-ID`
3. Any 401 response → `AuthManager.clearAuth()` → UI redirects to login

### Response parsing

Responses bypass Ktor ContentNegotiation. Raw text is read via `bodyAsText()`, then parsed with our own `Json` instance in two steps: first the envelope (`{data, meta, errors}`), then the typed `data` field. This avoids generic type erasure issues with kotlinx-serialization.

### SecureStorage (token persistence)

| Platform | Implementation |
|---|---|
| JVM | In-memory `MutableMap` (tests only) |
| Android | `EncryptedSharedPreferences` (AES-256-GCM) |
| iOS | `NSUserDefaults` (Keychain TODO) |

---

## Sync Engine

The orchestrator. Manages the full push → pull cycle with a state machine.

### State machine

```
IDLE → PUSHING → PULLING → IDLE (success)
                         → ERROR (failure)
```

Exposed as `StateFlow<SyncState>` — UI collects this to show a sync indicator (spinner, status bar, etc.).

### Sync cycle

```
sync()
  ├── if already PUSHING or PULLING → return ALREADY_RUNNING
  ├── if offline → return OFFLINE
  │
  ├── PUSH phase
  │   ├── getPendingBatch(50) from outbox
  │   ├── POST to /events/sync
  │   ├── per-event: accepted → markSynced, failed → recordFailure
  │   ├── repeat until outbox drained (skipping already-processed IDs)
  │   └── if batch-level failure (network, 401) → state=ERROR, return PUSH_FAILED
  │
  ├── PULL phase
  │   ├── GET /sync/pull?since=<last_sync_timestamp>
  │   ├── upsert all entities into local SQLite (single transaction per page)
  │   ├── store synced_at in SyncState
  │   ├── if has_more=true → repeat with new since
  │   └── if failure → state=ERROR, return PULL_FAILED (push results preserved)
  │
  └── state=IDLE, return SUCCESS
```

Key invariant: push and pull are atomic per phase. If push succeeds but pull fails, the push confirmations (markSynced) are NOT rolled back. The next sync resumes from where pull left off.

### SyncScheduler

Thin coroutine wrapper around `SyncEngine.sync()`:

| Trigger | When | Method |
|---|---|---|
| Periodic | Every 5 minutes (backgrounded) | `start(scope)` |
| Immediate | After any local operation (when online) | `syncNow(scope)` |
| Reconnect | When connectivity is restored | `syncNow(scope)` |

The scheduler doesn't own a coroutine scope — it receives one from the app layer. This avoids lifecycle management complexity in the shared core.

---

## Conflict Resolution

Two categories of operations, handled differently:

### Additive operations (always apply)

`addition`, `lab_analysis`, `fermentation_reading`, `fermentation_start`, `fermentation_end`, `sensory_note`

Multiple offline devices adding SO2 to the same lot is fine — the server applies all of them. If one fails, it's a data issue (bad entity_id, validation error), not a conflict. The EventQueue retry mechanism handles these.

### Destructive operations (can conflict)

`transfer`, `blend`, `rack`, `bottling`, `pressing`, `filtering`, `lot_split`, `lot_merge`

These modify shared state (e.g., vessel volume). If two offline devices both try to transfer 300 gallons from a 500-gallon vessel, the second one fails server-side with "insufficient volume."

When a destructive operation fails:

```
Server rejects → ConflictResolver.processPushResult() → ConflictStore.recordConflict()
  → INSERT INTO LocalConflict (attempted_payload, server_state, error_message)
    → UI shows unresolved conflict
      → User chooses: resolve (retry) or dismiss
```

### ConflictStore

| Method | Purpose |
|---|---|
| `getUnresolved()` | List for conflict UI |
| `observeUnresolved()` | Reactive `Flow` for badges/lists |
| `unresolvedCount()` | Badge count |
| `resolve(id)` | Mark as resolved |
| `dismiss(id)` | Mark as dismissed |
| `purgeResolved()` | Cleanup |

---

## Clock Drift Checker

On app launch, compares device clock to a reference time source (the VineSuite API server, which is NTP-synced).

| Result | Condition | Action |
|---|---|---|
| `Ok(driftSeconds)` | drift <= 30s | No action |
| `Drifted(drift, deviceTime, refTime)` | drift > 30s | Show non-blocking warning |
| `Unavailable(reason)` | Offline/timeout/error | Skip silently |

The check uses `withTimeoutOrNull(5.seconds)` — never blocks the app.

Clock drift matters because event ordering for conflict resolution uses `performed_at` timestamps. If two devices have drifted clocks, event ordering may be incorrect.

---

## Makefile Targets

```bash
make shared-deps            # Verify Java 17+ and Gradle wrapper
make shared-build           # Compile JVM target
make shared-build-all       # Compile all targets (needs ANDROID_HOME)
make shared-test            # Run JVM tests
make shared-test F=Sync     # Filter by test name
make shared-test-coverage   # Kover HTML coverage report
make shared-schema          # Regenerate SQLDelight sources from .sq files
make shared-clean           # Clean build artifacts
make shared-check           # Full QA: build + test + coverage
```

---

## Testing Strategy

All tests run on JVM — fast, no emulator, CI-ready.

| What | How |
|---|---|
| Database | In-memory SQLite via `JdbcSqliteDriver(IN_MEMORY)` |
| API | Ktor `MockEngine` with inline response handlers |
| Timestamps | Fixed `Clock` injection for deterministic ordering |
| Offline | Fake `ConnectivityMonitor` returning `false` |
| Sync cycle | Full integration: enqueue → push (MockEngine) → pull (MockEngine) → verify local DB |

116 tests across 7 test classes. Coverage: 90%+ on hand-written code; 72.8% overall (SQLDelight/kotlinx-serialization generated code inflates the denominator).

---

## Adding New Operation Types

When a new cellar operation is added (e.g., a new production workflow):

1. Define the payload shape — what fields does the event carry?
2. Add the operation type string to `ConflictResolver.ADDITIVE_OPERATIONS` or `DESTRUCTIVE_OPERATIONS`
3. Create the event in the app layer: `eventFactory.create(entityType, entityId, operationType, payload)`
4. Enqueue it: `eventQueue.enqueue(event)`
5. The sync engine handles everything else — push, confirm, retry, conflict detection

No changes needed to the sync engine, API client, or database for new operation types. The outbox pattern is operation-type agnostic.

---

*Built in Phase 7 (Task 07). See `docs/execution/completed/07-kmp-shared-core.info.md` for sub-task details and decision rationale.*
