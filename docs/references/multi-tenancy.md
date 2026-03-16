# Multi-Tenancy

> Schema-per-tenant via stancl/tenancy v3.9. One schema per winery. Central schema: tenants, billing, VineBook.

---

## How It Works

**Tenant creation:** `Tenant::create()` → fires TenantCreated event → runs pipeline:
1. CreateDatabase — `CREATE SCHEMA tenant_{uuid}`
2. MigrateDatabase — Run `database/migrations/tenant/` in new schema
3. SeedDatabase — Run TenantDatabaseSeeder

**Tenant identification:**
- **Subdomain:** `InitializeTenancyByDomain` matches `winery.vinesuite.com`
- **API token header:** `InitializeTenancyByRequestData` with `X-Tenant-ID` header

**Bootstrappers switch:** Database, cache, filesystem, queue all scoped to tenant.

---

## Usage

**Sync creation (tests, tinker):**
```php
$tenant = Tenant::create([
    'name' => 'Mountain View Winery',
    'slug' => 'mountain-view',
    'plan' => 'starter',
]);
$tenant->domains()->create(['domain' => 'mountain-view']);
```

**Async creation (API):**
```php
CreateTenantJob::dispatch(
    name: 'Mountain View Winery',
    slug: 'mountain-view',
    plan: 'starter',
    ownerEmail: 'owner@mountainview.com',
);
```

**Run code in tenant context:**
```php
$tenant->run(function () {
    $users = User::all();  // Hits tenant schema
    $events = Event::where('entity_type', 'lot')->get();
});
```

**Access tenant from request context:**
```php
// Inside controller with tenancy middleware:
$tenantId = tenant('id');
$tenantName = tenant('name');
```

---

## Filament + Tenancy Integration

Three critical fixes required:

1. **Disable asset URL rewriting:**
   ```php
   // config/tenancy.php → filesystem section
   'asset_helper_tenancy' => false
   ```
   Without this, all Filament CSS/JS requests are rewritten to `/tenancy/assets/...` which 404s.

2. **Add tenancy middleware to Livewire update route:**
   ```php
   // AppServiceProvider::boot()
   Livewire::setUpdateRoute(function ($handle) {
       return Route::middleware([InitializeTenancyByDomain::class])->post('/livewire/update', $handle);
   });
   ```

3. **Middleware ordering in AdminPanelProvider:**
   `InitializeTenancyByDomain` and `PreventAccessFromCentralDomains` BEFORE `StartSession`. If session starts before tenancy initializes, auth resolves to central DB (UUID vs bigint PK mismatch).

---

## Domain Records

`InitializeTenancyByDomain` matches full hostname (e.g., `paso-robles-cellars.localhost`), not just subdomain. For local dev, add entries to `/etc/hosts` (macOS doesn't resolve `*.localhost` by default).

---

## Gotchas

- Every query automatically scopes to tenant's schema — no `WHERE winery_id = ?` needed
- Central data lives in `public` schema only
- Scaling ceiling: ~500 tenants before migration runs become painful
- **Testing:** Use `DatabaseMigrations`, not `RefreshDatabase`. PostgreSQL DDL deadlocks inside RefreshDatabase's transaction wrapper
- `afterEach` in tenancy tests should drop `tenant_%` schemas to prevent pollution
- **Filament assets:** Run `php artisan filament:assets` inside container after install to publish pre-compiled JS/CSS to `public/`
- **lot_vessel pivot UUID:** Must include `'id' => (string) Str::uuid()` on attach() or null constraint fails

---

## Key Files

- `config/tenancy.php` — Schema prefix, bootstrappers, migration paths
- `app/Models/Tenant.php` — UUID PK, name, slug, plan, stripe fields
- `app/Providers/TenancyServiceProvider.php` — Event listeners
- `app/Jobs/CreateTenantJob.php` — Async tenant provisioning
- `database/migrations/0001_01_01_000010_create_tenants_table.php`
- `routes/tenant.php` — Tenant-scoped routes

---

*Phase 1 (initial). Phase 2 (Filament integration). API token identification (Sub-Task 4).*
