# Foundation — Completion Record

> Task spec: `docs/execution/tasks/01-foundation.md`
> Phase: 1

---

## Sub-Task 1: Docker Compose Development Environment
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `docker-compose.yml` — 7 services: app (PHP 8.4-FPM + nginx), postgres (dev), postgres-test (test, tmpfs-backed), redis, meilisearch, mailpit, horizon
- `api/Dockerfile` — PHP 8.4-FPM with nginx + supervisor; extensions: pdo_pgsql, pgsql, redis, gd, zip, bcmath, intl, opcache, pcntl, mbstring
- `api/docker/nginx.conf` — Standard Laravel nginx config, 64M upload limit
- `api/docker/supervisord.conf` — Runs php-fpm + nginx via supervisor in a single container
- `api/docker/php.ini` — Dev overrides: 64M uploads, 256M memory, opcache off
- `api/.env.example` — Full environment template with Docker service hostnames, includes DB_TEST_* vars for test database
- `api/.dockerignore` — Excludes vendor, node_modules, .env, storage caches, tests from build context
- `.gitignore` — Monorepo-wide: Laravel, KMP, widgets, VineBook, Docker, OS/IDE files
- `Makefile` — Added `build` and `ps` targets to existing commands

### Key Decisions
- **Separate test database container**: Added `postgres-test` on port 5433 backed by tmpfs (RAM) instead of sharing the dev database. Ensures test isolation, fast test runs, and no risk of dev data corruption. Aligns with spec requirement to test against real PostgreSQL, never SQLite.
- **Single-container app (supervisor)**: Chose php-fpm + nginx in one container via supervisor over separate containers. Simpler compose file and volume management for dev. Production can split these if needed.
- **Anonymous volume for vendor**: Added `/var/www/html/vendor` as anonymous volume to prevent the host bind mount from overriding container's Composer-installed packages when vendor/ doesn't exist on host yet.

### Deviations from Spec
- None. The existing `docker-compose.yml` was treated as a starting point and enhanced with health checks, test DB, networking, and Meilisearch master key.

### Patterns Established
- **Health check dependency**: Services that need databases/cache use `condition: service_healthy` in `depends_on`, not bare service names. This prevents race conditions on startup.
- **Docker networking**: All services on a shared `vinesuite` bridge network. Services reference each other by compose service name (e.g., `postgres`, `redis`).

### Test Summary
- Manual verification: all 6 services started healthy (horizon expected to fail until Laravel exists)
- PHP 8.4.18 confirmed with all required extensions
- Both PostgreSQL instances responding to queries
- Redis PONG, Meilisearch health check passed, Mailpit UI accessible

### Open Questions
- None.

---

