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
