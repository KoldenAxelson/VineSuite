# KMP Shared Core — Completion Record

> Task spec: `docs/execution/tasks/07-kmp-shared-core.md`
> Phase: 7

---

## Sub-Task 1: KMP Project Scaffolding
**Completed:** 2026-03-18
**Status:** Pending user validation

### Key Decisions
- **Gradle root at `shared/`**: The KMP module is its own Gradle root project (`vinesuite-shared`), not a subproject of a larger Gradle build. The PHP/Laravel project doesn't use Gradle, so there's no parent to nest under. Keeps the KMP world self-contained.
- **Kotlin 2.0.21 + Gradle 8.10.2**: Pinned via version catalog (`gradle/libs.versions.toml`). Kotlin 2.0.x is the K2 compiler — stable, required by SQLDelight 2.0.2. Gradle 8.10.2 is the latest compatible with AGP 8.2.2 and Kotlin 2.0.21.
- **SQLDelight 2.0.2 over 1.x**: 2.x has native Kotlin 2.0 support, coroutines extensions, and `app.cash.sqldelight` package (1.x was `com.squareup.sqldelight`). Configured with empty `VineSuiteDatabase` — .sq files come in Sub-Task 2.
- **Ktor 2.3.12 over 3.0.x**: Ktor 2.3.x is battle-tested and has wider community support. 3.0 brings breaking API changes without features we need. Can upgrade later if needed.
- **`applyDefaultHierarchyTemplate()`**: Uses Kotlin's built-in source set hierarchy instead of manual `iosMain` wiring. Automatically creates `appleMain → iosMain` intermediate source sets.
- **Android target included**: Requires Android SDK (`ANDROID_HOME`) to compile. Included now to match spec; if SDK is missing, JVM/iOS targets still compile independently via `./gradlew jvmTest`.
- **Kover 0.8.3 for coverage**: Lightweight Kotlin coverage plugin. HTML reports at `build/reports/kover/html/`. Wired into `make shared-test-coverage` and `make shared-check`.

### Deviations from Spec
- **Gradle wrapper not generated**: Binary `gradle-wrapper.jar` can't be created in this environment. User must bootstrap via `gradle wrapper --gradle-version 8.10.2` (requires one-time Gradle install) or copy wrapper from another project. All other wrapper files (properties, scripts) are pre-configured.
- **No `.gitignore` yet**: Should add before committing to exclude `build/`, `.gradle/`, local IDE files.

### Patterns Established
- **Version catalog (`libs.versions.toml`)**: All dependency versions centralized. Sub-tasks add new entries here, never hardcode versions in `build.gradle.kts`.
- **expect/actual for platform abstractions**: `Platform.kt` demonstrates the pattern. Future Sub-Tasks use this for `DatabaseDriverFactory`, `SecureStorage`, `ConnectivityMonitor`.
- **JVM smoke tests validate dependency wiring**: Each new dependency gets a trivial test proving it compiles and runs on JVM. Catches version mismatch issues early.

### Test Summary
- `src/commonTest/.../PlatformTest.kt` — validates expect/actual resolution (runs on all targets)
- `src/jvmTest/.../SmokeTest.kt` — validates JVM target + dependency wiring (coroutines, serialization, datetime)
- Known gaps: Ktor client and SQLDelight not smoke-tested yet (no mock server or .sq files). Covered in Sub-Tasks 2-4.

---

## Sub-Task 2: SQLDelight Database Schema
**Completed:** 2026-03-18
**Status:** Pending user validation