## Sub-Task 2: Laravel 12 Project Initialization
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `api/` — Fresh Laravel 12 project via `composer create-project` (PHP 8.4.18)
- `api/.env` — Configured for Docker services: DB_HOST=postgres, REDIS_HOST=redis, MAIL_HOST=mailpit, QUEUE_CONNECTION=redis, SESSION_DRIVER=redis, CACHE_STORE=redis
- `api/.env.example` — Matches .env with secrets removed
- `api/config/database.php` — Default changed to `pgsql`; added `testing` connection pointing at `postgres-test` container
- `api/phpunit.xml` — DB_CONNECTION set to `testing` (real PostgreSQL), removed SQLite `:memory:` config
- `api/tests/Pest.php` — Pest configuration with Feature tests bound to Laravel TestCase
- `api/tests/Feature/ExampleTest.php` — Converted to Pest syntax
- `api/tests/Unit/ExampleTest.php` — Converted to Pest syntax
- Removed `database/database.sqlite` (created by Laravel's default post-install script)

### Key Decisions
- **Pest over PHPUnit syntax**: Installed `pestphp/pest` and `pestphp/pest-plugin-laravel` as required by spec. Converted default example tests to Pest closure syntax. PHPUnit remains as the underlying runner but all tests will use Pest's API.
- **Dedicated `testing` connection**: Rather than overriding `DB_HOST`/`DB_DATABASE` env vars in phpunit.xml (which is fragile), created a named `testing` connection in `config/database.php` that hardcodes the test container defaults. phpunit.xml simply sets `DB_CONNECTION=testing`.
- **Redis for session/cache/queue**: Switched all three from Laravel's defaults (database/database/database) to Redis, matching the architecture spec. Tests use `array`/`sync` drivers to stay fast and isolated.

### Deviations from Spec
- None.

### Patterns Established
- **Test isolation**: Tests always run against `postgres-test` container (port 5433, tmpfs-backed). Dev data is never touched. This is enforced by phpunit.xml setting `DB_CONNECTION=testing`.
- **Pest syntax**: All tests use `it()` / `test()` closures, not PHPUnit class methods. Feature tests automatically get the Laravel TestCase via `Pest.php` config.

### Test Summary
- `tests/Unit/ExampleTest.php` — basic assertion (Pest syntax verified working)
- `tests/Feature/ExampleTest.php` — HTTP GET / returns 200 (Laravel serving correctly, Pest + Laravel plugin working)
- 2 passed, 0 failed, 0.08s duration

### Open Questions
- None.

---

## Sub-Task 3: Multi-Tenancy Setup with stancl/tenancy
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `config/tenancy.php` — Full tenancy config: schema-per-tenant via PostgreSQLSchemaManager, `central_connection` pointing at default DB connection, tenant prefix `tenant_`, bootstrappers for database/cache/filesystem/queue context switching
- `app/Models/Tenant.php` — Custom Tenant model extending stancl's base with UUID primary keys. Custom columns: name, slug, plan (starter/growth/pro), stripe_customer_id, stripe_subscription_id, launched_at
- `database/migrations/0001_01_01_000010_create_tenants_table.php` — Central tenants table with plan enum, stripe fields, JSON data column for stancl extras
- `database/migrations/0001_01_01_000011_create_domains_table.php` — Central domains table mapping subdomains to tenants, cascading deletes
- `app/Providers/TenancyServiceProvider.php` — Event-driven tenant lifecycle: TenantCreated triggers CreateDatabase → MigrateDatabase → SeedDatabase pipeline (sync). TenantDeleted triggers DeleteDatabase. Routes tenant.php with domain identification middleware
- `app/Jobs/CreateTenantJob.php` — Queued job: creates Tenant model (triggers schema + migration + seeding), creates subdomain domain record, logs timing. 1 retry, 30s timeout
- `database/migrations/tenant/2026_03_10_000001_create_tenant_users_table.php` — Tenant-scoped users table with UUID PK, central_user_id link, role, is_active, invited_by
- `database/seeders/TenantDatabaseSeeder.php` — Placeholder seeder (will add roles/permissions in Sub-Task 4)
- `routes/tenant.php` — Tenant-scoped routes with domain identification middleware
- `routes/api.php` — Central API routes with /api/v1/ prefix
- `bootstrap/app.php` — Registered TenancyServiceProvider, added API routing
- `tests/Feature/Tenancy/TenantCreationTest.php` — 5 tests, 20 assertions

### Key Decisions
- **Schema-per-tenant (not database-per-tenant)**: PostgreSQLSchemaManager creates `tenant_{uuid}` schemas within the same database. Works well up to ~500 tenants per architecture spec.
- **Sync tenant provisioning in event pipeline**: TenantCreated pipeline runs synchronously. CreateTenantJob wraps it in a queued job for async API usage.
- **DatabaseMigrations for tenancy tests**: `RefreshDatabase`/`LazilyRefreshDatabase` wrap tests in transactions, which deadlock with PostgreSQL DDL (`CREATE SCHEMA`/`DROP SCHEMA`). Tenancy tests use `DatabaseMigrations` instead. Non-tenancy tests opt in per file — no global trait in Pest.php.
- **UUID primary keys on Tenant**: Uses HasUuids trait. Schema names are `tenant_{uuid}`, avoiding slug-collision issues.

### Deviations from Spec
- **API token-based tenant identification** deferred to Sub-Task 4 (Auth). Subdomain identification is wired. Token-based identification requires Sanctum infrastructure.

### Patterns Established
- **Tenancy test pattern**: `uses(DatabaseMigrations::class)` at file level. `afterEach` drops `tenant_%` schemas. No global RefreshDatabase.
- **Central vs tenant migrations**: Central in `database/migrations/`, tenant in `database/migrations/tenant/`.
- **Tenant lifecycle**: All provisioning through stancl's event system. CreateTenantJob calls `Tenant::create()` which fires the pipeline.

### Test Summary
- `tests/Feature/Tenancy/TenantCreationTest.php` — 5 tests, 20 assertions: schema creation, migration isolation, cross-tenant access prevention, CreateTenantJob, unique slug enforcement
- All against real PostgreSQL, 0.68s total

### Open Questions
- None.

---

## Sub-Task 4: Authentication System (Sanctum + RBAC)
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `app/Models/User.php` — Tenant-scoped User model: UUID PK, HasApiTokens, HasRoles, HasUuids. Token abilities matrix per client type (portal, cellar_app, pos_app, widget, public_api). isAdmin() and hasSimpleRole() helpers
- `app/Models/CentralUser.php` — Central user model for multi-winery switching, always uses central DB connection
- `database/migrations/0001_01_01_000012_create_central_users_table.php` — Central users table with UUID PK
- `database/migrations/tenant/2026_03_10_000002_create_permission_tables.php` — spatie/laravel-permission tables in tenant schema (roles, permissions, model_has_roles, model_has_permissions, role_has_permissions) with UUID morph keys
- `database/migrations/tenant/2026_03_10_000003_create_personal_access_tokens_table.php` — Sanctum personal_access_tokens in tenant schema with UUID morphs
- `database/seeders/RolesAndPermissionsSeeder.php` — Seeds 7 roles with full permission matrix (~55 permissions). Owner=all, Admin=all except billing, Winemaker=production+compliance+reporting, Cellar Hand=work orders+additions+transfers+barrels+lab, Tasting Room Staff=POS+customers+reservations, Accountant=reports+COGS+integrations, Read Only=read-only across all resources
- `database/seeders/TenantDatabaseSeeder.php` — Updated to call RolesAndPermissionsSeeder on tenant creation
- `app/Http/Controllers/Auth/LoginController.php` — Validates credentials, checks is_active, creates client-scoped Sanctum token, updates last_login_at
- `app/Http/Controllers/Auth/RegisterController.php` — Creates owner user, assigns owner role, fires Registered event, returns portal token
- `app/Http/Controllers/Auth/ForgotPasswordController.php` — Password reset link + reset with token revocation
- `app/Http/Controllers/Auth/LogoutController.php` — Revokes current token
- `app/Http/Middleware/EnsureUserHasRole.php` — Checks user's role column + spatie roles, returns 403 if unauthorized
- `routes/api.php` — Auth routes under /api/v1/auth/ (register, login, forgot-password, reset-password, logout, me) with InitializeTenancyByRequestData middleware for X-Tenant-ID header identification
- `bootstrap/app.php` — Registered role, permission, role_or_permission middleware aliases
- `app/Providers/TenancyServiceProvider.php` — Added configureIdentification() to set X-Tenant-ID header for InitializeTenancyByRequestData
- `config/tenancy.php` — Added identification.header config
- `config/permission.php` — Published spatie config (model_morph_key=model_id, UUID compatible via migration)
- `config/sanctum.php` — Published Sanctum config
- `tests/Feature/Auth/AuthenticationTest.php` — 7 tests: register, login, invalid creds, deactivated user, authenticated access, unauthenticated rejection, logout+revocation
- `tests/Feature/Auth/RbacTest.php` — 6 tests: 7 roles seeded, owner has all perms, read_only has only reads, cellar_hand blocked from admin, role middleware blocks, token abilities scoped

### Key Decisions
- **MustVerifyEmail deferred**: Removed from User model because it requires verification routes (signed URL generation fails without them). Will re-add when email verification flow is built. The Registered event is still fired in RegisterController — it just doesn't trigger verification email without the interface.
- **Permission/token tables in tenant schema only**: Deleted the vendor:publish central migrations. Roles, permissions, and Sanctum tokens live exclusively in tenant schemas. Central schema has no permission infrastructure.
- **Dual role checking**: EnsureUserHasRole middleware checks both the `role` column (fast, no DB query) and spatie's HasRoles (full permission matrix). This lets us use the simple role column for quick checks and spatie for granular permission logic.
- **Token abilities per client type**: Defined in User::TOKEN_ABILITIES constant. Portal gets `*`, mobile apps get scoped abilities, widget gets minimal read+create. Both token abilities AND role permissions must pass for access.
- **X-Tenant-ID header**: Configured InitializeTenancyByRequestData to use `X-Tenant-ID` header (not the default `X-Tenant`). Query parameter disabled.

### Deviations from Spec
- **Password reset flow not tested**: Controllers built and routes defined, but no test coverage yet. Requires email verification route infrastructure. Will test in a dedicated pass.
- **MustVerifyEmail removed temporarily**: Will be re-added when verification routes are set up.

### Patterns Established
- **Tenancy test helper**: `createTestTenant()` function creates a tenant + optionally runs setup in its context. Used across auth tests.
- **afterEach tenancy cleanup**: All tenancy test files must call `tenancy()->end()` then drop `tenant_%` schemas. Prevents dangling connections that break `DatabaseMigrations`.
- **Auth guard reset**: When testing token revocation, call `app('auth')->forgetGuards()` between logout and verification requests to prevent Sanctum caching.
- **Structured logging in auth**: All auth events log `user_id` and `tenant_id` per the logging guide.

### Test Summary
- `tests/Feature/Auth/AuthenticationTest.php` — 7 tests: register returns 201 + token, login returns token with role, invalid creds returns 422, deactivated user returns 422, valid token accesses /me, missing token returns 401, logout revokes token
- `tests/Feature/Auth/RbacTest.php` — 6 tests: 7 roles seeded on tenant creation, owner has all permissions, read_only has only .read perms, cellar_hand blocked from settings/users/billing, role middleware returns 403, token abilities correctly scoped per client type
- 13 auth tests total, all against real PostgreSQL

### Open Questions
- None.

---

## Sub-Task 5: Team Invitation System
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `app/Models/TeamInvitation.php` — Tenant-scoped model: UUID PK, email, role, 64-char random token, invited_by FK, accepted_at, expires_at (72h). Scopes: `pending()`. Helpers: `isExpired()`, `isAccepted()`, `isValid()`.
- `database/migrations/tenant/2026_03_10_000004_create_team_invitations_table.php` — Tenant-scoped table with unique token index, email index, invited_by FK to users
- `app/Http/Controllers/TeamInvitationController.php` — `send()`: validates email/role, blocks duplicates and existing users, creates invitation, sends email. `index()`: lists all invitations with computed status. `cancel()`: deletes pending invitation.
- `app/Http/Controllers/Auth/AcceptInvitationController.php` — Public endpoint (no auth required). Validates token, checks expiry/accepted state, creates user with invited role, assigns spatie role, marks invitation accepted, returns Sanctum token.
- `app/Mail/TeamInvitationMail.php` — Markdown mailable with accept URL, tenant name, role, expiry date, inviter name.
- `resources/views/emails/team-invitation.blade.php` — Markdown email template with accept button
- `routes/api.php` — Added: `POST /auth/accept-invitation` (public), `POST /team/invite` (owner/admin), `GET /team/invitations` (owner/admin), `DELETE /team/invitations/{invitation}` (owner/admin), `GET /team` (any authenticated user)
- `tests/Feature/Team/TeamInvitationTest.php` — 11 tests, 44 assertions

### Key Decisions
- **Owner role cannot be invited**: Validation rejects `owner` in the role field. Owners are only created during tenant registration.
- **72-hour expiry**: Invitations expire after 72 hours. Expired invitations are not deleted — they remain as a record with `expired` status.
- **No auth required to accept**: The accept endpoint is public (within the tenant middleware group). The invitation token acts as the authentication. The invitee doesn't have an account yet.
- **Duplicate prevention**: Blocks sending a new invitation if a pending (not expired, not accepted) invitation already exists for the same email. Also blocks if a user with that email already exists in the tenant.
- **Immediate token on accept**: When an invitation is accepted, the new user gets a portal Sanctum token immediately so they can start using the app right away.

### Deviations from Spec
- None.

### Patterns Established
- **Mail::fake() in invitation tests**: All invitation tests fake the mail to avoid actually sending emails and to assert mail was dispatched.
- **Token-based public endpoints**: Accept invitation follows the pattern of using a cryptographic token for authentication on endpoints where the user doesn't have an account yet.

### Test Summary
- `tests/Feature/Team/TeamInvitationTest.php` — 11 tests: owner can send invitation (mail sent), duplicate pending blocked, existing user blocked, owner role rejected, non-admin forbidden (403), invitee accepts valid invitation (user created with correct role + token returned), expired invitation rejected, already-accepted rejected, invalid token rejected (404), cancel pending invitation, list invitations with status
- 31 tests total across all suites, 102 assertions, 9.28s

### Open Questions
- None.

---

## Sub-Task 6: Event Log Table and Base Service
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `database/migrations/tenant/2026_03_10_000005_create_events_table.php` — Tenant-scoped append-only events table: UUID PK, entity_type, entity_id, operation_type, JSONB payload, performed_by FK, performed_at (client timestamp), synced_at (server receipt, nullable), device_id, idempotency_key (unique, nullable). Includes BRIN index on performed_at for time-series queries, composite index on (entity_type, entity_id), index on operation_type. Database trigger `events_immutability_guard` prevents UPDATE and DELETE at the PostgreSQL level.
- `app/Models/Event.php` — Eloquent model: HasUuids, UPDATED_AT=null (immutable), casts payload as array, datetime casts for performed_at/synced_at. Scopes: `forEntity()`, `ofType()`, `performedBetween()`. Relationship: `performer()` → User.
- `app/Services/EventLogger.php` — Single entry point for writing events. `log()`: creates event with all fields, handles idempotency deduplication (returns existing event on duplicate key), sets synced_at for mobile-synced events, structured logging with tenant_id. `getEntityStream()`: chronological event history for an entity. `getByOperationType()`: events filtered by operation type and time range (for TTB reporting).
- `tests/Feature/EventLog/EventLoggerTest.php` — 13 tests, 37 assertions

### Key Decisions
- **Database-level immutability**: PostgreSQL trigger function `prevent_event_mutation()` raises an exception on UPDATE or DELETE. This is the strongest possible enforcement — even raw SQL or admin tools can't mutate events without first dropping the trigger.
- **BRIN index for performed_at**: BRIN indexes are much smaller than B-tree for sequential/time-ordered data. Events are naturally ordered by time, making BRIN ideal for range queries (TTB reporting, date filters).
- **Idempotency at the service level**: EventLogger checks for existing idempotency_key before INSERT. Returns the existing event silently — no error, no duplicate. Critical for offline mobile sync retries.
- **No updated_at column**: Event model sets `UPDATED_AT = null`. Since events are immutable, there's no need for an update timestamp.
- **Nullable idempotency_key**: Server-created events (e.g., system-generated) may not need idempotency. Null keys are allowed and don't conflict with the unique constraint.

### Deviations from Spec
- None.

### Patterns Established
- **EventLogger as the single write path**: All modules must use `app(EventLogger::class)->log()` to create events. Never use `Event::create()` directly.
- **JSONB payload querying**: PostgreSQL JSONB operators (`payload->>'key'`) work in Eloquent whereRaw. Demonstrated in tests.
- **Immutability testing**: Tests verify both UPDATE and DELETE are blocked by the trigger, and that the original data is preserved.

### Test Summary
- `tests/Feature/EventLog/EventLoggerTest.php` — 13 tests: event creation with all fields, JSONB payload queryable, client-provided performed_at, synced_at for mobile events, synced_at null for local events, performed_by links to user, idempotency key deduplication, null idempotency keys allowed, UPDATE blocked by trigger, DELETE blocked by trigger, entity stream in chronological order, operation type + time range query, cross-tenant isolation
- 44 tests total across all suites, 139 assertions, 13.77s

### Open Questions
- None.

---

## Sub-Task 7: Event Sync API Endpoint
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `app/Http/Controllers/Api/V1/EventSyncController.php` — `POST /api/v1/events/sync`. Accepts a batch of events (max 100), delegates to EventProcessor, returns per-event status (accepted/skipped/failed) with counts.
- `app/Http/Requests/EventSyncRequest.php` — Validates batch: events array required (1–100 items), each event requires entity_type, entity_id (UUID), operation_type, payload (array), performed_at (date within last 30 days, not future), idempotency_key (required for sync). Optional device_id.
- `app/Services/EventProcessor.php` — `processBatch()`: iterates events, wraps each in its own DB transaction. Duplicate idempotency keys are skipped (not errors). Failed events are logged but don't reject the batch. Returns structured results with accepted/skipped/failed counts. Uses EventLogger internally.
- `routes/api.php` — Added `POST /events/sync` under authenticated tenant routes.
- `tests/Feature/EventLog/EventSyncTest.php` — 12 tests, 48 assertions.

### Key Decisions
- **Per-event transactions**: Each event is processed in its own DB transaction. One bad event doesn't reject the entire batch. This matches the spec gotcha exactly.
- **Idempotency key required for sync**: Unlike EventLogger (which allows null keys), the sync endpoint requires idempotency_key on every event. Mobile clients must generate keys client-side for offline safety.
- **30-day window for performed_at**: Events older than 30 days are rejected via validation. Future timestamps are also rejected. This prevents stale data injection while allowing reasonable offline sync windows.
- **Max 100 events per batch**: Prevents oversized payloads. Mobile apps should chunk larger syncs.
- **EventProcessor delegates to EventLogger**: The processor checks for existing idempotency keys first, then calls EventLogger.log() with `isSynced: true`. This sets synced_at automatically.

### Deviations from Spec
- None.

### Patterns Established
- **Form Request validation for batch operations**: EventSyncRequest validates the entire events array with per-item rules (`events.*.field`). Custom error messages for date range violations.
- **Per-event error handling**: Failed events don't break the batch. Results include index, status, and error message for debugging.
- **Sync endpoint always returns 200**: Even partial failures return 200 with per-event status. Only validation failures return 422 (before processing begins).

### Test Summary
- `tests/Feature/EventLog/EventSyncTest.php` — 12 tests: batch acceptance with per-event status, synced_at set on all events, events linked to authenticated user, duplicate idempotency keys skipped, mixed new/duplicate in same batch, full idempotency (double-call same result), >30 days rejected, future timestamps rejected, empty array rejected, required fields validated, authentication required, client performed_at preserved.
- 56 tests total across all suites, 187 assertions, 17.86s

### Open Questions
- None.

---

## Sub-Task 8: Winery Profile and Onboarding Setup
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `database/migrations/tenant/2026_03_10_000006_create_winery_profiles_table.php` — Tenant-scoped profile: UUID PK, identity fields (name, dba_name, description, logo, website, phone, email), location (address, city, state, zip, country, timezone), compliance (ttb_permit_number, ttb_registry_number, state_license_number), preferences (unit_system, currency, fiscal_year_start_month, date_format), onboarding_complete flag.
- `app/Models/WineryProfile.php` — HasUuids, all fields fillable, casts for fiscal_year_start_month (int) and onboarding_complete (bool). Helpers: `usesImperial()`, `usesMetric()`.
- `app/Http/Controllers/Api/V1/WineryProfileController.php` — `show()`: returns profile for any authenticated user. `update()`: partial updates with validation (unit_system in [imperial, metric], timezone validates IANA, fiscal_year_start_month 1-12). Owner/admin only.
- `database/seeders/TenantDatabaseSeeder.php` — Updated to auto-create a WineryProfile with the tenant's name on provisioning.
- `database/seeders/DemoWinerySeeder.php` — Creates "Paso Robles Cellars" tenant with realistic data: Adelaida District address, TTB permits, July fiscal year, 7 demo users (one per role). Idempotent.
- `database/seeders/DatabaseSeeder.php` — Updated to call DemoWinerySeeder.
- `routes/api.php` — Added: `GET /winery` (any auth user), `PUT /winery` (owner/admin).
- `tests/Feature/WineryProfile/WineryProfileTest.php` — 11 tests, 59 assertions.

### Key Decisions
- **Auto-create profile on tenant provisioning**: TenantDatabaseSeeder creates a default WineryProfile with the tenant's name. This ensures every tenant always has exactly one profile row.
- **Partial updates via PUT**: The update endpoint uses `sometimes` validation — only submitted fields are validated and updated. This allows the frontend to send incremental onboarding steps.
- **Fiscal year start month**: Stored as integer 1-12. Affects reporting periods across all modules (TTB reports, financial summaries). July (7) is common for wineries.
- **Timezone stored per winery**: Used for scheduled jobs (club processing, report generation). Defaults to America/Los_Angeles.
- **Demo winery is idempotent**: Checks for existing slug before creating. Running `db:seed` twice doesn't duplicate.

### Deviations from Spec
- None.

### Patterns Established
- **One profile per tenant**: Auto-created by seeder. Controller uses `firstOrFail()` — no need to scope by ID.
- **Preference-aware modules**: Future modules should check `WineryProfile::first()->usesImperial()` for unit conversions and `fiscal_year_start_month` for reporting boundaries.

### Test Summary
- `tests/Feature/WineryProfile/WineryProfileTest.php` — 11 tests: auto-creation on provisioning, GET returns profile, unauthenticated rejected, owner can update, partial updates work, unit_system validated, fiscal_year_start_month validated, timezone validated, non-admin cannot update (403), demo seeder creates full winery, demo seeder is idempotent.
- 67 tests total across all suites, 246 assertions, 22.47s

### Open Questions
- None.

---

## Sub-Task 9: Filament Management Portal Shell
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `filament/filament` v3.x installed via Composer
- `app/Providers/Filament/AdminPanelProvider.php` — Panel at `/portal`, ID `portal`, brand "VineSuite", purple primary color. 7 navigation groups (Production, Inventory, Compliance, Sales, Club, CRM, Settings). Tenant-aware via InitializeTenancyByDomain + PreventAccessFromCentralDomains middleware. Session-based auth with Filament's Authenticate middleware. Sidebar collapsible, full-width content.
- `app/Filament/Pages/Dashboard.php` — Custom dashboard page. Heading shows winery name from WineryProfile. Placeholder for future widgets.
- `app/Filament/Resources/UserResource.php` — Team member management under Settings group. Table with name, email, role badge, active status, last login. Filters for role and active status. Actions: deactivate (owner can't be deactivated), activate, edit. Form with name, email, role select, active toggle. Access restricted to owner/admin via `canAccess()`.
- `app/Filament/Resources/UserResource/Pages/ListUsers.php` — List page for team members.
- `app/Filament/Resources/UserResource/Pages/EditUser.php` — Edit page with spatie role sync on save (`afterSave` syncs the role column to spatie roles).
- `tests/Feature/Filament/PortalTest.php` — 11 tests, 22 assertions.

### Key Decisions
- **Panel path `/portal` not `/admin`**: Matches the spec. The panel ID is `portal` to avoid confusion with generic admin panels.
- **Domain-based tenancy for portal**: Portal uses InitializeTenancyByDomain (subdomain routing) while the API uses InitializeTenancyByRequestData (X-Tenant-ID header). Both patterns coexist.
- **Session auth for portal, token auth for API**: Filament uses session-based auth (its default). The API continues to use Sanctum tokens. No conflict — they use different middleware stacks.
- **No delete action on users**: Users are deactivated, never deleted. The deactivate action is hidden for owner-role users to prevent locking out the account.
- **Role sync on edit save**: When a user's role is changed in the portal, `afterSave()` calls `syncRoles()` to keep the spatie permission role in sync with the role column.
- **Empty navigation groups**: The 7 navigation groups are registered but most have no resources yet. They serve as the skeleton for future modules.

### Deviations from Spec
- None.

### Patterns Established
- **Filament resource access control**: Use `canAccess()` static method to restrict resources by role. Check `auth()->user()->role` directly for speed.
- **Portal + API coexistence**: Portal routes use session + domain tenancy. API routes use token + header tenancy. Both resolve to the same tenant schemas.

### Test Summary
- `tests/Feature/Filament/PortalTest.php` — 11 tests: panel at /portal, 7 navigation groups, brand name, dashboard page exists, UserResource in Settings group, label is Team Members, has list+edit pages, owner can access, admin can access, winemaker cannot access, read_only cannot access.
- 78 tests total across all suites, 268 assertions, 25.46s

### Open Questions
- None.

---

## Sub-Task 10: Stripe Billing Integration (SaaS Subscriptions)
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `laravel/cashier` v16.4 installed via Composer
- `app/Models/Tenant.php` — Added `Billable` trait. Billing is per-tenant (not per-user). Added `PLANS` constant, `stripePriceForPlan()`, `hasActiveSubscription()`, `isInGracePeriod()`. Updated `getCustomColumns()` with pm_type, pm_last_four, trial_ends_at.
- `database/migrations/0001_01_01_000013_create_cashier_tables.php` — Central migration: adds pm_type, pm_last_four, trial_ends_at to tenants table. Creates subscriptions and subscription_items tables in central schema with tenant_id FK.
- `app/Http/Controllers/BillingController.php` — `checkout()`: creates Stripe Checkout session for a plan. `portal()`: creates Stripe Customer Portal session. `changePlan()`: swaps subscription price and updates tenant plan column. `status()`: returns current billing state.
- `app/Http/Controllers/WebhookController.php` — Extends Cashier's webhook controller. Handles `customer.subscription.updated` (syncs plan column), `customer.subscription.deleted` (logs grace period start).
- `app/Listeners/HandleSubscriptionChange.php` — Listens for Cashier `WebhookReceived` events. Handles `invoice.payment_succeeded` and `invoice.payment_failed` with structured logging.
- `app/Providers/AppServiceProvider.php` — Registers HandleSubscriptionChange listener for WebhookReceived event.
- `bootstrap/app.php` — Added CSRF exception for `api/v1/stripe/webhook`.
- `routes/api.php` — Added: `POST /stripe/webhook` (central, no auth), `GET /billing/status`, `POST /billing/checkout`, `POST /billing/portal`, `PUT /billing/plan` (all owner/admin + tenant-scoped).
- `.env` — Stripe test keys configured, CASHIER_MODEL=App\Models\Tenant.
- `tests/Feature/Billing/BillingTest.php` — 15 tests, 44 assertions.

### Key Decisions
- **Tenant is the Billable, not User**: SaaS billing is per-winery. The Tenant model has the Cashier Billable trait. Subscriptions and subscription_items tables reference tenant_id.
- **Central schema billing**: Cashier tables (subscriptions, subscription_items) live in the central public schema alongside tenants/domains. This is NOT per-tenant Stripe Connect.
- **Plan price IDs via env vars**: `STRIPE_PRICE_STARTER`, `STRIPE_PRICE_GROWTH`, `STRIPE_PRICE_PRO` env vars map plans to Stripe price IDs. Products need to be created in Stripe Dashboard.
- **Webhook extends Cashier controller**: Custom WebhookController extends `CashierWebhookController` to add plan syncing on subscription changes. Cashier handles the core subscription lifecycle automatically.
- **CSRF exclusion for webhook**: The Stripe webhook endpoint is excluded from CSRF verification in bootstrap/app.php.
- **Grace period on cancellation**: When a subscription is cancelled, Cashier tracks `ends_at`. The 30-day read-only window is enforced via Cashier's `onGracePeriod()` method.

### Deviations from Spec
- **Stripe products not yet created**: The test keys are configured but no Stripe products/prices exist yet. Checkout will return 422 until STRIPE_PRICE_* env vars are set. This is expected — products should be created in the Stripe Dashboard.

### Patterns Established
- **Central billing, tenant-scoped data**: Billing lives in central schema. Winery data lives in tenant schemas. Both are accessed through different routes.
- **Webhook handling pattern**: Extend Cashier's webhook controller for custom logic. Use the WebhookReceived event listener for invoice-level handling.

### Test Summary
- `tests/Feature/Billing/BillingTest.php` — 15 tests: Billable trait on Tenant, plan helpers, stripePriceForPlan null without env, billing status for owner, auth required, RBAC enforced, checkout rejects without price config, checkout validates plan, portal rejects without customer, plan change rejects without subscription, plan change validates, webhook route reachable, subscriptions table exists, subscription_items table exists, tenants has Cashier columns.
- 93 tests total across all suites, 312 assertions, 31.16s

### Open Questions
- Stripe products (Starter, Growth, Pro) need to be created in the Stripe Dashboard and price IDs added to .env as STRIPE_PRICE_STARTER, STRIPE_PRICE_GROWTH, STRIPE_PRICE_PRO.

---

## Sub-Task 11: Activity Logging System
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `database/migrations/tenant/2026_03_10_000007_create_activity_logs_table.php` — Tenant-scoped immutable activity log table: UUID PK, user_id FK (nullable for system), action (created/updated/deleted), model_type, model_id, old_values JSONB, new_values JSONB, changed_fields JSONB, ip_address, user_agent. BRIN index on created_at, composite index on (model_type, model_id). PostgreSQL trigger `activity_logs_immutability_guard` prevents UPDATE and DELETE.
- `app/Models/ActivityLog.php` — Eloquent model: HasUuids, UPDATED_AT=null (immutable), JSONB casts for old_values/new_values/changed_fields. Scopes: `forModel()`, `byUser()`, `ofAction()`. Relationship: `user()` → User.
- `app/Traits/LogsActivity.php` — Trait for automatic Eloquent model auditing. Registers created/updated/deleted observers via `bootLogsActivity()`. Captures old values, new values, changed fields, authenticated user, IP address, user agent. Filters sensitive fields (password, remember_token always excluded). Supports `$activityLogExclude` and `$activityLogOnly` property overrides. Skips logging when only excluded fields change. Wrapped in try/catch so logging failures never break the application.
- `app/Filament/Resources/ActivityLogResource.php` — Read-only Filament resource under Settings group. Table with created_at, user name, action badge (color-coded), model type, model ID. Filters for action type and user. 30-second auto-poll for real-time updates. `canCreate()` returns false. `canAccess()` restricts to owner/admin.
- `app/Filament/Resources/ActivityLogResource/Pages/ListActivityLogs.php` — List page with no header actions (no create button).
- `app/Filament/Resources/ActivityLogResource/Pages/ViewActivityLog.php` — View page with Infolist: activity details section (when, who, action, model, record ID, IP, user agent), changed fields section, collapsible old/new values sections with JSON display.
- `app/Models/User.php` — Added `LogsActivity` trait. Excludes: updated_at, created_at, email_verified_at (password/remember_token always excluded by trait).
- `app/Models/WineryProfile.php` — Added `LogsActivity` trait. Excludes: updated_at, created_at.
- `app/Models/TeamInvitation.php` — Added `LogsActivity` trait. Excludes: token, updated_at, created_at.
- `tests/Feature/ActivityLog/ActivityLogTest.php` — 14 tests, 54 assertions.

### Key Decisions
- **Database-level immutability**: Same pattern as Event Log (Sub-Task 6). PostgreSQL trigger prevents UPDATE and DELETE on activity_logs. Audit trail cannot be tampered with.
- **Separate from Event Log**: Activity logs track system-level changes (user edited a profile, changed a setting). Event logs track winery operations (additions, transfers, fermentations). Different concerns, different tables.
- **Try/catch resilience**: The LogsActivity trait wraps all logging in try/catch. If the activity_logs table is unavailable or logging fails for any reason, the original operation (create/update/delete) still succeeds. Logging is never a blocking concern.
- **Sensitive field filtering**: Password and remember_token are always excluded regardless of model config. Models can add additional exclusions via `$activityLogExclude` or restrict to specific fields via `$activityLogOnly`.
- **Applied to three models**: User, WineryProfile, TeamInvitation. Future models should add the trait as they're built.
- **Read-only Filament resource**: No create/edit/delete actions. Activity logs are for viewing only. Owner and admin roles can access.

### Deviations from Spec
- None.

### Patterns Established
- **LogsActivity trait on tenant models**: Add `use LogsActivity;` to any tenant-scoped model that should be audited. Configure exclusions via `$activityLogExclude`.
- **Immutability at DB level**: Both events and activity_logs use PostgreSQL triggers to enforce immutability. This is the project standard for append-only tables.
- **Filament read-only resources**: Set `canCreate()` to false and remove edit/delete actions for resources that should only be viewed.

### Test Summary
- `tests/Feature/ActivityLog/ActivityLogTest.php` — 14 tests: immutability trigger (UPDATE and DELETE blocked), JSONB casts, scopes (forModel, byUser, ofAction), auto-log model creation (new_values captured), auto-log model updates (old/new values + changed_fields), auto-log model deletion (old_values captured), sensitive fields excluded (password, remember_token, timestamps), no spurious update logs for excluded-only changes, authenticated user captured in log, WineryProfile update captures field diffs, cross-tenant isolation (no data leakage), Filament resource config (canCreate false, Settings group, label), Filament access control (owner/admin only, winemaker blocked), trait resilience (operations succeed even if logging fails).
- 107 tests total across all suites, 366 assertions, 36.85s

### Open Questions
- None.

---

## Sub-Task 12: CI/CD Pipeline Setup
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `.github/workflows/ci.yml` — Three parallel CI jobs triggered on push to any branch and PRs to main:
  - **Pint (Code Style)** — runs `vendor/bin/pint --test` to enforce Laravel coding standards
  - **PHPStan (Level 6)** — static analysis via Larastan, scans `app/` directory
  - **Pest (Tests)** — runs full test suite against a PostgreSQL 16 service container
  - All jobs cache Composer dependencies via `actions/cache@v4` keyed to `composer.lock`
- `.github/workflows/deploy.yml` — Deploy to staging: triggers on push to `main`, reuses CI workflow as prerequisite, fires Laravel Forge deploy webhook. Uses `concurrency` group and `staging` environment for secrets.
- `api/phpstan.neon` — Larastan extension at level 6, scans `app/`, excludes `app/Providers/Filament`.
- `api/pint.json` — Laravel preset with `declare_strict_types`, `no_unused_imports`, alphabetical imports.
- `larastan/larastan:^3.0` — Added as dev dependency.
- `config/services.php` — Added `stripe.price_starter/growth/pro` config entries to replace direct `env()` calls.

### Code Quality Fixes Applied
- **41 Pint style issues fixed** — `declare_strict_types` on all stock Laravel files, unused imports removed, spacing/concat fixes.
- **79 PHPStan errors fixed** to reach zero at level 6:
  - `Builder<Model>` generics on all scope methods (Event, ActivityLog, TeamInvitation)
  - `BelongsTo<User, $this>` generics on all relationship methods
  - `@param array<string, mixed>` PHPDoc types on array parameters across services/controllers
  - WebhookController return types matched to Cashier parent signatures
  - `env()` calls outside config replaced with `config()` (Tenant, WebhookController)
  - `@var array<int, string>` on `$activityLogExclude` properties
  - `@use HasFactory<UserFactory>` on User model
  - `@return` types on TenancyServiceProvider, Tenant, EventSyncRequest
  - Nullsafe fixes in LogsActivity and TeamInvitationMail

### Key Decisions
- **Three parallel CI jobs**: Lint, static analysis, and tests run independently for faster feedback.
- **PostgreSQL 16 in CI**: Matches production. Tests against real PostgreSQL, never SQLite.
- **PHPStan level 6**: Strict enough to catch real bugs without generics noise. Filament providers excluded.
- **Larastan over vanilla PHPStan**: Understands Laravel magic (facades, models, relationships, scopes).
- **Deploy via Forge webhook**: Simple, reliable. Webhook URL stored as GitHub secret.
- **env() → config() migration**: `env()` returns null when config is cached. Stripe prices moved to `config/services.php`.

### Deviations from Spec
- **`pint.json` instead of `.php-cs-fixer.php`** — Pint is Laravel's standard tool (wraps PHP-CS-Fixer).
- **No PHPStan baseline** — All 79 errors fixed rather than baselined. Clean zero errors.

### Patterns Established
- **CI on every push**: Catches issues early on feature branches.
- **Code style enforced by CI**: Pint auto-fixes locally, CI rejects if forgotten.
- **Static analysis as gate**: PHPStan must pass before merge.
- **All array parameters typed**: `array<string, mixed>` for associative, `array<int, string>` for lists.

### Test Summary
- No new tests (infrastructure-only sub-task).
- All existing tests pass after fixes: 107 tests, 366 assertions, 36.44s.

### Open Questions
- `FORGE_DEPLOY_WEBHOOK_URL` GitHub secret needs to be configured in the repository's `staging` environment once Forge is set up.

---
