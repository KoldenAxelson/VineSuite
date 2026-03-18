# Medium Priority Refactors

Better done before the codebase has more consumers and modules depending on current patterns. Harder to fix later, but nothing is broken today.

---

## 1. Add HttpTimeout plugin to Ktor client

**Problem:** The `ApiClient`'s Ktor `HttpClient` has no request/connect/socket timeouts configured. If the server hangs or the network is slow, the HTTP call blocks indefinitely. The `SyncEngine` push/pull phases will hang, the state machine will be stuck in `PUSHING` or `PULLING`, and subsequent sync attempts will return `ALREADY_RUNNING`.

**Where:** `shared/src/commonMain/kotlin/com/vinesuite/shared/api/ApiClient.kt` — the default `HttpClient` configuration (line ~52).

**Fix:**
1. Add `install(HttpTimeout) { requestTimeoutMillis = 30_000; connectTimeoutMillis = 10_000; socketTimeoutMillis = 30_000 }` to the default HttpClient
2. The `apiCall` wrapper already catches exceptions — `HttpRequestTimeoutException` will be caught and returned as `Result.failure`
3. Add test: MockEngine with a delayed response exceeding timeout → verify Result.failure returned

---

## 2. Add exponential backoff to SyncScheduler

**Problem:** `SyncScheduler` runs `sync()` every 5 minutes regardless of outcome. If the server is down, the scheduler fires a request every 5 minutes forever, all of which fail and increment retry counts on outbox events. Should back off on repeated failures (5 min → 10 min → 20 min → cap at 30 min) and reset to normal interval on success.

**Where:** `shared/src/commonMain/kotlin/com/vinesuite/shared/sync/SyncScheduler.kt` — the `start()` method's `while(true) { delay(...) }` loop.

**Fix:**
1. Track consecutive failure count in `SyncScheduler`
2. On `SyncResultStatus.SUCCESS` → reset to `backgroundIntervalMs`
3. On failure → double the interval, cap at 30 minutes
4. Add test: simulate 3 consecutive failures → verify interval increases, then succeed → verify interval resets

---

## 3. Add concurrent sync guard with mutex

**Problem:** `SyncEngine.sync()` checks `if (_state.value == PUSHING || PULLING) return ALREADY_RUNNING` but this is a check-then-act race. Two coroutines could both read `IDLE`, both proceed past the guard, and both start pushing. With `MutableStateFlow`, the state update is atomic but the read-then-write sequence is not.

**Where:** `shared/src/commonMain/kotlin/com/vinesuite/shared/sync/SyncEngine.kt` — top of `sync()` method.

**Fix:**
1. Add a `Mutex` to `SyncEngine`: `private val syncMutex = Mutex()`
2. Wrap `sync()` body in `syncMutex.withLock { ... }` or use `tryLock()` to return `ALREADY_RUNNING` immediately if another sync is in progress
3. Add test: launch two concurrent `sync()` calls → verify only one runs, the other returns `ALREADY_RUNNING`

---

## 4. Add logging interface to shared core

**Problem:** The shared core has zero logging. When debugging sync issues on a user's device, there's no way to see what happened — no push/pull timestamps, no retry counts, no error messages, no drift amounts. The spec mentions "Log the drift amount for debugging" which was deferred.

**Where:** New file: `shared/src/commonMain/kotlin/com/vinesuite/shared/util/Logger.kt`

**Fix:**
1. Create a `Logger` interface with `debug(tag, message)`, `warn(tag, message)`, `error(tag, message, throwable?)`
2. Platform implementations: Android → `android.util.Log`, iOS → `os_log`, JVM → `println` (or SLF4J)
3. Add logging to: `SyncEngine` (push/pull start/end, counts), `EventQueue` (enqueue, retry), `ApiClient` (request URLs, status codes), `ClockDriftChecker` (drift amount)
4. Log tag convention: `"VineSuite.SyncEngine"`, `"VineSuite.ApiClient"`, etc.

---

## 5. Add missing FK constraint violation test

**Problem:** We test that barrel rows cascade-delete when their parent vessel is deleted. But we don't test the inverse: inserting a barrel with a non-existent `vessel_id` should fail (since `PRAGMA foreign_keys = ON` is enabled). This verifies FK enforcement works in both directions.

**Where:** `shared/src/jvmTest/kotlin/com/vinesuite/shared/database/LocalDatabaseTest.kt`

**Fix:**
1. Add test: `barrelInsertWithNonExistentVesselFails()` — insert a barrel pointing to a vessel_id that doesn't exist → assert exception thrown
2. This validates the FK constraint is actually enforced, not just declared

---

## 6. Configure Kover exclusions for generated code

**Problem:** Kover reports 72.8% line coverage overall. The gap is almost entirely SQLDelight-generated query wrappers and kotlinx-serialization-generated methods (`copy()`, `hashCode()`, `componentN()`, etc.). These inflate the denominator and make it impossible to verify the 90% target on hand-written code.

**Where:** `shared/build.gradle.kts` — the `kover {}` block.

**Fix:**
1. Add Kover class exclusions for generated packages:
```kotlin
kover {
    currentProject {
        createVariant("jvmOnly") {
            add("jvm")
        }
    }
    reports {
        filters {
            excludes {
                classes("*.database.*Queries*", "*.database.*Impl*", "*.database.*Adapter*")
            }
        }
    }
}
```
2. Re-run `make shared-test-coverage` and verify hand-written code is 90%+
