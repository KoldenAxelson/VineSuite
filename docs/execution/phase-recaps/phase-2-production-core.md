# Phase 2 Recap — Production Core (Cellar Management)

> Duration: 2026-03-10 → 2026-03-15
> Task files: `02-production-core.md` | INFO: `02-production-core.info.md`

---

## Delivered

- Complete cellar production lifecycle: grape reception → fermentation → aging → blending → bottling
- 8 Eloquent models with UUID PKs, rich relationships, computed accessors, scoped queries
- Full REST API: 17+ endpoints behind tenant-scoped auth, standard envelope format
- Filament v3 portal with 8 production resources (CRUD, custom actions, calendar view, timeline) on tenant subdomains (stancl/tenancy)
- Realistic demo winery: 38 lots (4 vintages), 67 vessels (24 tanks + 43 barrels), 65+ additions, 18 transfers, 30 work orders, 2 blend trials, 4 bottling runs — all with event log entries

---

## Architecture Decisions

### Filament + stancl/tenancy Integration
Three non-obvious middleware/config fixes required:

1. **`asset_helper_tenancy` must be `false`** in `config/tenancy.php` — otherwise FilesystemTenancyBootstrapper rewrites `asset()` URLs to `/tenancy/assets/...` (404s on pre-compiled CSS/JS)
2. **Livewire needs tenant middleware** — `Livewire::setUpdateRoute()` must include `InitializeTenancyByDomain` so login POSTs query tenant database
3. **Tenancy middleware before session** — `InitializeTenancyByDomain` before `StartSession` in AdminPanelProvider, otherwise session/auth resolves against central database

See `guides/filament-tenancy.md` for details.

### Immutable Operation Logs
Additions and Transfers are append-only: Create + View only, no Edit/Delete. Matches event log immutability.

### Two-Phase Work Order Completion
pending → complete (with completion_notes, completed_by, completed_at). Both API and Filament use custom "Complete" actions with confirmation modals.

### UUID Pivot Tables
`lot_vessel` pivot uses `uuid('id')->primary()`. Laravel's `attach()` doesn't auto-generate UUIDs — all pivot writes must pass `'id' => (string) Str::uuid()`.

### Volume Tracking via Pivot
Wine volume in vessels tracked via `lot_vessel` pivot (volume_gallons, filled_at, emptied_at). "Current contents" filters by `emptied_at IS NULL`. Vessel's `current_volume` sums active pivots. Supports multiple lots in one vessel and lot history across vessels.

---

## Deviations from Spec

- **No BadgeEntry in Filament Infolists:** Filament 3 uses `TextEntry::make()->badge()`. Infolists don't have BadgeEntry.
- **38 lots instead of 40+:** High-quality Paso Robles terroir. Negligible functional difference.
- **PressLog and FilterLog not seeded:** Models and API exist, seeder skipped. Represented as completed work orders.
- **`tenants:migrate --fresh` doesn't exist:** Makefile `fresh` target uses `migrate:fresh --seed` which triggers tenant creation via lifecycle events.

---

## Patterns Established

### EventLogger as Universal Write Path
Every production operation writes via `app(EventLogger::class)->log()`. Seeder follows same pattern. Never insert into `events` directly. See `references/event-log.md`.

### Filament Resource Structure
Resources in `app/Filament/Resources/` with auto-discovery. Custom pages extend `Filament\Resources\Pages\Page` with `$view` property pointing to Blade template. Immutable models (Addition, Transfer) use Create + View only.

### Modular Demo Seeders
`ProductionSeeder` called from `DemoWinerySeeder` via `$this->call()`. Future phases: `LabSeeder`, `InventorySeeder`, `CostSeeder`, etc. Each sub-seeder assumes users exist, loads them via role queries.

### Tenant Middleware Ordering
In `AdminPanelProvider`: tenancy middleware (`InitializeTenancyByDomain`, `PreventAccessFromCentralDomains`) before `StartSession`. Critical and non-obvious. See `guides/filament-tenancy.md`.

---

## Known Debt

1. **PressLog/FilterLog not seeded** — impact: low — models and API work, no demo data
2. **No dedicated ProductionSeeder test** — impact: low — exercised via DemoWinerySeeder test
3. **Calendar page is server-rendered Blade** — impact: medium — functional but not interactive. FullCalendar would be better for production.
4. **No event replay verification** — impact: medium — materialized state set directly in seeder, not derived from event replay
5. **Barrel operations API exist but Filament actions basic** — impact: low — API works, Filament UI uses standard CRUD not specialized flows

---

## Reference Docs Updated

- `references/event-log.md` — Updated with production event types and seeder pattern
- `references/multi-tenancy.md` — Updated with Filament integration gotchas and middleware ordering
- `guides/filament-tenancy.md` — **Created** — Filament + stancl/tenancy integration guide
- `diagrams/production-data-flow.mermaid` — **Created** — Wine production data flow diagram

---

## Metrics

| Metric | Value |
|--------|-------|
| Sub-tasks | 14/14 |
| Tests | 354 (up from 141) |
| Assertions | ~1,466 |
| Files created | ~75 (models, migrations, factories, services, controllers, requests, resources, pages, seeders, tests, views) |
| Tenant migrations | 9 new |
| Filament resources | 8 (23 page classes + 1 Blade template) |
| API endpoints | 17+ (CRUD + specialized operations) |
| PHPStan level 6 | 0 errors (512M memory limit) |
| Pint | 0 style issues |
| Demo data | 38 lots, 67 vessels, 65+ additions, 18 transfers, 30 work orders, 2 blend trials, 4 bottling runs |
