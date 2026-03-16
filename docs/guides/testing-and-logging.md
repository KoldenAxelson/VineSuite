# Testing & Logging Standards

What to test, what to log, and what to skip — across PHP (Laravel), Kotlin (KMP), Swift, and TypeScript.

---

## Test Tiers

**Guiding question:** If this broke silently, how long until we'd notice and how bad would the damage be?

### Tier 1 — Must Have (block PR if missing)

Protects money, data integrity, compliance, core business logic.

- Event log writes (correct entity_type, operation_type, payload structure)
- Financial calculations (cost-per-unit, COGS, tax, payment totals, club billing, gift card math)
- TTB compliance (report generation, volume tracking, bonded/taxpaid transitions, form field calculations)
- Inventory math (adds, subtracts, transfers, blend proportions, unit conversions)
- Authentication and authorization (login/logout, token issuance, role gates, tenant isolation)
- Payment flows (charge, refund, stored card usage, webhook signature verification)
- Sync engine (event outbox drain, idempotency dedup, conflict resolution, offline queue ordering)
- Data migrations (transforming existing data, not just schema changes)

**Test type:** Integration/Feature tests exercising full request-to-database path. Mock external APIs (Stripe, QuickBooks); never mock your own services.

**Coverage target:** 100% of Tier 1 scenarios. No exceptions.

### Tier 2 — Should Have (expected in PR, negotiable if time-boxed)

Protects user workflows, catches regressions in standard CRUD.

- API endpoint contracts (status codes, response shape, pagination, filtering, sorting)
- Filament resource CRUD (create, read, update, soft-delete via Livewire)
- Service layer business logic (status transition rules, validators)
- Notification dispatch (correct class to correct channel for correct event)
- Scheduled jobs (run, process expected records, handle empty state)
- KMP repository layer (SQLDelight query results, offline cache hydration)
- Webhook ingestion (validate and dispatch to correct handler)

**Test type:** Mix of unit and integration. Unit for pure logic; integration for database or external boundaries.

**Coverage target:** 80%+ of Tier 2 scenarios. Skip trivial getters.

### Tier 3 — Nice to Have (don't write unless complex)

Framework-standard, visually verifiable, or so simple a test just repeats the implementation.

- Simple model accessors, mutators, scopes (unless they contain math/branching)
- Filament form field and table column definitions (framework tests these)
- View/Blade rendering (test the logic feeding the view, not the view)
- Simple config retrieval, migration structure, CSS/styling
- Factory definitions

**When promoted:** If an accessor has a conditional (`is_club_member ? discount_price : retail_price`), that's Tier 2 business logic.

### Never Test

