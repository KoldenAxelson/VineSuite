# Cellar App — Completion Record

> Task spec: `docs/execution/tasks/08-cellar-app.md`
> Phase: 8

---

## Sub-Task 0: Carry-Over Debt Cleanup
**Completed:** 2026-03-18
**Status:** Pending user validation

### Debt Items Assessed

| # | Item | Status | Action |
|---|------|--------|--------|
| 1 | iOS `SecureStorage` uses `NSUserDefaults` | **Fixed** | Rewrote with Keychain Services |
| 2 | `ConflictResolver` not wired into `SyncEngine` | **Already done** | Wired in Phase 7 Sub-Task 8 — `processPushResult()` called on line 154 of SyncEngine.kt, covered by `failedDestructiveOpCreatesConflict` and `failedAdditiveOpDoesNotCreateConflict` tests |
| 3 | Android SDK not set up (`ANDROID_HOME`) | **Deferred** | Resolves naturally in Sub-Task 1 (Android project setup) |
| 4 | `stock_received` events need `volume_gallons` | **Documented** | Added `docs/ideas/stock-received-volume-gallons.md` — only relevant if a stock receiving screen is added to the Cellar App |

### Key Decisions

- **Keychain Services over third-party wrapper**: Used the Security framework directly (`SecItemAdd`, `SecItemCopyMatching`, `SecItemUpdate`, `SecItemDelete`) rather than pulling in a library like KeychainAccess. Zero new dependencies. The `kotlinx.cinterop` layer gives clean access to the C API from Kotlin/Native.
- **Service-scoped `clear()`**: The old NSUserDefaults implementation hardcoded 3 key names in `clear()` (missing 3 of AuthManager's 6 keys). The Keychain implementation queries by `kSecAttrService = "com.vinesuite.cellar"` and deletes all matching items. No key list to maintain.
- **Update-first strategy for `set()`**: Calls `SecItemUpdate` first, falls back to `SecItemAdd` on `errSecItemNotFound`. Avoids the duplicate-item error that occurs if you blindly call `SecItemAdd` on an existing key.
- **`toCFDictionary()` bridge helper**: Maps Kotlin `Map<Any?, Any?>` to `CFDictionaryRef` via `CFBridgingRetain` + `CFAutorelease`. Keeps each Keychain call readable — one dictionary literal per operation.

### Deviations from Spec
- None. This sub-task was not in the original spec — it's a pre-work cleanup agreed with the human before starting Sub-Task 1.

### Patterns Established
- **Keychain service name convention**: `com.vinesuite.cellar` groups all Cellar App secrets. If other apps need separate storage, they use different service names.
- **No hardcoded key lists in `clear()`**: Service-scoped deletion means new keys added to `AuthManager` are automatically covered.

### Test Summary
- 121 JVM tests passing (0 failures, 0 ignored). The iOS SecureStorage change is iosMain-only and doesn't affect JVM tests. It will be validated when the iOS project builds in Sub-Task 2.
- No existing tests broken.

---
