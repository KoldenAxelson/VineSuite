# VineSuite API

Laravel 12 API powering the VineSuite winery management platform.

## Quick Start

```bash
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan filament:assets
```

## Services

| Service | Port | Purpose |
|---|---|---|
| app | 8000 | Laravel API + Filament portal |
| postgres | 5432 | PostgreSQL 16 (multi-tenant schemas) |
| redis | 6379 | Cache, queues, sessions |

## Commands

```bash
make testsuite       # Full suite: Pest + Pint + PHPStan
make test            # Pest only
make analyse         # PHPStan (level 6, 512M memory)
make fresh           # migrate:fresh --seed (rebuilds everything)
make pint            # Code style fix
```

## Portal Access

After seeding, the demo winery portal is available at:

**URL:** http://paso-robles-cellars.localhost:8000/portal
**Login:** `admin@vine.com` / `password`

Requires `/etc/hosts` entry: `127.0.0.1 paso-robles-cellars.localhost`

## Project Structure

```
app/
├── Filament/Resources/    8 production resources (Lots, Vessels, Barrels, etc.)
├── Http/Controllers/      API controllers (v1)
├── Models/                Eloquent models (UUID PKs, tenant-scoped)
├── Services/              Business logic (EventLogger, LotService, etc.)
└── Providers/             AppServiceProvider, AdminPanelProvider, TenancyServiceProvider

database/
├── migrations/central/    Tenants, domains, billing
├── migrations/tenant/     Per-winery schema (lots, vessels, events, etc.)
├── seeders/               DemoWinerySeeder → ProductionSeeder
└── factories/             Factories for all production models

tests/
└── Feature/               352 tests, all against real PostgreSQL
```

## Documentation

See `../docs/` for architecture, task specs, completion records, and reference docs.
