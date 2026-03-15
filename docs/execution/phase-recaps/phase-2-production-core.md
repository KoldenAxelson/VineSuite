# Phase 2 Recap — Production Core (Cellar Management)

> Duration: 2026-03-10 → 2026-03-15
> Task files: `02-production-core.md`
> INFO files: `02-production-core.info.md`

---

## What Was Delivered
- A complete cellar production management system covering the full lifecycle of wine: grape reception → fermentation → aging → blending → bottling, with every operation recorded in the immutable event log
- 8 Eloquent models with UUID primary keys, rich relationships, computed accessors, and scoped queries — Lot, Vessel, Barrel, WorkOrder, Addition, Transfer, BottlingRun, BlendTrial (plus pivot/component models)
- Full REST API with 17+ endpoints behind tenant-scoped authentication, all returning the standard API envelope format
- A Filament v3 management portal with 8 production resources (CRUD interfaces, custom actions, calendar view, event timeline), running on tenant subdomains with stancl/tenancy
- A realistic demo winery seeded with 38 lots across 4 vintages, 67 vessels (24 tanks + 43 barrels), 65+ additions, 18 transfers, 30 work orders, 2 blend trials, and 4 bottling runs — all with consistent event log entries

## Architecture Decisions Made

### Filament + stancl/tenancy Integration (Sub-Task 13)
Getting Filament 3 running on tenant subdomains required three non-obvious middleware/config fixes that took significant debugging. These are documented in detail in `guides/filament-tenancy.md` and must be understood by anyone touching the portal:

1. **`asset_helper_tenancy` must be `false`** in `config/tenancy.php` — otherwise FilesystemTenancyBootstrapper rewrites all `asset()` URLs to `/tenancy/assets/...` which 404s for Filament's pre-compiled CSS/JS
2. **Livewire needs tenant middleware** — `Livewire::setUpdateRoute()` in AppServiceProvider must include `InitializeTenancyByDomain` so login POSTs query the tenant database
3. **Tenancy middleware before session** — `InitializeTenancyByDomain` must come before `StartSession` in AdminPanelProvider's middleware stack, otherwise the session/auth resolves against the central database

### Immutable Operation Logs (Sub-Tasks 5, 6)
Additions and Transfers are append-only — the API and Filament resources support Create + View only, no Edit or Delete. This matches the event log's immutability guarantee and prevents retroactive changes to production records.

### Two-Phase Work Order Completion (Sub-Task 4)
Work orders follow a two-phase workflow: create (pending) → complete (with completion_notes, completed_by, completed_at). Both the API and Filament use this pattern via custom "Complete" actions with confirmation modals.

### UUID Pivot Tables (Sub-Task 14)
The `lot_vessel` pivot table uses `uuid('id')->primary()` — Laravel's `attach()` doesn't auto-generate UUIDs, so all code writing to this pivot must pass `'id' => (string) Str::uuid()`. This is a recurring gotcha.

### Volume Tracking via Pivot (Sub-Tasks 1, 2)
Wine volume in vessels is tracked through the `lot_vessel` pivot with `volume_gallons`, `filled_at`, `emptied_at`. "Current contents" queries filter by `emptied_at IS NULL`. Vessel's `current_volume` accessor sums active pivot records. This design supports multiple lots in one vessel (common for tanks during blending) and lot history across vessels.

## Deviations from Original Spec
- **No `Filament\Infolists\Components\BadgeEntry`** — the spec assumed this class exists, but Filament 3 Infolists use `TextEntry::make()->badge()` instead. Tables have `BadgeColumn` but Infolists do not have `BadgeEntry`.
- **38 lots instead of 40+** — the demo seeder creates 38 high-quality lots with realistic Paso Robles terroir references. Functional difference is negligible.
- **PressLog and FilterLog not seeded** — models and API endpoints exist, but the demo seeder doesn't populate them. Press/filter operations are represented as completed work orders.
- **`tenants:migrate --fresh` doesn't exist** — the Makefile `fresh` target was adjusted to rely on `migrate:fresh --seed` which triggers tenant creation via lifecycle events.

## Patterns Established

### EventLogger as Universal Write Path
Every production operation (addition, transfer, blend, bottling) writes to the event log via `app(EventLogger::class)->log()`. The seeder follows this same pattern. Future modules must maintain this — never insert into `events` directly. See `references/event-log.md`.

### Filament Resource Structure
Resources live in `app/Filament/Resources/` with auto-discovery via `discoverResources()`. Each resource has a `Pages/` subdirectory. Custom pages (like the calendar) extend `Filament\Resources\Pages\Page` with a `$view` property pointing to a Blade template. Immutable models (Addition, Transfer) use Create + View pages only.

### Modular Demo Seeders
`ProductionSeeder` is called from `DemoWinerySeeder` via `$this->call()`. Future phases should follow this pattern: create a `LabSeeder`, `InventorySeeder`, `CostSeeder`, etc. and wire them in. Each sub-seeder assumes users already exist and loads them via role queries.

### Tenant Middleware Ordering
In `AdminPanelProvider`, tenancy middleware (`InitializeTenancyByDomain`, `PreventAccessFromCentralDomains`) must appear before `StartSession`. This ordering is critical and non-obvious. See `guides/filament-tenancy.md`.

## Known Debt
1. **PressLog/FilterLog not demo-seeded** — impact: low — models and API work, just no demo data
2. **No dedicated ProductionSeeder test** — impact: low — exercised via DemoWinerySeeder test but no assertions on specific production data counts
3. **Calendar page is server-rendered Blade** — impact: medium — works but not interactive. A JavaScript calendar library (FullCalendar) would be better for production use. Current implementation is functional for demo purposes.
4. **No event replay verification** — impact: medium — materialized state (lots table volumes) is set directly in seeder, not derived from event replay. A future integrity check could verify event log consistency.
5. **Barrel operations API endpoints exist but Filament actions are basic** — impact: low — fill/top/rack/sample API endpoints work, but the Filament UI uses standard CRUD rather than specialized barrel operation flows

## Reference Docs Updated
- `references/event-log.md` — Updated with new production event types and seeder usage pattern
- `references/multi-tenancy.md` — Updated with Filament integration gotchas and middleware ordering
- `guides/filament-tenancy.md` — **Created** — Step-by-step guide for Filament + stancl/tenancy integration
- `diagrams/production-data-flow.mermaid` — **Created** — Visual diagram of wine production data flow

## Metrics
- Sub-tasks completed: 14/14
- Test count: 354 (all passing, up from 141 at end of Phase 1; includes post-audit additions)
- Assertions: ~1,466
- Files created: ~75 new files (models, migrations, factories, services, controllers, requests, resources, pages, seeders, tests, views)
- Tenant migrations: 9 new (lots, vessels, barrels, lot_vessel, work_orders, work_order_templates, additions, transfers, bottling_runs, blend_trials, blend_trial_components, bottling_components, press_logs, filter_logs)
- Filament resources: 8 (with 23 page classes + 1 Blade template)
- API endpoints: 17+ (full CRUD + specialized operations)
- PHPStan: level 6, zero errors (with 512M memory limit)
- Pint: zero style issues
- Demo data: 38 lots, 67 vessels, 65+ additions, 18 transfers, 30 work orders, 2 blend trials, 4 bottling runs
