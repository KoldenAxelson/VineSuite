# Cellar App (Native Mobile)

## Phase
Phase 5

## Dependencies
- `07-kmp-shared-core.md` — MUST be complete. The shared core provides: SQLDelight local DB, event queue, sync engine, Ktor API client, conflict resolution. The Cellar App is a native UI shell on top of this.
- `01-foundation.md` — API auth (Sanctum)
- `02-production-core.md` — API endpoints for lots, vessels, work orders, additions, transfers, barrel ops
- `03-lab-fermentation.md` — API endpoints for lab analysis and fermentation data entry

> **Pre-implementation check:** This spec predates completed phases. Before starting, load `CONVENTIONS.md` and review phase recaps for any dependency phases listed above. Patterns, service boundaries, and data model decisions may affect assumptions in this spec.

## Goal
Build the native mobile app for cellar floor operations. iOS (SwiftUI) + Android (Jetpack Compose), phone-optimized. Offline-first — a cellar hand must be able to complete a full shift of work orders, log additions, record transfers, and scan barrel QR codes without any internet connectivity. This app exploits InnoVint's iOS-only gap by shipping on both platforms with truly native UX.

## Sub-Tasks

### 1. Android project setup (Jetpack Compose)
**Description:** Create the Android project that depends on the KMP shared module. Configure Compose, navigation, dependency injection (Koin or Hilt), and the shared core integration.
**Files to create:**
- `cellar-app/android/` — Android project structure
- `cellar-app/android/build.gradle.kts` — depends on `:shared`
- `cellar-app/android/app/src/main/kotlin/.../CellarApp.kt` — Application class
- `cellar-app/android/app/src/main/kotlin/.../di/` — DI setup
- `cellar-app/android/app/src/main/kotlin/.../navigation/NavGraph.kt`
**Acceptance criteria:**
- App builds and launches on Android emulator
- Shared core is accessible (can query local SQLite via shared code)
- Navigation framework in place (Compose Navigation)
- Theme and styling consistent with VineSuite brand
**Gotchas:** Target Android API 28+ (covers 95%+ of devices). Use Material 3 for Compose components.

### 2. iOS project setup (SwiftUI)
**Description:** Create the iOS project that consumes the KMP shared framework. Configure SwiftUI views, navigation, and shared core integration.
**Files to create:**
- `cellar-app/ios/` — Xcode project
- `cellar-app/ios/CellarApp/CellarApp.swift` — App entry point
- `cellar-app/ios/CellarApp/Navigation/` — SwiftUI NavigationStack
- `cellar-app/ios/CellarApp/DI/` — dependency setup
**Acceptance criteria:**
- App builds and launches on iOS Simulator
- Shared core KMP framework linked and accessible
- Navigation structure mirrors Android app
- Uses native iOS patterns (NavigationStack, sheets)
**Gotchas:** KMP framework integration with Xcode can be finicky. Use the KMP Xcode plugin or cocoapods/SPM integration. Test on both simulator and physical device early.

### 3. Login and authentication screen
**Description:** Build the login screen on both platforms. Uses shared core's AuthManager to authenticate and store the Sanctum token.
**Files to create:**
- Android: `LoginScreen.kt`
- iOS: `LoginView.swift`
**Acceptance criteria:**
- Email/password login
- Token stored securely (EncryptedSharedPreferences on Android, Keychain on iOS)
- Biometric login option (FaceID / fingerprint) after initial login
- Handles auth errors gracefully (wrong password, network error)
- Auto-login on subsequent launches if token is valid
**Gotchas:** Biometric auth protects access to the stored token — it doesn't replace the token. If biometric fails, fall back to password.

