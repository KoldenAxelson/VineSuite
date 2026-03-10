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
