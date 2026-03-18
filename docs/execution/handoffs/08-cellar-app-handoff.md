# Task 8 Handoff — Cellar App (Native Mobile)

> Phases 1–7 complete (Foundation, Production Core, Lab & Fermentation, Inventory, Cost Accounting, TTB Compliance, KMP Shared Core). PHP side: 870+ tests passing, PHPStan level 6 (zero errors), Pint (zero style issues). Kotlin side: 116 JVM tests passing, Kover coverage generated.

## Read Before Coding

1. `docs/README.md` — doc routing table
2. `docs/CONVENTIONS.md` — code patterns (treat as law)
3. `docs/WORKFLOW.md` — dev lifecycle
4. `docs/execution/tasks/08-cellar-app.md` — your task spec (the "Before starting" block at the top has phase-specific pointers)

## What's Relevant From Previous Phases

**Phase 7 (KMP Shared Core):** This is your direct dependency. The `shared/` directory contains the entire offline-first infrastructure: SQLDelight local database (8 entity tables + outbox + conflict store), `EventQueue` for enqueuing operations, `SyncEngine` for push/pull cycles, `ApiClient` for Ktor HTTP, `AuthManager` for Sanctum token management, `ConflictResolver` + `ConflictStore` for handling destructive operation failures, `ClockDriftChecker` for NTP-style warnings. All tested on JVM. Your native apps are UI shells on top of this shared core.

**Phase 1 (Foundation):** Sanctum token auth. Login with `client_type = "cellar_app"` and `device_name`. Token stored via `SecureStorage` (Android: EncryptedSharedPreferences, iOS: Keychain TODO — you'll implement this).

**Phases 2-2b (Production Core + Lab):** The API endpoints the Cellar App consumes: lots, vessels, barrel tracking, work orders, additions, transfers, blending, bottling, lab analysis, fermentation readings. All write through the event log. The KMP `EventFactory` creates events matching these operation types.

**Phases 2c-3 (Inventory, Cost Accounting, TTB):** Not consumed by the Cellar App directly. Server-side only.

## Carry-Over Debt

1. **iOS `SecureStorage` uses `NSUserDefaults`** — needs Keychain Services implementation for production security. The `expect/actual` is stubbed; you need to write the real iOS implementation.
2. **`ConflictResolver` not wired into `SyncEngine`** — the resolver is tested standalone. You'll want to integrate it into the push loop so destructive operation failures automatically create conflicts instead of just incrementing retry counts.
3. **Android SDK not set up** — `make shared-build-all` fails without `ANDROID_HOME`. First sub-task (Android project setup) will fix this naturally.
4. **`stock_received` events need `volume_gallons`** — if the Cellar App creates stock_received events, include this field in the payload for TTB compliance.

## Phase-Specific Notes

- This phase creates a `cellar-app/` directory at the project root with `android/` and `ios/` subdirectories. The `shared/` module is consumed as a dependency.
- Android depends on `:shared` as a Gradle module. You'll need to restructure the Gradle project so the root `settings.gradle.kts` includes both `shared` and `cellar-app/android`.
- iOS consumes the shared core via the `VineSuiteShared.framework` (already configured in `build.gradle.kts` with `isStatic = true`). Use the `embedAndSignAppleFrameworkForXcode` Gradle task.
- The `SyncEngine.state` is a `StateFlow<SyncState>` — collect it in Compose (`collectAsState()`) or SwiftUI (`@Published` wrapper) for the sync indicator.
- `ConnectivityMonitor` is an interface. Implement `AndroidConnectivityMonitor` (already stubbed) and `IosConnectivityMonitor` with real platform APIs. Register for connectivity change callbacks to trigger `SyncScheduler.syncNow()` on reconnect.
- All event payload shapes are documented in the test fixtures: `tests/Fixtures/ttb/scenario_*.json` and in `docs/references/event-log.md`.
- The Cellar App's primary workflow: view work orders → navigate to vessel → perform operation (addition, racking, transfer) → scan barrel QR → operation writes to outbox → sync engine pushes when online.

## Rules

- **One sub-task at a time.** Complete it, write the INFO entry, run tests, then stop and check in with the human before starting the next sub-task. Do not batch multiple sub-tasks.
- Follow sub-task order. They're sequenced for dependencies.
- Write the INFO file after every sub-task: `docs/execution/completed/08-cellar-app.info.md`
- Don't break existing tests. Run `make testsuite` for PHP, `make shared-test` for KMP.
- New ideas go to `docs/ideas/`, not into scope.
- Tech stack is locked.

## Go

Read the files listed above. Then start Sub-Task 1 of `docs/execution/tasks/08-cellar-app.md`.
