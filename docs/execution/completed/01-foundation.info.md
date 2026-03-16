# Foundation — Completion Record

> Task spec: `docs/execution/tasks/01-foundation.md`
> Phase: 1

---

## Sub-Task 1: Docker Compose Development Environment
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **Separate test database container**: `postgres-test` on port 5433, tmpfs-backed. Test isolation and fast runs; no risk of dev data corruption.
- **Single-container app (supervisor)**: php-fpm + nginx in one container via supervisor. Simpler for dev; production can split.
- **Anonymous volume for vendor**: Prevents host bind mount from overriding container's Composer-installed packages.

**Patterns Established**
- Health check dependencies: `condition: service_healthy` prevents race conditions.
- All services on `vinesuite` bridge network; reference each other by service name.

**Test Summary**
- Manual: All 6 services healthy; PHP 8.4.18 with required extensions; both PostgreSQL instances responding; Redis/Meilisearch/Mailpit working.

---

## Sub-Task 2: Laravel 12 Project Initialization
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **Pest over PHPUnit syntax**: `pestphp/pest` + `pestphp/pest-plugin-laravel` installed. All tests use Pest closure API.
- **Dedicated `testing` connection**: `config/database.php` hardcodes test container (port 5433); phpunit.xml sets `DB_CONNECTION=testing`.
- **Redis for session/cache/queue**: Switched from defaults; tests use `array`/`sync` for speed/isolation.

**Patterns Established**
- Test isolation: Tests always run against `postgres-test`. Dev data untouched.
- Pest syntax: All tests use `it()` / `test()` closures.

**Test Summary**
- 2 tests passing; ExampleTest.php (Pest syntax); ExampleTest.php (HTTP GET / returns 200). 0.08s.

---

## Sub-Task 3: Multi-Tenancy Setup with stancl/tenancy
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **Schema-per-tenant**: PostgreSQLSchemaManager creates `tenant_{uuid}` schemas within same database. Works up to ~500 tenants.
- **Sync tenant provisioning**: TenantCreated pipeline runs synchronously; CreateTenantJob wraps it for async API usage.
- **DatabaseMigrations for tenancy tests**: Avoids `RefreshDatabase` deadlock with PostgreSQL DDL. Non-tenancy tests opt-in per file.
- **UUID primary keys on Tenant**: HasUuids trait; schema names avoid slug-collision issues.

**Deviations from Spec**
- API token-based tenant identification deferred to Sub-Task 4. Subdomain identification wired.

**Patterns Established**
- Tenancy test pattern: `uses(DatabaseMigrations::class)` at file level; `afterEach` drops `tenant_%` schemas.
- Central migrations in `database/migrations/`; tenant in `database/migrations/tenant/`.

**Test Summary**
- 5 tests: schema creation, migration isolation, cross-tenant access prevention, CreateTenantJob, unique slug. 0.68s.

---

## Sub-Task 4: Authentication System (Sanctum + RBAC)
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **MustVerifyEmail deferred**: Removed; requires verification routes. Registered event still fired; will re-add when email flow built.
- **Permission/token tables in tenant schema only**: Roles, permissions, Sanctum tokens live in tenant schemas. Central has no permission infrastructure.
- **Dual role checking**: Middleware checks `role` column (fast) and spatie HasRoles (permission matrix). Combines simple role checks with granular permissions.
- **Token abilities per client type**: `User::TOKEN_ABILITIES` constant defines portal=`*`, mobile=scoped, widget=minimal. Token abilities AND role permissions must pass.
- **X-Tenant-ID header**: InitializeTenancyByRequestData uses `X-Tenant-ID` (not default `X-Tenant`); query parameter disabled.

**Deviations from Spec**
- Password reset flow not tested. Controllers built; will test after email verification infrastructure done.

**Patterns Established**
- Tenancy test helper: `createTestTenant()` creates tenant + optionally runs setup in its context.
- Auth guard reset: Call `app('auth')->forgetGuards()` between logout and verification to prevent Sanctum caching.
- Structured logging: All auth events log `user_id` and `tenant_id`.

**Test Summary**
- 13 tests: register/login/invalid creds/deactivated/token access/logout/revocation. 7 roles seeded; owner has all perms; read_only has only reads; token abilities scoped.

---