- Framework behavior (`belongsTo()` works — don't test it)
- Third-party package internals (don't test Spatie Permission; test YOUR seeder creates the roles)
- Private methods directly (test the public method that calls them)
- Constructor assignment

---

## Logging Standards

### Log Levels (RFC 5424)

| Level | When to Use | Examples | Alert? |
|-------|-------------|----------|--------|
| EMERGENCY | System unusable | DB connection pool exhausted, tenant schema loop | PagerDuty |
| CRITICAL | Immediate action needed | Payment processor down during billing run, event log write failure | PagerDuty |
| ERROR | Runtime error, not immediate | Invalid Stripe webhook, sync batch failed for one tenant, accounting push failed | Error tracker (Sentry/Flare) |
| WARNING | Exceptional, not error | API rate limit at 80%, tenant near quota, deprecated endpoint called | Error tracker |
| NOTICE | Normal but significant | New tenant provisioned, phase migration done, billing run started/finished | Log aggregator |
| INFO | Interesting events | Login, payment processed, sync batch done, report generated | Log aggregator |
| DEBUG | Detailed debug info | SQL queries (Telescope), event payloads, sync decisions | Local/dev only |

### What to Log / Never Log

**Always log (INFO or above):**
- External API calls (Stripe, QuickBooks, Xero, Anthropic) — operation, status, relevant IDs (never PII or secrets)
- Tenant lifecycle — provisioning, plan changes, suspension, archival
- Payment outcomes — success/failure, refunds, disputes (last4 only, never full card)
- Sync operations — batch start/finish, conflicts, failed items with entity IDs
- Background job completion — job class, tenant ID, duration, outcome
- Auth events — login, logout, failed attempts, role changes, token issuance

**Never log:**
- Full API keys, tokens, secrets (log `sk_...XXXX` masked if debugging)
- User passwords, password reset tokens
- Full credit card numbers (last 4 only)
- Full request/response bodies from external APIs (may contain PII)
- PHI or health-related data
- Individual SQL queries in production (use DEBUG level, off in prod)

**Log judiciously (WARNING or DEBUG):**
- Performance concerns — queries over 500ms, jobs over 30s, sync batches over 60s
- Retry attempts — log each with attempt number and backoff duration
- Feature flag evaluations — log once per session, not per request

### Structured Logging Format

All entries use context fields, not string interpolation. Makes logs searchable and parseable.

**PHP (Laravel):**
```php
Log::info('Payment processed', [
    'tenant_id' => $tenant->id,
    'order_id' => $order->id,
    'amount_cents' => $charge->amount,
    'processor' => 'stripe',
    'stripe_charge_id' => $charge->id,
    'duration_ms' => $elapsed,
]);
```

**Kotlin (KMP):**
```kotlin
logger.info("Sync batch completed") {
    put("tenant_id", tenantId)
    put("events_sent", sentCount)
    put("events_failed", failedCount)
    put("duration_ms", elapsed)
}
```

**TypeScript (Widgets / VineBook):**
```typescript
console.info('Widget initialized', {
    widgetType: 'reservation',
    tenantSlug: config.slug,
    loadTimeMs: performance.now() - start,
});
```

### Tenant Context

Every log from tenant-scoped code must include `tenant_id`. In Laravel, use middleware:

```php
Log::shareContext(['tenant_id' => tenant()->id]);
```

For KMP and widgets, pass `tenant_id` explicitly in every structured log call.

---

## Language-Specific Tooling

### PHP / Laravel

| Purpose | Tool | Notes |
|---------|------|-------|
| Test runner | Pest (PHP) | Use `describe()` to group by feature |
| HTTP tests | `$this->getJson()`, `$this->postJson()` | Assert status + JSON structure |
| Database | `DatabaseMigrations` trait | Use instead of `RefreshDatabase` — PostgreSQL DDL in tenant schema creation deadlocks with RefreshDatabase's transaction wrapper |
| Factories | Laravel model factories | Every model gets a factory; produce valid, seedable records |
| Mock external APIs | `Http::fake()` | Mock Stripe, QBO, Xero at HTTP level; never mock your own services |
| Mock events/jobs | `Event::fake()`, `Queue::fake()` | Assert dispatched without triggering handlers |
| Filament testing | `Livewire::test(ResourcePage::class)` | Test create, edit, list, delete through Livewire's test API |
| Coverage | Pest `--coverage` | Track trends; Tier system is the real gate |
| Logging | `Log` facade + Monolog | JSON channel (prod), stack (local) |
| Error tracking | Flare or Sentry | ERROR and above ship to tracker |

**File conventions:**
- `tests/Unit/` — pure logic, no database, no HTTP
- `tests/Feature/` — integration, hitting database and/or HTTP
- One file per model/service: `tests/Feature/LotServiceTest.php`
- Descriptive names: `it('prevents transferring more volume than available')`

### Kotlin (KMP Shared Core)

| Purpose | Tool | Notes |
|---------|------|-------|
| Test runner | `kotlin.test` + JUnit5 | Run via `make test-shared` |
| SQLDelight tests | In-memory JDBC driver | Test queries against real SQL, not mocks |
| HTTP client tests | Ktor `MockEngine` | Provide canned responses |
| Coroutine tests | `kotlinx-coroutines-test` | Use `runTest` for suspending functions |
| Logging | Kermit or expect/actual logger | Shared interface, platform-specific implementations |

**File conventions:**
- `shared/src/commonTest/` — platform-independent tests
- One class per repository/use case: `EventOutboxRepositoryTest.kt`

### TypeScript (Widgets + VineBook)

| Purpose | Tool | Notes |
|---------|------|-------|
| Test runner | Vitest | ESM-native, works for both widget and Astro builds |
| DOM testing | `@testing-library/dom` | Test Web Components via `element.shadowRoot` |
| HTTP mocking | `msw` (Mock Service Worker) | Intercept fetch at network level |
| Logging | `console.*` with structured objects | Minimal client-side logging |

**File conventions:**
- Co-located: `src/components/ReservationWidget.test.ts` next to component
- Build verification: `npm run build && npm run test` — tests run against built output

---

## Test Summary Format (for INFO Files)

When recording coverage, use this format so future sessions know what's protected:

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

---

## Laravel Testing Gotchas

### Sanctum auth guard caching across multi-user tests

When login as User A then User B with different tokens, Sanctum caches User A. Subsequent calls with User B's token still resolve as User A.

**Fix:**
```php
$roLogin = test()->postJson('/api/v1/auth/login', [...], ['X-Tenant-ID' => $tenant->id]);
$roToken = $roLogin->json('data.token');

app('auth')->forgetGuards();  // Reset auth guard

test()->postJson('/api/v1/work-orders', [...], [
    'Authorization' => "Bearer {$roToken}",
    'X-Tenant-ID' => $tenant->id,
])->assertStatus(403);
```

### Use DatabaseMigrations, not RefreshDatabase

PostgreSQL DDL (CREATE SCHEMA, ALTER TABLE) in tenant creation causes deadlocks with RefreshDatabase's transaction wrapper. Use `DatabaseMigrations` instead — runs `migrate:fresh` between tests, slower but avoids deadlocks.

### Tenant schema cleanup in afterEach

Every test file creating tenants must clean up schemas:

```php
afterEach(function () {
    if (function_exists('tenancy') && tenancy()->initialized) {
        tenancy()->end();
    }
    $schemas = DB::select(
        "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'"
    );
    foreach ($schemas as $schema) {
        DB::statement("DROP SCHEMA IF EXISTS \"{$schema->schema_name}\" CASCADE");
    }
});
```

### Testing resilience (try/catch in traits)

To test that a trait's `try/catch` catches failures, trigger a real failure. Creating normal records doesn't test resilience — the catch block never fires. Temporarily break the dependency and verify the primary operation succeeds:

```php
DB::statement('ALTER TABLE activity_logs RENAME TO activity_logs_disabled');
$user = User::create([...]);
expect($user->exists)->toBeTrue();
DB::statement('ALTER TABLE activity_logs_disabled RENAME TO activity_logs');
```

### UUID pivot tables and attach()

The `lot_vessel` pivot uses `uuid('id')->primary()`. Laravel's `attach()` won't auto-generate UUIDs — pass `'id' => (string) Str::uuid()` explicitly.

### PostgreSQL column aliases in HAVING clauses

PostgreSQL doesn't allow column aliases in `HAVING` (MySQL does). Use `havingRaw()` with the full expression:

```php
// Bad — fails on PostgreSQL
SensoryNote::select('lot_id')
    ->selectRaw('count(*) as cnt')
    ->groupBy('lot_id')
    ->having('cnt', '>', 1)
    ->get();

// Good
SensoryNote::select('lot_id')
    ->selectRaw('count(*) as cnt')
    ->groupBy('lot_id')
    ->havingRaw('count(*) > 1')
    ->get();
```

### PHPStan and Eloquent model type resolution

PHPStan needs `@property` PHPDoc blocks on Eloquent models to resolve dynamic attributes. Relationships also need generic annotations:

```php
/**
 * @property string $id
 * @property string $lot_id
 * @property \Illuminate\Support\Carbon $date
 * @property-read Lot $lot
 * @property-read User $taster
 */
class SensoryNote extends Model
{
    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo { ... }
}
```

---

## CI Integration

| Surface | Command | Blocks Deploy? |
|---------|---------|----------------|
| API (Laravel) | `make test` | Yes — all Tier 1/2 must pass |
| KMP shared core | `make test-shared` | Yes |
| Widgets | `cd widgets && npm test` | Yes |
| VineBook | `cd vinebook && npm test` | No (static site, low risk) |
| Cellar app (Android) | `./gradlew :apps:cellar:android:test` | Yes |
| POS app (Android) | `./gradlew :apps:pos:android:test` | Yes |

Flaky tests are bugs. Fix or delete them — a test you can't trust is worse than no test.
