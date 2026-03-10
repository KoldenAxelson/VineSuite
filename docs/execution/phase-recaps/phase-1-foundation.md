# Phase 1 Recap — Foundation

> Duration: 2026-03-10 → 2026-03-10
> Task files: `01-foundation.md`
> INFO files: `01-foundation.info.md`

---

## What Was Delivered
- A fully containerized Laravel 12 API with PostgreSQL 16, Redis 7, and supporting services — ready for local development with `docker compose up -d`
- Schema-per-tenant multi-tenancy where each winery gets complete data isolation in its own PostgreSQL schema, with provisioning under 10 seconds
- Token-based authentication with 7 winery-specific roles (Owner through Read-Only) and ~55 granular permissions, scoped per client type (portal, cellar app, POS, widget, public API)
- An immutable, append-only event log — the foundational data structure that all future winery operations will write to, enforced at the PostgreSQL trigger level
- SaaS billing infrastructure via Stripe (Cashier) with Free/Basic/Pro/Max plans, webhook handling, and customer portal
- A Filament v3 management portal shell at `/portal` with team member management and activity log viewer
- Full CI/CD pipeline: Pint + PHPStan (level 6, zero errors) + Pest (135 tests, 481 assertions) on every push

## Architecture Decisions Made
- **Schema-per-tenant (not database-per-tenant):** PostgreSQLSchemaManager creates `tenant_{uuid}` schemas within the same database. Simpler ops, good up to ~500 tenants. See `01-foundation.info.md` Sub-Task 3.
- **DatabaseMigrations over RefreshDatabase for tenancy tests:** PostgreSQL DDL deadlocks inside RefreshDatabase's transaction wrapper. All tenancy tests use DatabaseMigrations + manual schema cleanup. See Sub-Task 3.
- **Sanctum tokens, not OAuth:** Scoped per client type via abilities. Portal gets `*`, mobile apps get limited abilities. Both token abilities AND role permissions must pass. See Sub-Task 4.
- **Tenant is the Billable, not User:** SaaS billing is per-winery. Subscriptions live in central schema alongside tenant records. See Sub-Task 10.
- **Token name encodes client_type:** Format is `client_type|device_name` (e.g., `portal|My MacBook`). Rate limiter reads the prefix. See Sub-Task 14.
- **API envelope on all routes:** `{ "data": ..., "meta": {}, "errors": [] }` format. Validation errors include field-level details. See Sub-Task 13.

## Deviations from Original Spec
- **No `app/Exceptions/Handler.php`:** Laravel 12 uses `bootstrap/app.php` for exception rendering. Spec referenced older pattern. No downstream impact.
- **No separate `RouteServiceProvider` or `api_v1.php`:** Laravel 12 handles API prefix in `bootstrap/app.php` `withRouting()`. All routes remain in `routes/api.php`. No downstream impact.
- **MustVerifyEmail deferred:** Removed from User model because verification routes weren't built yet. Will re-add when email verification flow is implemented. Impacts any future task requiring email verification.
- **Per-origin widget throttling deferred:** Per-key throttling is implemented. Per-origin (CORS referer) deferred to when widget embedding is built in Phase 7 (14-widgets.md).

## Patterns Established
- **EventLogger as single write path:** All modules must use `app(EventLogger::class)->log()` — never `Event::create()` directly. Detailed in `references/event-log.md`.
- **Tenancy test pattern:** `uses(DatabaseMigrations::class)` at file level, `afterEach` drops `tenant_%` schemas. Detailed in `references/multi-tenancy.md`.
- **ApiResponse::* in all controllers:** Every API controller returns an `ApiResponse::success()`, `created()`, `message()`, or `error()` call. No raw `response()->json()`.
- **Token name format:** `client_type|context` for all token creation. Rate limiter depends on this.
- **LogsActivity trait for auditing:** Add `use LogsActivity;` to any tenant model that should be audited. Password/remember_token always excluded.
- **Immutability via DB triggers:** Both `events` and `activity_logs` tables use PostgreSQL triggers to block UPDATE/DELETE. Standard for all append-only tables.
- **Structured logging:** All auth/tenant events log `tenant_id` and `user_id` with `Log::info('message', ['key' => 'value'])` format.

## Post-Phase 1 Amendments

The following changes were made after Phase 1 was marked complete but before Phase 2 began:

### Plan Tier Rename
Renamed `starter/growth/pro` → `free/basic/pro/max` across the entire codebase to align with current SaaS tier conventions. Added a `free` tier (no Stripe subscription required) as the default plan for new tenants.

**Files touched:** Tenant model, migration, BillingController, WebhookController, CreateTenantJob, DemoWinerySeeder, `config/services.php`, and all 10+ test files referencing plan names.

### Tenant Model Enhancements
- Added `PLAN_HIERARCHY` constant and helper methods: `planRank()`, `isDowngradeTo()`, `hasPlanAtLeast()` for upgrade/downgrade comparison
- Added `isFreePlan()` and `hasActiveAccess()` for free tier support
- Added `protected $attributes = ['plan' => 'free']` to mirror database default in-memory
- BillingTest expanded from 15 to 21 tests covering hierarchy, free tier, and downgrade detection

### Bug Fix: WineryProfileController
Wired the previously unused `$oldValues` variable into the `Log::info()` call in `update()`, capturing both old and new values for audit trail.

### Documentation: Token Name Contract
Added "Token Name Contract" section to `references/auth-rbac.md` documenting the `client_type|context` format, rationale (avoids Sanctum migration customization), and failure mode (silent fallback to lowest rate limit tier).

### Idea Docs Created
Seven strategic documents added to `docs/ideas/`:
- `pricing-and-plan-tiers.md` — Freemium model, tier structure, feature gating architecture
- `progressive-onboarding.md` — Lessons from competitor onboarding failures
- `data-portability.md` — Export-first philosophy, migration OUT strategy
- `harvest-season-resilience.md` — Load testing and offline-first requirements
- `gradual-migration-path.md` — Module-at-a-time adoption strategy
- `customer-support-escalation.md` — Tiered AI support system
- `grape-marketplace.md` — Tenant-to-tenant fruit trading with network effects

## Known Debt
1. **MustVerifyEmail not active** — impact: low — affects: any task requiring email verification
2. **Password reset flow untested** — impact: low — controllers exist but no test coverage yet
3. **Stripe products not created** — impact: low — checkout returns 422 until `STRIPE_PRICE_*` env vars are set
4. **Per-origin widget throttling** — impact: low — affects: 14-widgets.md
5. **Forge deploy webhook not configured** — impact: none for dev — deploy.yml is manual-trigger only until Forge is set up
6. **PlanFeatureService not built** — impact: none yet — feature gating middleware deferred to Phase 2

## Reference Docs Updated
- `references/event-log.md` — created — EventLogger usage, immutability, querying patterns
- `references/multi-tenancy.md` — created — schema lifecycle, identification, testing gotchas
- `references/auth-rbac.md` — created — token abilities, roles, rate limiting, usage patterns
- `guides/forge-deployment.md` — created — step-by-step Forge setup when ready to deploy
- `guides/testing-and-logging.md` — pre-existing, referenced throughout
- `guides/stripe-setup.md` — pre-existing, referenced for billing

## Metrics
- Sub-tasks completed: 15/15
- Test count: 141 (all integration/feature, against real PostgreSQL) — 6 added post-Phase 1
- Assertions: ~500+
- Files created: ~70 (app, database, tests, config, views, workflows)
- Migrations created: 14 (7 central, 7 tenant)
- PHPStan: level 6, zero errors
- Pint: zero style issues
- Idea docs: 7 strategic documents in `docs/ideas/`
