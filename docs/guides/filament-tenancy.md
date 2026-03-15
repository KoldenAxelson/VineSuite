# Filament v3 + stancl/tenancy v3.9 Integration Guide

> Created: 2026-03-15
> Relevant source: `AdminPanelProvider.php`, `AppServiceProvider.php`, `config/tenancy.php`
> Context: Phase 2, Sub-Task 13 — see `02-production-core.info.md` for the full debugging story

---

## Overview

Running Filament v3's management portal on tenant subdomains with stancl/tenancy requires three separate fixes. Each addresses a different layer (asset serving, Livewire routing, session/auth middleware), and all three must be in place for the portal to function. Missing any one of them produces a different failure mode.

This guide documents the exact symptoms, root causes, and fixes — learned through significant trial and error — so future developers don't have to rediscover them.

---

## Prerequisites

Before any of this matters, Filament's pre-compiled assets must exist on disk:

```bash
docker compose exec app php artisan filament:assets
docker compose exec app php artisan storage:link
```

Filament bundles Alpine.js and Livewire internally — there is no separate `npm run dev` process needed. The portal runs entirely on published PHP assets.

---

## Fix 1: Disable Asset URL Rewriting

**Symptom:** All CSS and JS files return 404. Browser Network tab shows requests to URLs like `/tenancy/assets/css/filament/filament/app.css?v=3.3.49.0` instead of `/css/filament/filament/app.css`. The login page renders as unstyled HTML.

**Root cause:** stancl's `FilesystemTenancyBootstrapper` defaults `asset_helper_tenancy` to `true`. When active, it overrides Laravel's `asset()` helper to prefix all asset URLs with `/tenancy/assets/`. This route is meant to serve tenant-specific uploaded files, but it doesn't know about Filament's published static assets — so every CSS/JS request 404s.

**Fix:** In `config/tenancy.php`, set `asset_helper_tenancy` to `false` in the filesystem section:

```php
'filesystem' => [
    'suffix_base' => 'tenant_',
    'disks' => ['local', 'public'],
    'root_override' => [
        'local' => '%storage_path%/app/',
        'public' => '%storage_path%/app/public/',
    ],
    'suffix_storage_path' => true,
    'asset_helper_tenancy' => false,  // ← Critical for Filament
],
```

**Diagnosis tip:** Open Safari/Chrome DevTools → Network tab. If CSS/JS URLs contain `/tenancy/assets/`, this is the issue. If they're at the normal path (`/css/filament/...`) and still 404, the problem is that `filament:assets` hasn't been run.

---

## Fix 2: Livewire Update Route Needs Tenancy Middleware

**Symptom:** Login form shows "These credentials do not match our records" even though the credentials are correct. Running `Auth::attempt()` in tinker inside `$tenant->run()` returns `true`. The login form appears styled (Fix 1 is working), but authentication always fails.

**Root cause:** Filament's login form is a Livewire component. When you submit credentials, the browser POSTs to `/livewire/update` — this is Livewire's internal route, not a Filament route. It has its own middleware stack that doesn't include any of the tenancy middleware from `AdminPanelProvider`. Without tenancy middleware, the POST queries the central `users` table (which uses bigint PKs) instead of the tenant's `users` table (which uses UUIDs).

**Fix:** In `AppServiceProvider::boot()`, override Livewire's update route to include tenancy middleware:

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

**Diagnosis tip:** If you can authenticate via tinker (`$tenant->run(fn() => Auth::attempt([...]))` returns `true`) but the form rejects the same credentials, the issue is that the form POST isn't running in tenant context.

---

## Fix 3: Middleware Ordering — Tenancy Before Session

**Symptom:** Login appears to succeed (no "credentials don't match" error), but the redirect after login throws `SQLSTATE[22P02]: Invalid text representation: 7 ERROR: invalid input syntax for type bigint` — Laravel is trying to look up a UUID user ID in the central `users` table which expects bigint PKs.

**Root cause:** `InitializeTenancyByDomain` is placed after `StartSession` in AdminPanelProvider's middleware stack. The session starts in central database context, so when the auth guard reads the user ID from the session on the next request, it queries the central `users` table with a UUID value.

**Fix:** In `AdminPanelProvider`, order the middleware so tenancy initializes before the session starts:

```php
->middleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    InitializeTenancyByDomain::class,       // ← Before StartSession
    PreventAccessFromCentralDomains::class,  // ← Before StartSession
    StartSession::class,
    AuthenticateSession::class,
    ShareErrorsFromSession::class,
    VerifyCsrfToken::class,
    SubstituteBindings::class,
    DisableBladeIconComponents::class,
    DispatchServingFilamentEvent::class,
])
```

**Diagnosis tip:** If login succeeds but the redirect crashes with a type mismatch error (bigint vs UUID), the session is starting before tenancy. Check middleware ordering.

---

## Domain Records

`InitializeTenancyByDomain` matches the **full hostname** from the request, not just the subdomain prefix. The domain record stored in the `domains` table must be the complete hostname:

```php
// Correct — stores full hostname
$tenant->domains()->create(['domain' => 'paso-robles-cellars.localhost']);

// Wrong — will fail with TenantCouldNotBeIdentifiedOnDomainException
$tenant->domains()->create(['domain' => 'paso-robles-cellars']);
```

For local development, macOS does not resolve `*.localhost` to `127.0.0.1` by default. Add entries to `/etc/hosts`:

```
127.0.0.1  paso-robles-cellars.localhost
```

---

## Quick Checklist

When setting up a new Filament panel on tenant subdomains, verify:

1. `php artisan filament:assets` has been run (files exist in `public/css/filament/` and `public/js/filament/`)
2. `config/tenancy.php` → `filesystem.asset_helper_tenancy` is `false`
3. `AppServiceProvider::boot()` has `Livewire::setUpdateRoute()` with tenancy middleware
4. `AdminPanelProvider` middleware has `InitializeTenancyByDomain` **before** `StartSession`
5. Domain records store the full hostname (e.g., `winery-name.localhost`, not `winery-name`)
6. `/etc/hosts` has entries for any local development subdomains

---

## Related Files
- `api/app/Providers/Filament/AdminPanelProvider.php` — Panel middleware configuration
- `api/app/Providers/AppServiceProvider.php` — Livewire route override
- `api/config/tenancy.php` — Asset helper setting
- `api/database/seeders/DemoWinerySeeder.php` — Domain record creation