### 4. Work order list and detail screens
**Description:** Build the primary workflow screen — list of assigned work orders with status, tap to view details and complete.
**Files to create:**
- Android: `WorkOrderListScreen.kt`, `WorkOrderDetailScreen.kt`
- iOS: `WorkOrderListView.swift`, `WorkOrderDetailView.swift`
**Acceptance criteria:**
- Shows work orders assigned to current user (filterable by status, date)
- "Today" view shows what's due today
- Tap to open detail: operation type, lot info, vessel info, notes
- "Complete" button with completion notes field
- Completion writes event to local outbox (via shared core EventQueue)
- Works fully offline — work orders loaded from local SQLite
- Completed work orders show completed state immediately (optimistic UI)
**Gotchas:** Work orders may have been assigned before the app was offline. The local cache must include all assigned work orders. Sync pulls the latest assignments.

### 5. Addition logging screen
**Description:** Screen for logging cellar additions (SO2, nutrients, fining agents, etc.). Selects from the product library, enters amount, logs to event queue.
**Files to create:**
- Android: `AdditionScreen.kt`
- iOS: `AdditionView.swift`
**Acceptance criteria:**
- Select lot (searchable dropdown from local cache)
- Select product from addition product library (cached locally)
- Enter rate and total amount
- Auto-calculates: if rate entered per gallon × lot volume = total
- Writes `addition_made` event to outbox
- SO2 additions update the running total displayed on the lot
- Works fully offline
**Gotchas:** Addition product library must be synced to local SQLite on app launch. If a new product is added in the portal while the app is offline, it won't appear until next sync — acceptable tradeoff.

### 6. Transfer recording screen
**Description:** Record wine transfers between vessels (tank to tank, barrel to tank, etc.).
**Files to create:**
- Android: `TransferScreen.kt`
- iOS: `TransferView.swift`
**Acceptance criteria:**
- Select source vessel (shows current lot and volume)
- Select target vessel (shows capacity and current contents)
- Enter volume transferred
- Enter variance/loss
- Writes `transfer_executed` event to outbox
- Optimistic local update: source volume decreases, target increases
- Volume validation: cannot transfer more than source contains (local check)
- Works fully offline
**Gotchas:** Volume validation is done locally first (optimistic), then validated on server during sync. If server finds a conflict (someone else transferred from the same vessel), the conflict resolution flow kicks in.

### 7. Barrel QR scan and operations
**Description:** Use the device camera to scan barrel QR codes, then perform barrel operations (fill, top, rack, sample).
**Files to create:**
- Android: `BarrelScanScreen.kt` (CameraX integration)
- iOS: `BarrelScanView.swift` (AVFoundation integration)
- Android: `BarrelOperationScreen.kt`
- iOS: `BarrelOperationView.swift`
**Acceptance criteria:**
- Camera opens and scans QR/barcode
- QR code resolves to a barrel record from local cache
- Shows barrel details: cooperage, toast, current lot, volume, oak type
- Operations available: fill, top, rack, sample
- Each operation writes the appropriate event to outbox
- Bulk mode: scan multiple barrels for the same operation (e.g., top all barrels in a row)
**Gotchas:** Use native camera APIs (CameraX on Android, AVFoundation on iOS) — NOT a cross-platform camera plugin. Native gives better scan performance and reliability.

### 8. Lab analysis data entry screen
**Description:** Quick lab data entry from the cellar floor. Select lot, enter test results.
**Files to create:**
- Android: `LabEntryScreen.kt`
- iOS: `LabEntryView.swift`
**Acceptance criteria:**
- Select lot
- Enter one or more test types with values (pH, TA, VA, SO2, Brix, etc.)
- Writes `lab_analysis_entered` event(s) to outbox
- Works offline
**Gotchas:** Lab entry should be fast — common tests pre-selected, numeric keypad by default. Don't make the cellar hand navigate through multiple screens to enter three numbers.

### 9. Fermentation data entry screen
**Description:** Daily fermentation data entry — temperature, Brix/density, SO2, notes.
**Files to create:**
- Android: `FermentationEntryScreen.kt`
- iOS: `FermentationEntryView.swift`
**Acceptance criteria:**
- Select lot with active fermentation
- Enter: temperature, Brix (or SG), free SO2, notes
- Shows mini fermentation curve (last 7 days of data)
- Writes `fermentation_data_entered` event to outbox
- Works offline
**Gotchas:** During harvest, a cellar hand enters fermentation data for 10-20 lots per day. The UI must be optimized for rapid data entry — minimal taps, smart defaults, quick navigation between lots.

