# Multi-Tenancy

> Last updated: 2026-03-15
> Relevant source: `api/config/tenancy.php`, `api/app/Models/Tenant.php`, `api/app/Providers/TenancyServiceProvider.php`
> Architecture doc: Section 2.3

---

## What This Is
Schema-per-tenant isolation via `stancl/tenancy` v3.9. Each winery gets its own PostgreSQL schema. Central schema holds: tenant registry, billing, VineBook directory data. Tenant identification via subdomain or API token header.

## How It Works
When a tenant is created via `Tenant::create()`, stancl fires a `TenantCreated` event. The `TenancyServiceProvider` listens for this and runs a job pipeline:

1. **CreateDatabase** — Runs `CREATE SCHEMA tenant_{uuid}` on PostgreSQL
2. **MigrateDatabase** — Runs migrations from `database/migrations/tenant/` in the new schema
3. **SeedDatabase** — Runs `TenantDatabaseSeeder` to populate default data

When a request arrives, tenant identification middleware switches the app context:
- **Subdomain**: `InitializeTenancyByDomain` middleware matches `winery.vinesuite.com` → looks up domain record → initializes tenancy
- **API token header**: `InitializeTenancyByRequestData` with `X-Tenant-ID` header (implemented in Sub-Task 4)

Once tenancy is initialized, bootstrappers switch:
- **Database**: All queries go to `tenant_{uuid}` schema
- **Cache**: Keys prefixed with `tenant_{uuid}`
- **Filesystem**: Storage paths scoped to tenant
- **Queue**: Jobs tagged with tenant context

## Key Files
- `config/tenancy.php` — Central config: connection settings, schema prefix, bootstrappers, migration paths
- `app/Models/Tenant.php` — Tenant model with UUID PK, custom columns (name, slug, plan, stripe fields)
- `app/Providers/TenancyServiceProvider.php` — Event listeners for tenant lifecycle, route registration
- `app/Jobs/CreateTenantJob.php` — Queued job for async tenant provisioning
- `database/migrations/0001_01_01_000010_create_tenants_table.php` — Central tenants table
- `database/migrations/0001_01_01_000011_create_domains_table.php` — Central domains table
- `database/migrations/tenant/` — Directory for tenant-scoped migrations
- `database/seeders/TenantDatabaseSeeder.php` — Default data for new tenants
- `routes/tenant.php` — Routes that run in tenant context

## Usage Patterns

### Creating a tenant (sync, e.g. in tests or tinker)
```php
$tenant = Tenant::create([
    'name' => 'Mountain View Winery',
    'slug' => 'mountain-view',
    'plan' => 'starter',
]);
$tenant->domains()->create(['domain' => 'mountain-view']);
```

### Creating a tenant (async, from API)
```php
CreateTenantJob::dispatch(
    name: 'Mountain View Winery',
    slug: 'mountain-view',
    plan: 'starter',
    ownerEmail: 'owner@mountainview.com',
);
```

### Running code in tenant context
```php
$tenant->run(function () {
    // All DB queries here hit the tenant's schema
    $users = User::all();
    $events = Event::where('entity_type', 'lot')->get();
});
```

### Accessing tenant from request context
```php
// Inside a controller with tenancy middleware active:
$tenantId = tenant('id');
$tenantName = tenant('name');
```

## Filament + Tenancy Integration

Running Filament v3 on tenant subdomains requires three critical configuration changes. See `guides/filament-tenancy.md` for the full walkthrough. Summary:

1. **Disable asset URL rewriting**: Set `'asset_helper_tenancy' => false` in `config/tenancy.php` → `filesystem` section. Without this, all Filament CSS/JS requests are rewritten to `/tenancy/assets/...` which 404s.

2. **Add tenancy middleware to Livewire's update route**: In `AppServiceProvider::boot()`, call `Livewire::setUpdateRoute()` and include `InitializeTenancyByDomain::class` in the middleware stack. Without this, Filament's login form POSTs query the central database.

3. **Middleware ordering in AdminPanelProvider**: `InitializeTenancyByDomain` and `PreventAccessFromCentralDomains` must come **before** `StartSession` in the middleware array. If session starts before tenancy initializes, auth resolves against the central database (UUID vs bigint PK mismatch).

### Domain Records
`InitializeTenancyByDomain` matches the **full hostname**, not just the subdomain prefix. Domain records must store the complete hostname (e.g., `paso-robles-cellars.localhost`, not `paso-robles-cellars`). For local development, add entries to `/etc/hosts` since macOS doesn't resolve `*.localhost` by default.

## Gotchas
- Every query automatically scopes to the current tenant's schema — no `WHERE winery_id = ?` needed
- Central data (tenants table, billing, VineBook) lives in the `public` schema
- Scaling ceiling: ~500 tenants before migration runs become painful (see architecture.md Section 2.3)
- Never raw-query across tenant schemas — use the tenancy package's central connection for cross-tenant work
- **Testing**: Tenancy tests MUST use `DatabaseMigrations`, not `RefreshDatabase`. PostgreSQL DDL (`CREATE SCHEMA`) deadlocks inside the transaction wrapper that RefreshDatabase uses
- `afterEach` in tenancy tests should drop `tenant_%` schemas to prevent test pollution
- The `data` JSON column on tenants table stores any attributes not in `getCustomColumns()` — add new columns there explicitly if they need indexing
- **Filament assets**: Must run `php artisan filament:assets` inside the container after install to publish pre-compiled JS/CSS to `public/`
- **`lot_vessel` pivot UUID**: The lot_vessel pivot table uses `uuid('id')->primary()` — `attach()` calls must include `'id' => (string) Str::uuid()` or the insert will fail with a not-null violation

## History
- 2026-03-10: Initial implementation. Schema-per-tenant with PostgreSQLSchemaManager. Subdomain identification wired.
- 2026-03-10: Sub-Task 4 — API token identification via `X-Tenant-ID` header implemented. Both subdomain and header identification active.
- 2026-03-15: Phase 2 — Filament + tenancy integration documented. Three critical middleware/config fixes required. Domain record format clarified. UUID pivot gotcha documented.
