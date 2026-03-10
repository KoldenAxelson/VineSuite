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
