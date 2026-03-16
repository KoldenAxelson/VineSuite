# Phase 1 Recap — Foundation

> Duration: 2026-03-10
> Task files: `01-foundation.md` | INFO: `01-foundation.info.md`

---

## Delivered

- Containerized Laravel 12 API (PostgreSQL 16, Redis 7) — ready for `docker compose up -d`
- Schema-per-tenant multi-tenancy with data isolation in PostgreSQL schemas (~10s provisioning)
- Token-based authentication with 7 winery roles (~55 granular permissions per client type)
- Immutable, append-only event log enforced via PostgreSQL trigger
- SaaS billing (Stripe Cashier): Free/Basic/Pro/Max plans with webhook handling
- Filament v3 management portal (`/portal`) with team management and activity log viewer
- Full CI/CD: Pint + PHPStan (level 6) + Pest (141 tests, ~500 assertions) on every push

---

## Architecture Decisions

- **Schema-per-tenant:** PostgreSQL `tenant_{uuid}` schemas. Simpler ops, scales to ~500 tenants.
- **DatabaseMigrations for tenancy tests:** PostgreSQL DDL deadlocks in RefreshDatabase transactions. All tenancy tests use DatabaseMigrations + manual schema cleanup.
- **Sanctum tokens, not OAuth:** Scoped per client type via abilities. Portal gets `*`, mobile apps limited abilities. Both token abilities AND role permissions must pass.
- **Tenant is Billable, not User:** SaaS billing per-winery. Subscriptions live in central schema.
- **Token name encodes client_type:** Format: `client_type|device_name` (e.g., `portal|My MacBook`). Rate limiter reads prefix.
- **API envelope on all routes:** `{ "data": ..., "meta": {}, "errors": [] }` format. Validation errors include field-level details.

---

## Deviations from Spec

- **No `app/Exceptions/Handler.php`:** Laravel 12 uses `bootstrap/app.php`. No downstream impact.
- **No separate RouteServiceProvider:** Laravel 12 handles API prefix in `bootstrap/app.php`. No impact.
- **MustVerifyEmail deferred:** Removed from User model (routes not built). Will re-add when email verification implemented.
- **Per-origin widget throttling deferred:** Per-key throttling implemented. Per-origin (CORS referer) deferred to Phase 7.

---

## Patterns Established

- **EventLogger as single write path:** All modules use `app(EventLogger::class)->log()` — never `Event::create()` directly. See `references/event-log.md`.
- **Tenancy test pattern:** `uses(DatabaseMigrations::class)` at file level, `afterEach` drops `tenant_%` schemas. See `references/multi-tenancy.md`.
- **ApiResponse::* in all controllers:** Every API controller returns `ApiResponse::success()`, `created()`, `message()`, or `error()`.
- **Token name format:** `client_type|context` for all token creation.
- **LogsActivity trait for auditing:** Add `use LogsActivity;` to any tenant model. Password/remember_token auto-excluded.
- **Immutability via DB triggers:** `events` and `activity_logs` use PostgreSQL triggers to block UPDATE/DELETE.
- **Structured logging:** Auth/tenant events log `tenant_id` and `user_id` with `Log::info('message', ['key' => 'value'])` format.

---

## Post-Phase 1 Amendments

### Plan Tier Rename
`starter/growth/pro` → `free/basic/pro/max`. Added free tier (no Stripe subscription).

**Files touched:** Tenant model, migration, BillingController, WebhookController, CreateTenantJob, DemoWinerySeeder, `config/services.php`, 10+ test files.

### Tenant Model Enhancements
- Added `PLAN_HIERARCHY` constant and helpers: `planRank()`, `isDowngradeTo()`, `hasPlanAtLeast()`
- Added `isFreePlan()`, `hasActiveAccess()` for free tier
- Added `protected $attributes = ['plan' => 'free']` for in-memory default
- BillingTest expanded 15 → 21 tests

### Bug Fix: WineryProfileController
Wired `$oldValues` into audit log call.

### Documentation: Token Name Contract
Added to `references/auth-rbac.md` — `client_type|context` format, rationale, failure modes.

### Idea Docs Created (7 strategic documents)
- pricing-and-plan-tiers.md
- progressive-onboarding.md
- data-portability.md
- harvest-season-resilience.md
- gradual-migration-path.md
- customer-support-escalation.md
- grape-marketplace.md

---

## Known Debt

1. **MustVerifyEmail inactive** — impact: low — affects: email verification tasks
2. **Password reset untested** — impact: low — controllers exist, no coverage
3. **Stripe products not created** — impact: low — checkout returns 422 without `STRIPE_PRICE_*` env vars
4. **Per-origin widget throttling** — impact: low — affects: Phase 7
5. **Forge deploy webhook unconfigured** — impact: none for dev
6. **PlanFeatureService not built** — impact: none yet — feature gating deferred to Phase 2

---

## Reference Docs

- `references/event-log.md` — EventLogger usage, immutability, querying
- `references/multi-tenancy.md` — schema lifecycle, identification, testing
- `references/auth-rbac.md` — token abilities, roles, rate limiting
- `guides/forge-deployment.md` — step-by-step Forge setup
- `guides/testing-and-logging.md` — pre-existing, referenced throughout
- `guides/stripe-setup.md` — pre-existing, referenced

---

## Metrics

| Metric | Value |
|--------|-------|
| Sub-tasks | 15/15 |
| Tests | 141 (all integration/feature vs real PostgreSQL) |
| Assertions | ~500+ |
| Files created | ~70 (app, database, tests, config, views, workflows) |
| Migrations | 14 (7 central, 7 tenant) |
| PHPStan level 6 | 0 errors |
| Pint | 0 style issues |
| Idea docs | 7 strategic documents |
