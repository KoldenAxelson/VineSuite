# Testing & Logging Standards

What to test, what to log, and what to skip — across every surface of VineSuite.

This guide applies to all languages in the stack: PHP (Laravel API), Kotlin (KMP shared core + Android), Swift (iOS), and TypeScript (widgets + VineBook). The principles are universal; the tooling section at the end covers language-specific details.

---

## Testing Philosophy

Not everything deserves a test. Tests are documentation — they tell the next developer (or AI session) what behavior is guaranteed. Write tests for behavior that would be expensive to fix if broken. Skip tests for behavior that's obvious on sight or covered by the framework itself.

The guiding question: **"If someone quietly broke this, how long until we'd notice and how bad would the damage be?"** If the answer is "immediately, and it's catastrophic," that's Tier 1. If the answer is "maybe never, and it doesn't matter," don't write a test for it.

---

## Test Tiers

### Tier 1 — Must Have (block the PR if missing)

These protect money, data integrity, compliance, and core business logic. If any of these break silently, a winery loses revenue, gets a TTB violation, or ships corrupted data to a customer.

**What falls here:**
- Event log writes — every operation that should produce an event gets a test verifying the event was written with the correct `entity_type`, `operation_type`, and payload structure
- Financial calculations — cost-per-unit, COGS rollup, tax computation, payment totals, club billing amounts, gift card balance math
- TTB compliance — report generation, volume tracking, bonded/taxpaid transitions, 5120.17 form field calculations
- Inventory math — additions, subtractions, transfers, blend proportions, unit conversions (gallons ↔ liters ↔ cases)
- Authentication and authorization — login/logout, token issuance, role gates (owner can do X, cellar_hand cannot), tenant isolation (Tenant A cannot see Tenant B's data)
- Payment flows — charge, refund, partial refund, stored card usage, webhook signature verification
- Sync engine — event outbox drain, idempotency key dedup, conflict resolution, offline queue ordering
- Data migrations — any migration that transforms existing data (not just schema changes)

**Test type:** Integration/Feature tests that exercise the full request-to-database path. Mocking external APIs (Stripe, QuickBooks) is fine; mocking your own services is not.

**Coverage target:** 100% of Tier 1 scenarios. No exceptions, no "we'll add it later."

### Tier 2 — Should Have (expected in PR, negotiable if time-boxed)

These protect user-facing workflows and catch regressions in standard CRUD operations. Important but not catastrophic if briefly broken.

**What falls here:**
- API endpoint contracts — correct HTTP status codes, response shape, pagination, filtering, sorting
- Filament resource CRUD — create, read, update, soft-delete for each admin resource (use Livewire test helpers)
- Service layer business logic — non-financial business rules (e.g., "a lot in FERMENTING status cannot be marked BOTTLED without going through AGING first")
- Notification dispatch — verify the right notification class is dispatched to the right channel for the right event (not that the email renders correctly)
- Scheduled jobs — verify jobs run, process the expected records, and handle empty state gracefully
- KMP repository layer — verify SQLDelight queries return expected results, offline cache hydrates correctly
- Webhook ingestion — verify incoming webhooks from external services are validated and dispatched to the right handler

**Test type:** Mix of unit and integration. Unit tests for pure logic (status transitions, validators). Integration tests for anything touching the database or external boundaries.

**Coverage target:** 80%+ of Tier 2 scenarios. Skip if the logic is truly trivial (a getter that returns a field).

### Tier 3 — Nice to Have (don't write unless the code is unusually complex)

These cover code that's either framework-standard, visually verifiable, or so simple that a test would just repeat the implementation.

**What falls here:**
- Simple model accessors, mutators, and scopes (unless they contain math or branching logic)
- Filament form field definitions and table column definitions (the framework tests these)
- View/Blade rendering (test the logic feeding the view, not the view itself)
- Simple config retrieval
- Migration structure (schema assertions — the migration either runs or it doesn't)
- CSS/styling, layout concerns
- Factory definitions (factories are test infrastructure, not test subjects)

**When Tier 3 gets promoted:** If a "simple" accessor has a conditional (`return $this->is_club_member ? $this->discount_price : $this->retail_price`), that's Tier 2 business logic. The tier is about the complexity of the behavior, not the type of code.

### What to Never Test

- Framework behavior (Laravel's `belongsTo()` works — don't test that it returns the right model)
- Third-party package internals (don't test that Spatie Permission assigns roles correctly — test that YOUR seeder creates the roles you expect)
- Private methods directly (test the public method that calls them)
- Constructor assignment (if a service takes `EventLogger` in its constructor, don't test that `$this->logger` is set)

---

## Logging Standards

### Log Levels

VineSuite uses the standard RFC 5424 severity levels. Here's what each level means in this project specifically:

| Level | When to Use | Examples | Alert? |
|-------|-------------|----------|--------|
| **EMERGENCY** | System is unusable | Database connection pool exhausted, tenant schema creation loop | PagerDuty |
| **CRITICAL** | Action must be taken immediately | Payment processor unreachable during club billing run, event log write failure | PagerDuty |
| **ERROR** | Runtime error that doesn't require immediate action | Stripe webhook signature invalid, sync batch failed for one tenant, accounting push failed | Error tracker (Sentry/Flare) |
| **WARNING** | Exceptional occurrence that isn't an error | API rate limit approaching 80%, tenant approaching storage quota, deprecated endpoint called | Error tracker |
| **NOTICE** | Normal but significant events | New tenant provisioned, phase migration completed, club billing run started/finished | Log aggregator |
| **INFO** | Interesting events | User logged in, payment processed, sync batch completed, scheduled report generated | Log aggregator |
| **DEBUG** | Detailed debug information | SQL queries (via telescope), event payload contents, sync conflict resolution decisions | Local/dev only |

### What to Log (and What Not To)

**Always log (INFO or above):**
- Every external API call result (Stripe, QuickBooks, Xero, Anthropic) — log the operation, status code, and relevant IDs (never log request/response bodies containing PII or secrets)
- Tenant lifecycle events — provisioning, plan changes, suspension, archival
- Payment outcomes — charge success/failure, refund processed, dispute opened (log amount + last4, never full card number)
- Sync operations — batch start/finish, conflict resolutions, failed items with entity IDs
- Background job completion — job class, tenant ID, duration, outcome
- Auth events — login, logout, failed login attempts, role changes, token issuance

**Never log:**
- Full API keys, tokens, or secrets (log `sk_...XXXX` masked format if needed for debugging)
- User passwords or password reset tokens
- Full credit card numbers (last 4 only)
- Full request/response bodies from external APIs (they may contain PII)
- PHI or health-related data (not relevant now but future-proofing)
- Individual SQL queries in production (use DEBUG level, which is off in prod)

**Log judiciously (WARNING or DEBUG):**
- Performance concerns — queries over 500ms, jobs over 30s, sync batches over 60s
- Retry attempts — log each retry with attempt number and backoff duration
- Feature flag evaluations — if tier gating blocks a request, log it once per session not per request

### Structured Logging Format

All log entries should include context fields, not string interpolation. This makes logs searchable and parseable.

**PHP (Laravel):**
```php
// Good — structured context
Log::info('Payment processed', [
    'tenant_id' => $tenant->id,
    'order_id' => $order->id,
    'amount_cents' => $charge->amount,
    'processor' => 'stripe',
    'stripe_charge_id' => $charge->id,
    'duration_ms' => $elapsed,
]);

// Bad — string interpolation
Log::info("Payment of {$charge->amount} processed for order {$order->id}");
```

**Kotlin (KMP shared core):**
```kotlin
// Good — structured via key-value pairs
logger.info("Sync batch completed") {
    put("tenant_id", tenantId)
    put("events_sent", sentCount)
    put("events_failed", failedCount)
    put("duration_ms", elapsed)
}

// Bad
logger.info("Sent $sentCount events for tenant $tenantId in ${elapsed}ms")
```

**TypeScript (Widgets / VineBook):**
```typescript
// Good — structured
console.info('Widget initialized', {
    widgetType: 'reservation',
    tenantSlug: config.slug,
    loadTimeMs: performance.now() - start,
});

// Bad
console.log(`Widget loaded in ${performance.now() - start}ms`);
```

### Tenant Context

Every log entry from tenant-scoped code must include `tenant_id`. In Laravel, this is handled by a global log context middleware:

```php
// Applied once in middleware, enriches all subsequent Log calls in the request
Log::shareContext(['tenant_id' => tenant()->id]);
```

For KMP and widgets, pass `tenant_id` explicitly in every structured log call.

---

## Language-Specific Tooling

### PHP / Laravel (API)

| Purpose | Tool | Notes |
|---------|------|-------|
| Test runner | Pest (PHP) | Ships with Laravel 12. Use `describe()` blocks to group by feature. |
| HTTP tests | `$this->getJson()`, `$this->postJson()` | Laravel's built-in test client. Always assert status + JSON structure. |
| Database | `RefreshDatabase` trait | Runs migrations per test. Use `DatabaseTransactions` only if tests are slow and don't need migration resets. |
| Factories | Laravel model factories | Every model gets a factory. Factories should produce valid, seedable records without overrides. |
| Mocking external APIs | `Http::fake()` | Mock Stripe, QBO, Xero at the HTTP level. Never mock your own service classes. |
| Mocking events/jobs | `Event::fake()`, `Queue::fake()` | Use to assert events are dispatched without triggering handlers. |
| Filament testing | `Livewire::test(ResourcePage::class)` | Test create, edit, list, and delete actions through Livewire's test API. |
| Code coverage | Pest `--coverage` | Run in CI. Track trends, don't gate on percentage (Tier system is the real gate). |
| Logging | `Log` facade + Monolog | JSON channel for production, stack (stderr + daily) for local. |
| Error tracking | Laravel Flare or Sentry | ERROR and above ship to tracker. |

**Test file conventions:**
- `tests/Unit/` — pure logic, no database, no HTTP
- `tests/Feature/` — integration tests hitting the database and/or HTTP layer
- One test file per model or service: `tests/Feature/LotServiceTest.php`
- Name tests descriptively: `it('prevents transferring more volume than available in source vessel')`

### Kotlin (KMP Shared Core)

| Purpose | Tool | Notes |
|---------|------|-------|
| Test runner | `kotlin.test` + JUnit5 (JVM target) | Run via `./gradlew :shared:jvmTest` (aliased to `make test-shared`). |
| SQLDelight tests | In-memory JDBC driver | Test queries against real SQL, not mocks. |
| HTTP client tests | Ktor `MockEngine` | Provide canned responses for sync API calls. |
| Coroutine tests | `kotlinx-coroutines-test` | Use `runTest` for suspending functions. Use `TestDispatcher` for controlled concurrency. |
| Logging | Kermit or custom `expect`/`actual` logger | Shared interface, platform-specific implementations (Logcat on Android, os_log on iOS). |

**Test file conventions:**
- `shared/src/commonTest/` — platform-independent tests (most tests live here)
- `shared/src/androidUnitTest/` and `shared/src/iosTest/` — only for platform-specific behavior
- One test class per repository or use case: `EventOutboxRepositoryTest.kt`

### TypeScript (Widgets + VineBook)

| Purpose | Tool | Notes |
|---------|------|-------|
| Test runner | Vitest | Fast, ESM-native, compatible with both widget and Astro builds. |
| DOM testing | `@testing-library/dom` | For widget components (Web Components). Test shadow DOM via `element.shadowRoot`. |
| HTTP mocking | `msw` (Mock Service Worker) | Intercept fetch calls at the network level for API tests. |
| Logging | `console.*` with structured objects | Widgets have minimal logging (client-side). VineBook build-time can use Node logging. |

**Test file conventions:**
- Co-located: `src/components/ReservationWidget.test.ts` next to the component
- Build verification: `npm run build && npm run test` — widgets must build before tests run (tests run against built output for integration)

---

## What "Tested" Means in INFO Files

When recording test coverage in a sub-task's INFO file, use this format so future sessions know exactly what's covered:

```markdown
### Test Summary
- `tests/Feature/LotServiceTest.php` (8 tests)
  - Tier 1: event log writes for create/update/transfer/blend
  - Tier 1: volume math accuracy (6 decimal precision)
  - Tier 2: status transition enforcement
  - Tier 2: API endpoint response shapes for index/show/store
- `tests/Unit/VolumeConverterTest.php` (12 tests)
  - Tier 1: gallon↔liter↔case conversions with rounding
- Known gaps: Filament resource CRUD not tested (Tier 2, deferred)
- Skipped (Tier 3): model accessor tests, factory definitions
```

This tells the next session what's protected, what isn't, and why — without having to read the test files themselves.

---

## CI Integration

Tests run in the CI pipeline per surface:

| Surface | Command | Blocks Deploy? |
|---------|---------|----------------|
| API (Laravel) | `make test` | Yes — all Tier 1 and Tier 2 must pass |
| KMP shared core | `make test-shared` | Yes |
| Widgets | `cd widgets && npm test` | Yes |
| VineBook | `cd vinebook && npm test` | No (static site, low risk) |
| Cellar app (Android) | `./gradlew :apps:cellar:android:test` | Yes |
| POS app (Android) | `./gradlew :apps:pos:android:test` | Yes |

Flaky tests are treated as bugs, not tolerated with retries. If a test is flaky, fix it or delete it — a test you can't trust is worse than no test.
