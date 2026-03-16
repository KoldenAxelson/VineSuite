# VineSuite

**The all-in-one winery management platform.** Production tracking, compliance, DTC sales, POS, inventory, and reporting — in a single integrated suite built to replace InnoVint + Commerce7 + spreadsheets.

For small-to-mid-size wineries (500–15,000 cases/year). Freemium with 4 tiers: Free / Basic ($99/mo) / Pro ($179/mo) / Max ($299/mo).

---

## Tech Stack

**API:** Laravel 12, PHP 8.4, PostgreSQL 16, Redis 7, stancl/tenancy v3.9 (schema-per-tenant)
**Portal:** Filament v3 on tenant subdomains
**Auth:** Sanctum tokens, 7 winery roles, ~55 granular permissions
**Testing:** Pest + PHPStan level 6 + Pint. ~680 tests. All against real PostgreSQL.
**CI/CD:** GitHub Actions — Pint → PHPStan → Pest on every push.

**Core architecture:** Immutable append-only event log with 33 event types across 4 source partitions. Every business operation writes an event; materialized tables are derived state. PostgreSQL trigger enforces immutability at the DB level. Enables offline sync, TTB compliance as a downstream query, and full lot traceability from grape to glass.

---

## Build Progress

| Phase | What | Status | Tests |
|-------|------|--------|-------|
| 1 | Foundation (tenancy, auth, event log, billing, Filament, CI/CD) | ✅ Complete | 141 |
| 2 | Production Core (lots, vessels, barrels, additions, blending, bottling) | ✅ Complete | 213 |
| 2b | Lab & Fermentation (analysis, thresholds, fermentation curves, sensory) | ✅ Complete | 124 |
| 2c | Inventory (case goods, dry goods, raw materials, equipment, POs, counts) | ✅ Complete | 265 |
| 2d | Cost Accounting & COGS | ⬜ Up Next | — |
| 3 | TTB Compliance (5120.17 auto-gen, DTC rules, lot traceability) | ⬜ | — |
| 4 | KMP Shared Core (SQLDelight, sync engine, Ktor client) | ⬜ | — |
| 5 | Cellar App (Android + iOS, offline-first) | ⬜ | — |
| 6 | POS App (Stripe Terminal, cash, splits, tips, offline) | ⬜ | — |
| 7 | Growth Features (wine club, ecommerce, reservations, CRM, vineyard, reporting) | ⬜ | — |
| 8 | Pro Features (AI, multi-brand, wholesale, public API, VineBook, migrations) | ⬜ | — |

**48/186 sub-tasks complete.** 25 task files spanning the full product vision. See `docs/execution/tasks/00-index.md` for the full breakdown.

---

## Local Development

```bash
git clone <repo-url> && cd VineSuite
cp api/.env.example api/.env
docker compose up -d

docker compose exec app composer install
docker compose exec app php artisan key:generate
make fresh
docker compose exec app php artisan filament:assets

echo "127.0.0.1 paso-robles-cellars.localhost" | sudo tee -a /etc/hosts
```

**Portal:** http://paso-robles-cellars.localhost:8000/portal
**Login:** `admin@vine.com` / `password`

```bash
make testsuite              # Full QA: Pest + Pint + PHPStan
make test                   # Pest only
make test G=inventory       # Run a test group
make test F=TransferTest    # Run a single test
make fresh                  # Flush Redis + reset DB + re-seed
```

## Demo Data

`make fresh` creates "Paso Robles Cellars" with realistic data across 4 completed phases: 38 lots across 4 vintages, 67 vessels, 65+ additions, 30 work orders, 2 blend trials, 4 bottling runs, 30+ lab analyses with threshold alerts, 10 fermentation rounds with daily entries, full inventory with stock levels and purchase orders, equipment with maintenance logs, and a completed physical count with variance reconciliation. Everything writes to a fully consistent immutable event log.

## Documentation

Start at `docs/README.md` — it's a routing table. Load only what you need, 3-5 files per session.

---

*Headquartered in Paso Robles, CA. Built for winemakers, by someone who drinks their wine.*
