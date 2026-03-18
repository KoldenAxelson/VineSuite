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