## Sub-Task 5: Team Invitation System
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **Owner role cannot be invited**: Validation rejects `owner`. Owners only created during tenant registration.
- **72-hour expiry**: Invitations expire after 72h; not deleted (record retained).
- **No auth required to accept**: Public endpoint (within tenant middleware). Invitation token acts as auth.
- **Duplicate prevention**: Blocks new invitation if pending exists for same email; also blocks if user already exists.
- **Immediate token on accept**: Invitee gets Sanctum token immediately.

**Patterns Established**
- Mail::fake() in tests. Token-based public endpoints for users without accounts.

**Test Summary**
- 11 tests: send/duplicate blocked/existing user blocked/owner rejected/403 for non-admin/accept/expired/already-accepted/invalid token/cancel/list.

---

## Sub-Task 6: Event Log Table and Base Service
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **Database-level immutability**: PostgreSQL trigger `prevent_event_mutation()` raises exception on UPDATE/DELETE. Strongest enforcement.
- **BRIN index for performed_at**: Much smaller than B-tree for sequential time-ordered data. Ideal for range queries.
- **Idempotency at service level**: EventLogger checks for existing idempotency_key before INSERT; returns existing event silently.
- **No updated_at column**: Events immutable; no need for update timestamp.
- **Nullable idempotency_key**: Server-created events may not need keys; null allowed without unique constraint conflict.

**Patterns Established**
- EventLogger is single write path: All modules use `app(EventLogger::class)->log()`. Never use `Event::create()` directly.
- JSONB payload queryable via `payload->>'key'` in whereRaw.

**Test Summary**
- 13 tests: creation/JSONB queryable/performed_at/synced_at/user link/idempotency/UPDATE blocked/DELETE blocked/entity stream/operation type query/cross-tenant isolation.

---

## Sub-Task 7: Event Sync API Endpoint
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **Per-event transactions**: Each event in own DB transaction. Bad event doesn't reject batch.
- **Idempotency key required for sync**: Unlike EventLogger (null allowed), sync endpoint requires key on all events. Mobile clients generate client-side.
- **30-day window for performed_at**: Older events rejected. Future timestamps rejected.
- **Max 100 events per batch**: Prevents oversized payloads. Mobile chunks larger syncs.
- **EventProcessor delegates to EventLogger**: Checks for existing idempotency first, then calls EventLogger with `isSynced: true`.

**Patterns Established**
- Form Request validation for batches: `events.*.field` rules per item. Custom error messages for date range.
- Per-event error handling: Failed events don't break batch. Results include index, status, error message.
- Sync endpoint always returns 200 (partial failures OK); only pre-processing validation returns 422.

**Test Summary**
- 12 tests: batch acceptance/synced_at set/link to user/duplicate skipped/mixed new+duplicate/full idempotency/>30 days rejected/future rejected/empty rejected/auth required/performed_at preserved.

---

## Sub-Task 8: Winery Profile and Onboarding Setup
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **Auto-create profile on tenant provisioning**: TenantDatabaseSeeder creates default WineryProfile with tenant's name. Every tenant has exactly one profile.
- **Partial updates via PUT**: `sometimes` validation allows incremental onboarding steps.
- **Fiscal year start month**: 1–12 int. Affects reporting periods (TTB, financials).
- **Timezone per winery**: Used for scheduled jobs. Defaults to America/Los_Angeles.
- **Demo winery idempotent**: Checks for existing slug before creating.

**Patterns Established**
- One profile per tenant: Auto-created. Controller uses `firstOrFail()`.
- Preference-aware modules: Check `usesImperial()` for unit conversions; `fiscal_year_start_month` for reporting.

**Test Summary**
- 11 tests: auto-creation/GET/unauthenticated rejected/owner can update/partial updates/unit_system validated/fiscal_year_start_month validated/timezone validated/403 for non-admin/demo seeder creates/demo seeder idempotent.

**Post-Phase 1 Amendment**
- Plan tier renamed: `starter/growth/pro` → `free/basic/pro/max`. DemoWinerySeeder creates with `pro` plan.

---

