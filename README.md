# VineSuite

**The all-in-one winery management platform.** Production tracking, compliance, DTC sales, POS, inventory, and reporting — in a single integrated suite built to replace InnoVint + Commerce7 + spreadsheets.

For small-to-mid-size wineries (500–15,000 cases/year). Freemium with 4 tiers: Free / Basic ($99/mo) / Pro ($179/mo) / Max ($299/mo).

---

## Tech Stack

**API:** Laravel 12, PHP 8.4, PostgreSQL 16, Redis 7, stancl/tenancy v3.9 (schema-per-tenant)
**Portal:** Filament v4 on tenant subdomains
**Auth:** Sanctum tokens, 7 winery roles, ~55 granular permissions
**Testing:** Pest + PHPStan level 6 + Pint. ~841 PHP tests. All against real PostgreSQL.
**Mobile:** Kotlin Multiplatform shared core (`shared/`). SQLDelight, Ktor, kotlinx-serialization. 116 JVM tests.
**CI/CD:** GitHub Actions — Pint → PHPStan → Pest on every push.

**Core architecture:** Immutable append-only event log with 50+ event types across 5 source partitions (production, lab, inventory, accounting, compliance). Every business operation writes an event; materialized tables are derived state. PostgreSQL trigger enforces immutability at the DB level. KMP shared core enables offline-first mobile sync via event outbox + server delta pull. Full lot traceability from grape to glass.

---

## Build Progress

| Phase | What | Status | Tests |
|-------|------|--------|-------|
| 1 | Foundation (tenancy, auth, event log, billing, Filament, CI/CD) | ✅ Complete | 141 |
| 2 | Production Core (lots, vessels, barrels, additions, blending, bottling) | ✅ Complete | 213 |
| 2b | Lab & Fermentation (analysis, thresholds, fermentation curves, sensory) | ✅ Complete | 124 |
| 2c | Inventory (case goods, dry goods, raw materials, equipment, POs, counts) | ✅ Complete | 265 |
| 2d | Cost Accounting & COGS (lot costs, labor, overhead, blends, COGS at bottling) | ✅ Complete | ~36 |
| 3 | TTB Compliance (5120.17 auto-gen, PDF export, DTC rules, lot traceability, certifications) | ✅ Complete | ~62 |
| 4 | KMP Shared Core (SQLDelight, sync engine, Ktor client, conflict resolution) | ✅ Complete | 116 (JVM) |
| 5 | Cellar App (Android + iOS, offline-first) | ⬜ Up Next | — |
| 6 | POS App (Stripe Terminal, cash, splits, tips, offline) | ⬜ | — |
| 7 | Growth Features (wine club, ecommerce, reservations, CRM, vineyard, reporting) | ⬜ | — |
| 8 | Pro Features (AI, multi-brand, wholesale, public API, VineBook, migrations) | ⬜ | — |

**73/186 sub-tasks complete.** 25 task files spanning the full product vision. See `docs/execution/tasks/00-index.md` for the full breakdown.

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

# KMP Shared Core (requires Java 17+)
make shared-test            # Run KMP JVM tests
make shared-test F=Sync     # Filter by test name
make shared-check           # Full QA: build + test + coverage
make shared-test-coverage   # Generate Kover HTML coverage report
```

## Demo Data

`make fresh` creates "Paso Robles Cellars" with realistic data across 6 completed phases: 38 lots across 4 vintages, 67 vessels, 65+ additions, 30 work orders, 2 blend trials, 4 bottling runs, 30+ lab analyses with threshold alerts, 10 fermentation rounds with daily entries, full inventory with stock levels and purchase orders, equipment with maintenance logs, a completed physical count with variance reconciliation, full cost accounting (150+ cost entries, labor/overhead rates, COGS summaries with margin reporting), 3 TTB reports (filed/reviewed/draft) with full Section A/B line items, 5 regulatory licenses (TTB permit, state licenses, COLAs), and DTC shipping rules for all 50 states + DC. Everything writes to a fully consistent immutable event log.

## Documentation

Start at `docs/README.md` — it's a routing table. Load only what you need, 3-5 files per session.

---

*Headquartered in Paso Robles, CA. Built for winemakers, by someone who drinks their wine.*
