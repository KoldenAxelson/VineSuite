# High Priority Refactors

Do these before the next phase of development begins. Deferring these creates compounding debt or audit gaps.

---

## 1. Wire ConflictResolver into SyncEngine push loop

**Problem:** `ConflictResolver` and `ConflictStore` are fully built and tested but never called from `SyncEngine`. When a destructive operation (transfer, blend, bottling) fails during push, `SyncEngine` calls `eventQueue.recordFailure()` and moves on. No `LocalConflict` row is ever created. The entire conflict UI flow is dead code.

**Where:** `shared/src/commonMain/kotlin/com/vinesuite/shared/sync/SyncEngine.kt` — the `pushEvents()` method, around the `"failed"` branch (line ~129).

**Fix:**
1. Add `ConflictResolver` as a constructor parameter to `SyncEngine`
2. In the `"failed"` branch of the push results loop, call `conflictResolver.processPushResult(outboxEvent, pushResult)` before `eventQueue.recordFailure()`
3. The server's error response should include the current entity state — pass it as `serverState`. If the server doesn't include it yet, pass `"{}"` as a placeholder and file an idea for the server to return it.
4. Add integration test: enqueue a destructive `transfer` event → mock server returns `"failed"` → verify `LocalConflict` row created AND `OutboxEvent` retry count incremented.

**Why urgent:** The Cellar App (Task 8) will have cellar workers doing transfers and racking on multiple devices. Without this wiring, conflicts will silently retry 5 times and then sit as permanently failed events with no user-visible notification. Workers will think their operations succeeded.

---

## 2. Configure SQLDelight schema migrations

**Problem:** The database uses `VineSuiteDatabase.Schema.create(driver)` — one-shot creation, no versioning. Any schema change in a future release (adding a column, new table, index change) would require wiping the local database, which loses all unsynced outbox events and cached state.

**Where:** `shared/build.gradle.kts` — the `sqldelight {}` block. `shared/src/jvmMain/kotlin/com/vinesuite/shared/database/DatabaseDriverFactory.jvm.kt` — the `createDriver()` method.

**Fix:**
1. Add `deriveSchemaFromMigrations.set(true)` to the SQLDelight database config OR set up the initial schema as version 1
2. Create `shared/src/commonMain/sqldelight/com/vinesuite/shared/database/migrations/1.sqm` with the current schema
3. Update all `DatabaseDriverFactory` implementations to call `VineSuiteDatabase.Schema.migrate(driver, oldVersion, newVersion)` when the database already exists
4. Add `verifyMigrations.set(true)` to the Gradle config so CI catches migration errors
5. Add a test that creates a v1 database, runs a no-op migration, and verifies the schema is intact

**Why urgent:** The first app store release locks in the schema version. If we ship without migration support, the first schema change post-launch requires a database wipe — which means losing unsynced events. Must be configured before the Cellar App ships.

---

## 3. Add `retryFailed()` method to EventQueue

**Problem:** Spec requires `EventQueue.retryFailed()` to "re-queue failed events with incremented retry count." The building blocks exist (`getRetryable()`, `resetRetry()`, `recordFailure()`) but there's no single method matching the spec's API contract. The SyncEngine push loop handles retry implicitly (failed events stay in the outbox with `synced=0`), but there's no explicit method for the app layer to trigger a retry of all failed events at once.

**Where:** `shared/src/commonMain/kotlin/com/vinesuite/shared/sync/EventQueue.kt`

**Fix:**
1. Add `retryFailed(): Int` method that: selects all events where `retry_count > 0 AND retry_count < MAX_RETRY_COUNT AND synced = 0`, resets their `retry_count` to 0 and clears `last_error`, returns the count of events reset
2. This gives the app layer a "retry all failed" button for the conflict/error UI
3. Add test: enqueue 3 events, fail 2 of them (one at retry_count=3, one at retry_count=5), call `retryFailed()`, verify only the one under max was reset

**Why urgent:** The Cellar App's error UI needs this. Without it, the only way to retry a failed event is `resetRetry(eventId)` one at a time, which requires the app to know individual event IDs.

---

## 4. Fix vacuous `loginHandlesNetworkError` test

**Problem:** The test asserts `authManager.isAuthenticated() == false` after a failed login attempt. But `authManager` was never authenticated — it starts fresh in `@BeforeTest`. The assertion proves the initial state, not that the network error prevented authentication. This test would still pass if the error handling were completely broken.

**Where:** `shared/src/jvmTest/kotlin/com/vinesuite/shared/api/ApiClientTest.kt` — `loginHandlesNetworkError()` (line ~116)

**Fix:**
1. Pre-authenticate: call `authManager.storeAuth(token, user, tenantId)` before the failed login attempt
2. Attempt login (which fails due to network error)
3. Assert auth is still intact (network error during login should NOT clear existing auth — only 401 should)
4. This actually tests a real behavioral question: should a failed login clear existing auth? The answer should be no.

**Why urgent:** A test that can't fail gives false confidence. The behavioral question (does failed login clear existing auth?) needs an explicit answer before the Cellar App ships.