## Sub-Task 9: Filament Management Portal Shell
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **Panel path `/portal` not `/admin`**: Matches spec. Panel ID `portal`.
- **Domain-based tenancy for portal**: InitializeTenancyByDomain (subdomains) vs. API's InitializeTenancyByRequestData (headers). Both coexist.
- **Session auth for portal, token auth for API**: Filament uses sessions; API uses Sanctum. No conflict.
- **No delete action on users**: Deactivate only. Hidden for owner role to prevent lockout.
- **Role sync on edit save**: `afterSave()` calls `syncRoles()` to keep spatie permission role in sync.

**Patterns Established**
- Filament resource access control: Use `canAccess()` to restrict by role. Check `auth()->user()->role` for speed.
- Portal + API coexistence: Portal uses session + domain tenancy; API uses token + header tenancy. Both resolve to same tenant schemas.

**Test Summary**
- 11 tests: panel at /portal/7 navigation groups/brand name/dashboard page/UserResource in Settings/list+edit pages/owner can access/admin can access/winemaker cannot access/read_only cannot access.

---

## Sub-Task 10: Stripe Billing Integration (SaaS Subscriptions)
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **Tenant is Billable, not User**: SaaS billing per-winery. Tenant model has Cashier Billable trait.
- **Central schema billing**: Cashier tables (subscriptions, subscription_items) in central public schema. Not per-tenant Stripe Connect.
- **Plan price IDs via env vars**: `STRIPE_PRICE_BASIC`, `STRIPE_PRICE_PRO`, `STRIPE_PRICE_MAX` env vars map to Stripe price IDs.
- **Webhook extends Cashier controller**: Custom WebhookController extends `CashierWebhookController` for plan syncing.
- **CSRF exclusion for webhook**: `api/v1/stripe/webhook` excluded in `bootstrap/app.php`.
- **Grace period on cancellation**: 30-day read-only window enforced via `onGracePeriod()`.

**Deviations from Spec**
- Stripe products not yet created. Test keys configured; no products/prices exist. Expected — create in Stripe Dashboard.

**Patterns Established**
- Central billing, tenant-scoped data: Billing in central schema. Winery data in tenant schemas.
- Webhook handling: Extend Cashier's controller. Use WebhookReceived event listener for invoice handling.

**Post-Phase 1 Amendments**
- Plan renamed: `starter/growth/pro` → `free/basic/pro/max`. Free tier added (no Stripe subscription required). Added `PLAN_HIERARCHY`, `planRank()`, `isDowngradeTo()`, `hasPlanAtLeast()`. Tests expanded from 15 to 21.

**Test Summary**
- 21 tests (expanded post-Phase 1 from 15): status/checkout/portal/plan change/free plan/plan hierarchy/hasPlanAtLeast.

**Open Questions**
- Stripe products (Basic, Pro, Max) need creation in Stripe Dashboard; price IDs added to .env.

---

## Sub-Task 11: Activity Logging System
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **Database-level immutability**: PostgreSQL trigger `activity_logs_immutability_guard` prevents UPDATE/DELETE.
- **Separate from Event Log**: Activity logs = system changes (user edited profile); Event logs = winery operations. Different tables.
- **Try/catch resilience**: LogsActivity trait wraps all logging in try/catch. Original operation succeeds even if logging fails.
- **Sensitive field filtering**: Password and remember_token always excluded. Models add exclusions via `$activityLogExclude` or restrict via `$activityLogOnly`.
- **Applied to three models**: User, WineryProfile, TeamInvitation.
- **Read-only Filament resource**: No create/edit/delete. Owner/admin only.

**Patterns Established**
- LogsActivity trait on tenant models: Add `use LogsActivity;` and configure exclusions.
- Immutability at DB level: Both events and activity_logs use PostgreSQL triggers.
- Filament read-only resources: Set `canCreate()` false; remove edit/delete actions.

**Test Summary**
- 14 tests: immutability/JSONB casts/scopes/auto-log creation/updates/deletion/sensitive fields excluded/authenticated user captured/WineryProfile diffs/cross-tenant isolation/Filament config/access control/trait resilience.

---