### Key Decisions
- **`INSERT OR REPLACE` for all entity tables**: Local tables are state mirrors from the server. On pull-sync, the latest server state overwrites the local row entirely. This avoids merge logic for read-only mirror data. OutboxEvent uses plain `INSERT` (events are append-only, never overwritten).
- **`current_lot_id` and `current_volume` on LocalVessel**: Not in the server vessel model directly (it's a pivot table relationship), but the KMP spec calls for these as denormalized fields for offline convenience. The sync pull endpoint will populate these from the lot-vessel pivot.
- **`forest_origin` added to LocalBarrel**: Server model has this field; task spec omitted it. Added for schema parity — prevents data loss on sync round-trips.
- **SyncState as key-value store**: Simple `key TEXT PRIMARY KEY, value TEXT` table with `INSERT OR REPLACE` upsert. No schema changes needed as new sync metadata keys are added.
- **Batch queries for OutboxEvent**: Added `selectUnsyncedBatch(limit)` for controlled sync push sizes, `selectPermanentlyFailed` for events hitting the 5-retry ceiling, `countUnsynced` for the UI sync indicator.
- **Foreign key on LocalBarrel → LocalVessel with CASCADE DELETE**: Mirrors server relationship. If a vessel is removed during sync pull, its barrel detail row is cleaned up automatically.

### Deviations from Spec
- **Added `LocalUserProfile` table**: Spec listed it in the data models section but not in the `.sq` files list. Added because the API client (Sub-Task 4) needs offline user/role context for permission checks.
- **Added `forest_origin` to LocalBarrel**: Present in server model but absent from task spec's data model list.
- **`markSyncedBatch` uses `IN` clause**: SQLDelight generates a `Collection<String>` parameter for `WHERE id IN ?` — more efficient than marking one-by-one in a loop.

### Patterns Established
- **expect/actual `DatabaseDriverFactory`**: Platform-specific driver creation. JVM uses `JdbcSqliteDriver.IN_MEMORY` for tests, iOS uses `NativeSqliteDriver`, Android uses `AndroidSqliteDriver(context)`. All sub-tasks that touch the database use this factory.
- **`DatabaseFactory.create(driverFactory)`**: Single entry point for database instantiation across all platforms.
- **ISO 8601 strings for timestamps**: All `_at` columns are `TEXT` storing ISO 8601. No SQLite date functions needed — comparison and ordering work via string sort on ISO format. Timezone handling stays in Kotlin code.

### Test Summary
- `src/jvmTest/.../LocalDatabaseTest.kt` — 25 tests covering all 8 tables: insert, select by ID, select filtered, update status/volume, delete, batch operations, priority ordering, retry counting, upsert semantics, cross-table deleteAll
- Known gaps: Schema migration tests deferred until we actually need a migration (Sub-Task 2 is the initial schema).

---

## Sub-Task 3: Event Queue (Outbox Pattern)
**Completed:** 2026-03-18
**Status:** Pending user validation

### Key Decisions
- **`SyncEvent` uses `JsonObject` for payload, not a generic `Map`**: Server expects `payload` as a JSON object. Using kotlinx-serialization's `JsonObject` gives us type-safe JSON construction via `buildJsonObject {}` and round-trips cleanly through the outbox (stored as TEXT, deserialized back). No need for a separate serializer per event type.
- **`EventFactory` accepts injectable `Clock`**: Enables deterministic timestamps in tests via a fixed clock. Production code uses `Clock.System`. Same pattern recommended by kotlinx-datetime docs.
- **`kotlin.uuid.Uuid` for idempotency keys**: Kotlin 2.0 ships `kotlin.uuid` in stdlib (experimental). Avoids pulling in a third-party UUID library. The `@OptIn(ExperimentalUuidApi::class)` is scoped to the factory only.
- **`EventQueue.markSyncedBatch` uses a transaction loop instead of `IN` clause**: SQLDelight's `WHERE id IN ?` with `Collection<String>` had codegen issues in some versions. A transaction wrapping individual `markSynced` calls is equally atomic and avoids the risk. Performance is fine for batch sizes ≤ 100.
- **`DEFAULT_BATCH_SIZE = 50`**: Server accepts max 100 events per sync request. 50 is conservative — leaves headroom and keeps individual sync cycles fast.
- **`toSyncEvent()` on EventQueue, not on OutboxEvent**: OutboxEvent is a SQLDelight-generated data class (can't add methods). The conversion lives on EventQueue since it owns the serialization context.

### Deviations from Spec
- **`performed_by` included in `SyncEvent`**: Spec's data class didn't list it, but the server's `EventLogger::log()` requires `performed_by` (the user UUID). Added to SyncEvent so the server can attribute the event.
- **No separate `retryFailed()` method**: Spec listed `EventQueue.retryFailed()` as re-queuing failed events. Instead, failed events remain in the outbox with `synced = 0` and are picked up by `getPendingEvents()` naturally. The retry count tracks how many times they've failed. Simpler and avoids event duplication.

### Patterns Established
- **Fixed clock in tests**: `EventFactory(clock = fixedClock)` pattern for deterministic timestamp testing. All future sync tests should use this.
- **`buildJsonObject {}` for test payloads**: Clean DSL for building event payloads in tests without string concatenation.

### Test Summary
- `src/jvmTest/.../sync/EventQueueTest.kt` — 20 tests covering:
  - EventFactory: unique idempotency keys, user/device propagation, clock injection, timestamp override
  - Enqueue: writes to outbox, preserves all fields, maintains FIFO order
  - Pending: count reflects unsynced, batch retrieval respects limits
  - Sync: markSynced removes from pending, markSyncedBatch handles multiple
  - Retry: failure increments count, failed events stay in pending, retryable vs permanently failed distinction, resetRetry allows reprocessing
  - Purge: deletes old synced events, preserves unsynced
  - Round-trip: OutboxEvent → SyncEvent reconstruction preserves all fields including payload
  - Bulk: 50-event offline scenario with batched sync
- Known gaps: Idempotency deduplication is server-side (tested in PHP). KMP just ensures unique keys are generated.

---

## Sub-Task 4: Ktor API Client
**Completed:** 2026-03-18
**Status:** Pending user validation

### Key Decisions
- **`ApiClient` accepts optional `HttpClient` for testing**: Constructor takes a pre-configured Ktor `HttpClient`. Production builds use the default (OkHttp on JVM/Android, Darwin on iOS). Tests inject `MockEngine`. Clean seam, no test-only code in production path.
- **All methods return `Result<T>`**: No exceptions thrown from public API. Network errors, deserialization errors, HTTP errors all wrapped in `Result.failure`. Callers use `getOrNull()` / `exceptionOrNull()`. Matches Kotlin idiom.
- **`ApiException` carries status code + server errors**: Structured error type with HTTP status and the server's error list. Enables callers to distinguish 401 (clear auth, redirect to login) from 422 (show validation errors) from 500 (retry later).
- **Auto-clear auth on 401**: Any API call that receives HTTP 401 automatically calls `authManager.clearAuth()`. The UI layer can observe `isAuthenticated()` to redirect to login. Prevents stale token loops.
- **`SyncPullResponse` uses embedded ref DTOs**: Server nests related objects (e.g., `current_lot` inside vessel, `assigned_to` inside work order). DTOs mirror this exactly: `EmbeddedLotRef`, `EmbeddedVesselRef`, `EmbeddedUserRef`. The SyncEngine (Sub-Task 5) will flatten these into the local SQLite tables.
- **`ignoreUnknownKeys = true` in JSON config**: Server may add new fields in future versions. Client won't break on unrecognized JSON keys.
- **`X-Tenant-ID` header on all authenticated requests**: Multi-tenant API requires tenant context. Stored by `AuthManager` at login time, injected via `authHeaders()` helper.
- **iOS `SecureStorage` uses `NSUserDefaults` with Keychain TODO**: Full Keychain Services integration deferred to the Cellar App phase. `NSUserDefaults` is sufficient for the shared core's test/dev cycle. TODO is documented in code.

### Deviations from Spec
- **Token refresh not implemented**: Spec mentions "re-auth or redirect to login" on 401. Implemented the simpler path: 401 → clear auth → UI redirects to login. Token refresh (silent re-authentication) is a UX enhancement for the Cellar App phase.
- **No separate `endpoints/` package**: Spec listed `api/endpoints/` for endpoint-specific methods. All methods live directly on `ApiClient` since there are only 4 endpoints (login, logout, pushEvents, pullState). Extracting into separate files would add indirection without benefit at this scale.
- **`performed_by` kept on `SyncEvent`**: The server's `EventProcessor` receives `performed_by` from the authenticated user's token, but we still send it in the payload for completeness and future flexibility.

### Patterns Established
- **`MockEngine` for API tests**: All Ktor HTTP tests use `MockEngine` with inline response handlers. Tests validate request path, headers, body structure AND response deserialization in one pass. No network calls.
- **`SecureStorage` expect/actual**: Platform-specific key-value storage for sensitive data. JVM = in-memory map (tests), Android = EncryptedSharedPreferences (AES-256), iOS = NSUserDefaults (Keychain TODO).
- **API envelope unwrapping**: `handleEnvelope()` private method standardizes the `{ data, meta, errors }` → `Result<T>` conversion. All endpoints use the same unwrap logic.

### Test Summary
- `src/jvmTest/.../api/ApiClientTest.kt` — 14 tests covering:
  - Login: success stores token + user + tenant, invalid credentials returns failure, network error handled gracefully
  - Auth state: logout clears everything, 401 auto-clears auth, cached user round-trips
  - Push: sends batch with correct auth/tenant headers, handles accepted/skipped/failed statuses, partial failure per-event
  - Pull: returns entities + synced_at meta, passes `since` parameter, handles `has_more` pagination flag, server error returns failure
- Known gaps: Individual entity GET endpoints (lots, vessels, etc.) not implemented — sync pull covers the mobile use case. Can add if needed for targeted queries.

---

## Sub-Task 5: Sync Engine
**Completed:** 2026-03-18
**Status:** Pending user validation

### Key Decisions
- **`ConnectivityMonitor` is an interface, not expect/actual**: Switched from `expect class` to a plain `interface` so tests can provide fake offline implementations via anonymous objects. Platform-specific classes (`JvmConnectivityMonitor`, `AndroidConnectivityMonitor`, `IosConnectivityMonitor`) implement it. More testable than expect/actual for this use case.
- **State machine uses `StateFlow`**: `SyncState` enum (IDLE → PUSHING → PULLING → ERROR) exposed as `StateFlow<SyncState>` for reactive UI observation. Compose/SwiftUI can collect this directly.
- **Push and pull are atomic per phase**: If push succeeds but pull fails, the push confirmations are preserved (events marked synced in SQLite). The next sync resumes pull only for the events that didn't fail. This prevents duplicate pushes on retry.
- **Paginated pull with `has_more` loop**: Pull continues requesting pages until `has_more` is false. Each page's `synced_at` timestamp becomes the `since` parameter for the next page. Final `synced_at` is stored in SyncState for the next sync cycle.
- **Pull upserts in a single transaction per page**: All entity upserts for one pull page happen in one SQLite transaction. Either the whole page applies or none of it does. Prevents partial state if the app crashes mid-pull.
- **Push drains outbox in batches**: Instead of pushing all pending events in one API call (which could exceed the 100-event server limit), pushes in `DEFAULT_BATCH_SIZE` (50) batches until the outbox is empty. Each batch is a separate API call.
- **`SyncResult` captures both phases**: Callers get a single result with push stats, pull stats, and overall status. Enables the UI to show "2 events synced, 15 entities updated" or "Push failed: connection refused".
- **`SyncScheduler` is a thin coroutine wrapper**: Periodic background sync via `delay()` loop. `syncNow()` for immediate triggers. No platform-specific scheduling (BGAppRefreshTask on iOS, WorkManager on Android) — those are wired in the app layer.

### Deviations from Spec
- **No `ConnectivityMonitor` expect/actual**: Replaced with interface + platform implementations. Same external API, better testability.
- **State machine has ERROR state, not COMPLETE**: Spec listed IDLE → PUSHING → PULLING → COMPLETE/ERROR. Simplified to: success returns to IDLE, failure stays in ERROR. No transient COMPLETE state — callers check the `SyncResult` for success details.
- **`SyncScheduler` doesn't own the CoroutineScope**: Scope is passed to `start()` and `syncNow()` by the caller (typically the app-level scope). This avoids lifecycle management complexity in the shared core.

### Patterns Established
- **Interface over expect/actual for testable abstractions**: When the primary consumer is test code that needs fakes, use an interface. Reserve expect/actual for platform APIs that have fundamentally different implementations (like `DatabaseDriverFactory`, `SecureStorage`).
- **`SyncResult` as a sealed-ish data class**: Instead of throwing exceptions, the sync engine returns a result object with status + details. Callers pattern-match on `SyncResultStatus` and inspect push/pull details.

### Test Summary
- `src/jvmTest/.../sync/SyncEngineTest.kt` — 9 tests covering:
  - Full cycle: push 2 events + pull lots/vessels → local DB updated, sync timestamp stored
  - No pending: skips push, only pulls
  - Partial push failure: per-event failure tracking, failed events stay in outbox
  - Network failure: push error captured, events remain pending, state = ERROR
  - Pull failure after push success: push results preserved, pull error reported
  - Offline: returns OFFLINE status immediately, no API calls
  - Paginated pull: two pages, both applied, final synced_at stored
  - Delta pull: sends stored `since` timestamp from SyncState
  - State transitions: returns to IDLE after success
  - Idempotent push: skipped duplicates marked as synced
- Known gaps: Background sync scheduling not tested (platform-specific coroutine lifecycle). `SyncScheduler` is thin enough to test manually in the app layer.

---
