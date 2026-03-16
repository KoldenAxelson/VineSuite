# Filament v3 + stancl/tenancy v3.9 Integration

> Relevant: `AdminPanelProvider.php`, `AppServiceProvider.php`, `config/tenancy.php`
> See `02-production-core.info.md` for full debugging story

Running Filament v3's admin portal on tenant subdomains requires three fixes. Missing any one produces a different failure mode.

---

## Prerequisites

Filament's pre-compiled assets must exist on disk:

```bash
docker compose exec app php artisan filament:assets
docker compose exec app php artisan storage:link
```

No separate `npm run dev` process needed — the portal runs entirely on published PHP assets.

---

## Fix 1: Disable Asset URL Rewriting

**Symptom:** All CSS and JS return 404. Network tab shows `/tenancy/assets/css/filament/...` instead of `/css/filament/...`. Login page renders unstyled.

**Root cause:** stancl's `FilesystemTenancyBootstrapper` defaults `asset_helper_tenancy` to `true`. This prefixes all asset URLs with `/tenancy/assets/`, which doesn't know about Filament's published static assets.

**Fix:** In `config/tenancy.php`:

```php
'filesystem' => [
    'suffix_base' => 'tenant_',
    'disks' => ['local', 'public'],
    'asset_helper_tenancy' => false,  // ← Critical
],
```

---

## Fix 2: Livewire Update Route Needs Tenancy Middleware

**Symptom:** Login form says "credentials do not match" even though they're correct. Running `Auth::attempt()` in tinker inside `$tenant->run()` returns `true`.

**Root cause:** Filament's login is a Livewire component. When submitted, it POSTs to `/livewire/update` — Livewire's internal route with its own middleware stack that doesn't include tenancy middleware. Without it, the POST queries the central `users` table (bigint PKs) instead of tenant's `users` (UUIDs).

**Fix:** In `AppServiceProvider::boot()`:

```php
use Livewire\Livewire;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

public function boot(): void
{
    Livewire::setUpdateRoute(function ($handle) {
        return Route::post('/livewire/update', $handle)
            ->middleware([
                'web',
                InitializeTenancyByDomain::class,
                PreventAccessFromCentralDomains::class,
            ]);
    });
}
```

---

## Fix 3: Middleware Ordering — Tenancy Before Session

**Symptom:** Login succeeds, but redirect throws `SQLSTATE[22P02]` trying to look up UUID user ID in central `users` table.

**Root cause:** `InitializeTenancyByDomain` is placed after `StartSession`. The session starts in central database context, so auth reads the user ID from session on next request and queries the central table.

**Fix:** In `AdminPanelProvider`, order middleware so tenancy initializes before session:

```php
->middleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    InitializeTenancyByDomain::class,       // ← Before StartSession
    PreventAccessFromCentralDomains::class,
    StartSession::class,
    AuthenticateSession::class,
    ShareErrorsFromSession::class,
    VerifyCsrfToken::class,
    SubstituteBindings::class,
    DisableBladeIconComponents::class,
    DispatchServingFilamentEvent::class,
])
```

---

## Domain Records

`InitializeTenancyByDomain` matches the **full hostname**, not the subdomain prefix. The domain record must be the complete hostname:

```php
// Correct — full hostname
$tenant->domains()->create(['domain' => 'paso-robles-cellars.localhost']);

// Wrong — will fail with TenantCouldNotBeIdentifiedOnDomainException
$tenant->domains()->create(['domain' => 'paso-robles-cellars']);
```

For local dev on macOS, add `/etc/hosts` entries (macOS doesn't resolve `*.localhost` by default):

```
127.0.0.1  paso-robles-cellars.localhost
```

---

## Setup Checklist

1. `php artisan filament:assets` has been run (files exist in `public/css/filament/` and `public/js/filament/`)
2. `config/tenancy.php` → `filesystem.asset_helper_tenancy` is `false`
3. `AppServiceProvider::boot()` has `Livewire::setUpdateRoute()` with tenancy middleware
4. `AdminPanelProvider` middleware has `InitializeTenancyByDomain` **before** `StartSession`
5. Domain records store full hostname (e.g., `winery-name.localhost`, not `winery-name`)
6. `/etc/hosts` has entries for local development subdomains

---

## Related Files

- `api/app/Providers/Filament/AdminPanelProvider.php`
- `api/app/Providers/AppServiceProvider.php`
- `api/config/tenancy.php`
- `api/database/seeders/DemoWinerySeeder.php`
