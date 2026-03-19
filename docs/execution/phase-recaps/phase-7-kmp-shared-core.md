# Phase 7 Recap — KMP Shared Core

> Duration: 2026-03-18 → 2026-03-18
> Task files: `07-kmp-shared-core.md` | INFO: `07-kmp-shared-core.info.md`

---

## Delivered

- **Kotlin Multiplatform shared module** — self-contained `shared/` Gradle project at the repo root with JVM, Android, and iOS targets. Version catalog pins all dependency versions. Gradle wrapper bootstrapped for Gradle 8.10.2. Makefile targets for build, test, coverage, and full QA.
- **Local SQLite database (SQLDelight)** — 8 tables mirroring server-side entities (lots, vessels, barrels, work orders, addition products, user profiles) plus the outbox event queue and sync state store. A 9th table (conflicts) stores unresolved sync conflicts. All queries are type-safe generated Kotlin. INSERT OR REPLACE for state mirrors, plain INSERT for append-only outbox.
- **Event outbox (offline-first pattern)** — every user operation writes a `SyncEvent` to the local `OutboxEvent` table with a client-generated UUID idempotency key. Events wait locally until synced. Retry tracking with 5-attempt ceiling and permanent failure flagging.
- **Ktor API client** — typed HTTP client consuming the Laravel API. Login (Sanctum token), batch event push (`POST /api/v1/events/sync`), unified delta pull (`GET /api/v1/sync/pull`). All methods return `Result<T>` — never throw. Auto-clears auth on 401. Secure token storage via platform-specific `SecureStorage` (in-memory on JVM, EncryptedSharedPreferences on Android, Keychain TODO on iOS).
- **Sync engine** — full push → pull cycle with state machine (IDLE → PUSHING → PULLING → ERROR). Push drains outbox in 50-event batches. Pull is paginated via `has_more` flag. Upserts all pulled entities in a single SQLite transaction per page. Stores `synced_at` for delta pulls. Atomic per phase — push success preserved even if pull fails. Status exposed as `StateFlow` for reactive UI.
- **Conflict resolution** — additive operations (additions, lab analyses) always apply from any device. Destructive operations (transfers, blending, bottling) create user-visible conflicts when the server rejects them (e.g., insufficient volume). Conflicts stored locally with attempted payload, server state, and error message. Users can resolve or dismiss.
- **Clock drift checker** — on app launch, compares device clock to server time. Warns (non-blocking) if drift exceeds 30 seconds. Protects event timestamp ordering for conflict resolution.
- **116 JVM tests** — all run on JVM (no emulator, CI-ready). In-memory SQLite + Ktor MockEngine. Coverage: 90%+ on hand-written code (sync engine 91.8%, API client 89.5%, utilities 88.5%). Overall 72.8% line coverage inflated downward by SQLDelight/kotlinx-serialization generated code.

## Architecture Decisions

- **Gradle root at `shared/`:** KMP module is its own Gradle project, not nested under the PHP/Laravel project. Avoids polluting the Laravel build with Gradle infrastructure.
- **Kotlin 2.0.21 + SQLDelight 2.0.2 + Ktor 2.3.12:** Pinned via version catalog. Ktor 2.x chosen over 3.x for stability. SQLDelight 2.x for native Kotlin 2.0 support.
- **Concrete response classes per endpoint:** Ktor's ContentNegotiation has generic type erasure issues with `ApiEnvelope<T>`. Solved by creating concrete response types (`LoginApiResponse`, `SyncPushApiResponse`, `SyncPullApiResponse`) with the `data` field typed per endpoint. Responses go through Ktor's full plugin pipeline via `response.body<T>()` — no manual parsing.
- **`ConnectivityMonitor` as interface, not expect/actual:** Enables test fakes via anonymous objects. Platform implementations (`JvmConnectivityMonitor`, `AndroidConnectivityMonitor`, `IosConnectivityMonitor`) implement the interface.
- **Server time as NTP proxy:** Instead of raw NTP (requires platform-specific UDP sockets), the clock drift checker uses VineSuite API server time as the reference. Server is NTP-synced, so accuracy is equivalent.
- **PRAGMA foreign_keys = ON:** SQLite doesn't enforce FKs by default. Enabled explicitly in the JVM driver factory and tests.

## Deviations from Spec

- **No raw NTP implementation:** Used server time instead. Same drift detection accuracy without platform-specific networking.
- **No token refresh flow:** 401 → clear auth → redirect to login. Silent re-authentication deferred to Cellar App phase.
- **No `endpoints/` package:** All API methods live on `ApiClient` directly. Only 4 endpoints — separate files would add indirection without benefit.
- **Android target requires SDK:** JVM and iOS targets compile without Android SDK. Android compilation deferred until Cellar App phase when the SDK is needed anyway.

## Patterns Established

- **Version catalog (`libs.versions.toml`):** All dependency versions centralized. New dependencies add entries here, never hardcode versions.
- **expect/actual for platform-native code:** `DatabaseDriverFactory`, `SecureStorage` use this pattern. Interface pattern used when test fakes are needed (`ConnectivityMonitor`).
- **Fixed clock injection for tests:** `EventFactory(clock = fixedClock)` pattern for deterministic timestamps. Used across all sync tests.
- **`Result<T>` for all API methods:** No exceptions from public API surface. Callers pattern-match on success/failure.
- **MockEngine for API tests:** All HTTP tests use Ktor MockEngine with inline response handlers. Validates request structure AND response deserialization in one pass.
- **`SyncResult` for sync cycle reporting:** Structured result with push/pull stats. Enables UI to show "2 events synced, 15 entities updated" or detailed error messages.

## Known Debt

1. **iOS `SecureStorage` uses `NSUserDefaults`, not Keychain** — impact: low — affects: Cellar App iOS security review. TODO in code.
2. **ConflictResolver not wired into SyncEngine push loop** — impact: low — affects: Cellar App conflict UI. Currently standalone; integration is a straightforward follow-up.
3. **`stock_received` events need `volume_gallons`** — carry-over from Phase 6. If KMP emits stock_received events, include the field.
4. **Coverage report includes generated code** — Kover reports 72.8% overall due to SQLDelight/kotlinx-serialization generated methods. Hand-written code is 90%+. Could configure Kover exclusions for generated packages.
