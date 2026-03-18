# Low Priority Refactors

Quality-of-life improvements. Safe to do at any time, even after the codebase is mature. No risk from deferring.

---

## 1. Replace vacuous SmokeTest assertions

**Problem:** Two of the four SmokeTests have assertions that can never fail:
- `coroutinesWired()` assigns a string then asserts it equals itself. Proves the test runner works, not coroutines.
- `datetimeWired()` asserts `Clock.System.now().toString().isNotBlank()`. `Instant.toString()` always returns a non-blank string.

These were useful during Sub-Task 1 scaffolding to verify dependencies resolved. Now that we have 116 real tests, they add nothing.

**Where:** `shared/src/jvmTest/kotlin/com/vinesuite/shared/SmokeTest.kt`

**Fix:** Either delete the two vacuous tests (keeping `jvmPlatformResolves` and `serializationWired` which do test real behavior), or replace with assertions that actually exercise the library APIs (e.g., `runTest { delay(1); assertTrue(true) }` for coroutines, `Clock.System.now().plus(1.days)` for datetime).

---

## 2. Reduce test redundancy in EventQueueTest

**Problem:** Several test pairs cover near-identical code paths:
- `markSyncedRemovesFromPending` / `markSyncedBatchRemovesMultiple` — the batch version subsumes the single version
- `recordFailureIncrementsRetryCount` / `failedEventsStillInPending` — second test is a subset of the first

Not harmful (redundant tests are better than missing tests), but adds test runtime and reading burden.

**Where:** `shared/src/jvmTest/kotlin/com/vinesuite/shared/sync/EventQueueTest.kt`

**Fix:** Keep both pairs but add a comment noting the intentional overlap, or merge each pair into a single test with more assertions. Don't delete — redundancy in the outbox tests is cheap insurance.

---

## 3. Add environment configuration for baseUrl

**Problem:** `ApiClient` takes `baseUrl` as a constructor parameter, which is technically configurable. But there's no structured way for the app layer to switch between environments (local dev, staging, production). The Cellar App will need this.

**Where:** `shared/src/commonMain/kotlin/com/vinesuite/shared/api/ApiClient.kt`

**Fix:**
1. Create `shared/src/commonMain/kotlin/com/vinesuite/shared/api/ApiConfig.kt` with an enum or sealed class:
```kotlin
sealed class ApiEnvironment(val baseUrl: String) {
    object Production : ApiEnvironment("https://api.vinesuite.com/api/v1")
    object Staging : ApiEnvironment("https://staging-api.vinesuite.com/api/v1")
    data class Custom(val url: String) : ApiEnvironment(url)
}
```
2. App layer picks the environment at startup and passes it to `ApiClient`

---

## 4. Add `observeUnresolved()` Flow collection test

**Problem:** `ConflictStore.observeUnresolved()` returns a `Flow<List<LocalConflict>>` using SQLDelight's `asFlow().mapToList()`. This is the primary way the UI will observe conflict state. It's declared and compiles, but no test ever collects the Flow to verify it emits correctly when conflicts are added/resolved.

**Where:** `shared/src/jvmTest/kotlin/com/vinesuite/shared/sync/ConflictResolverTest.kt`

**Fix:**
1. Add test: launch a collector on `observeUnresolved()`, insert a conflict, verify emission, resolve it, verify updated emission
2. Uses `Turbine` or manual `take(n)` collection with `runTest`

---

## 5. Strengthen 50-event sync test mock response

**Problem:** `SyncEngineTest.fiftyEventsOfflineThenSyncAll()` builds the mock push response by counting regex matches in the request body and generating indexed JSON results. This is fragile — a change in serialization format could produce incorrect mock responses that still pass the count-based assertions.

**Where:** `shared/src/jvmTest/kotlin/com/vinesuite/shared/sync/SyncEngineTest.kt` — `fiftyEventsOfflineThenSyncAll()` (line ~464)

**Fix:** Deserialize the request body in the mock handler using `Json.decodeFromString<SyncPushRequest>()`, count `request.events.size`, and build results from the actual event data. This makes the mock response structurally correct and validates the request serialization as a side effect.