## Sub-Task 12: CI/CD Pipeline Setup
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **Three parallel CI jobs**: Lint, static analysis, tests. Independent; faster feedback.
- **PostgreSQL 16 in CI**: Matches production. Real PostgreSQL tests; never SQLite.
- **PHPStan level 6**: Strict enough for real bugs; not noise-heavy. Filament providers excluded.
- **Larastan over vanilla PHPStan**: Understands Laravel magic (facades, models, relationships, scopes).
- **Deploy via Forge webhook**: Simple, reliable. URL stored as GitHub secret.
- **env() → config() migration**: `env()` returns null when config cached. Stripe prices moved to `config/services.php`.

**Deviations from Spec**
- `pint.json` instead of `.php-cs-fixer.php`. Pint is Laravel's standard.
- No PHPStan baseline. All 79 errors fixed; zero errors.

**Patterns Established**
- CI on every push. Code style enforced. Static analysis as gate.
- All array parameters typed: `array<string, mixed>` for associative; `array<int, string>` for lists.

**Code Quality Fixes**
- 41 Pint style issues: `declare_strict_types`, unused imports, spacing/concat.
- 79 PHPStan errors fixed: Builder/BelongsTo generics, PHPDoc array types, return types, `env()` → `config()`, nullsafe fixes.

**Test Summary**
- No new tests (infrastructure). All 107 tests passing; 366 assertions. 36.44s.

**Open Questions**
- `FORGE_DEPLOY_WEBHOOK_URL` GitHub secret needs configuration in `staging` environment.

---

## Sub-Task 13: API Response Envelope and Error Handling
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **ApiResponse helper over middleware wrapping**: Explicit calls in controllers — no magic auto-wrapping.
- **ForceJsonResponse as first middleware**: Prepended to `api` group so early failures return JSON.
- **AuthenticationException handled separately**: Dedicated handler; without it, falls to `\Throwable` → 500.
- **Field-level validation errors**: Flattened to `[{ "field": "email", "message": "..." }]` for mobile/SPA.
- **No envelope on non-API routes**: All handlers check `$request->is('api/*')` — Filament unaffected.

**Deviations from Spec**
- No `app/Exceptions/Handler.php`. Laravel 12 uses `bootstrap/app.php` for exception rendering.

**Patterns Established**
- `ApiResponse::*` in all controllers.
- Envelope assertion: `array_column($response->json('errors'), 'field')` + `toContain('email')`.
- Token extraction: Tests use login endpoint, not `$tenant->run()` + `createToken()`.

**Test Summary**
- 14 tests: success/created/message/error/validation envelopes/health check/validation errors/404/login/401/403/logout/non-API exclusion/forced JSON.
- All 122 tests passing; 452 assertions.

---

## Sub-Task 14: Rate Limiting and API Versioning
**Completed:** 2026-03-10 | **Status:** Done

**Key Decisions**
- **Client type encoded in token name**: `client_type|device_name` format. Rate limiter extracts prefix for tier identification.
- **Per-user, per-client-type throttle keys**: Keys are `throttle:{tenant}:{user}:{client_type}`. Separate buckets per client.
- **IP-based fallback for unauthenticated**: 30/min per IP.
- **API versioning already in place**: `/api/v1/` prefix in `bootstrap/app.php` during Sub-Task 2.
- **429 in envelope format**: Uses `ApiResponse::error()`.

**Limits by Client Type**
- portal: 120/min | cellar_app: 60/min | pos_app: 60/min | widget: 30/min | public_api: 60/min | unauthenticated: 30/min

**Deviations from Spec**
- No separate RouteServiceProvider. No `api_v1.php` route file. Routes in `routes/api.php` with automatic prefix.
- Per-origin widget throttling deferred to widget embedding module.

**Patterns Established**
- Token name format: All token creation uses `client_type|context` naming.
- Rate limit middleware on route groups.

**Test Summary**
- 13 tests: rate limit constants/headers/decrementing count/per-type limits/429 envelope/API v1 prefix/404 without v1/404 for v2/token name format.

---

## Sub-Task 15: Demo Seeder with Realistic Winery Data
**Completed:** 2026-03-10 | **Status:** Done (part of Sub-Task 8)

**Built During Sub-Task 8**
- `DemoWinerySeeder.php`: Creates "Paso Robles Cellars" tenant with Adelaida District address, TTB permits, July fiscal year, 7 demo users (one per role). Idempotent.
- `DatabaseSeeder.php` calls it.
- Verified in WineryProfile tests.

**Open Questions**
- Demo seeder grows as modules added.

---