### 10. Lot detail and timeline screen
**Description:** View lot details and full event timeline from the local cache.
**Files to create:**
- Android: `LotDetailScreen.kt`
- iOS: `LotDetailView.swift`
**Acceptance criteria:**
- Shows lot info: name, variety, vintage, volume, status, current vessel(s)
- Event timeline: chronological list of all events for this lot
- Current lab values (most recent pH, TA, VA, SO2, Brix)
- Offline: shows cached data. Historical data loads on-demand when online.
**Gotchas:** Full event history may be too large for local cache (lots with 500+ events over 2 years). Cache the last 30 days, fetch older data on-demand when online.

### 11. Sync status indicator and conflict UI
**Description:** Show sync status throughout the app — how many events are pending, last sync time, and any conflicts requiring attention.
**Files to create:**
- Android: `SyncStatusBar.kt`, `ConflictListScreen.kt`
- iOS: `SyncStatusBar.swift`, `ConflictListView.swift`
**Acceptance criteria:**
- Persistent indicator showing: online/offline, pending event count, last sync time
- Sync-in-progress animation
- Conflict badge showing count of unresolved conflicts
- Conflict detail screen: what was attempted, what went wrong, options to retry or dismiss
**Gotchas:** Don't make the sync indicator alarming — a cellar hand should work confidently offline without anxiety about "is my data safe." Green = synced, yellow = pending, red = conflict needs attention.

### 12. Push notification handling
**Description:** Register for push notifications and handle them (new work order assigned, sync conflict, etc.).
**Files to create:**
- Android: Firebase Cloud Messaging setup
- iOS: APNs setup
- Server: `api/app/Services/PushNotificationService.php`
**Acceptance criteria:**
- App registers for push on first login
- Device token sent to server and stored per user
- Server can send push: new work order, sync conflict resolved, etc.
- Tapping notification navigates to relevant screen
**Gotchas:** Push requires Firebase (Android) and APNs (iOS) — both need developer accounts and provisioning profiles. Add this after core functionality works.

### 13. Offline integration test (real cellar scenario)
**Description:** A manual + automated test scenario simulating a real cellar shift without internet.
**Test procedure:**
1. Sync app while online (pull all data)
2. Enable airplane mode
3. Complete 5 work orders with completion notes
4. Log 3 additions (SO2, nutrient, acid)
5. Record 2 transfers between vessels
6. Scan 3 barrels and log topping
7. Enter fermentation data for 2 lots
8. Enter lab data for 1 lot
9. Disable airplane mode
10. Verify all events sync to server
11. Verify server materialized state matches expected results
12. Verify no data loss or duplicates
**Acceptance criteria:**
- All 16+ events created offline sync successfully
- No duplicate events on server (idempotency works)
- Materialized state tables are correct after sync
- UI reflects synced state (pending indicators clear)
**Gotchas:** This test should be run on both a physical Android device and a physical iPhone. Emulator offline mode is not a perfect simulation of real network loss.

## API Endpoints (Consumed)
All endpoints from `02-production-core.md` and `03-lab-fermentation.md`, plus the event sync endpoint from `01-foundation.md`.

## Events
All event types from `02-production-core.md` and `03-lab-fermentation.md` — generated locally on the device, synced to server.

## Testing Notes
- **Unit tests (KMP JVM):** Already covered in `07-kmp-shared-core.md`
- **Android instrumented tests:** Core flow — login → view work orders → complete one → verify event in outbox. Run on CI emulator.
- **iOS XCTest:** Same core flow as Android. Run on CI simulator.
- **Manual offline test:** Sub-task 13 above — this is the single most critical test for the cellar app.
- **Performance:** App launch time < 3 seconds with 500 cached lots. Barrel QR scan recognition < 2 seconds.
