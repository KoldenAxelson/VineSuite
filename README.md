# VineSuite

**The all-in-one winery management platform.** Production tracking, DTC sales, POS, compliance, and reporting — in a single integrated suite.

Built for small-to-mid-size wineries (500–15,000 cases/year) that are tired of juggling InnoVint + Commerce7 + spreadsheets.

---

## What's Here

```
api/              Laravel 12 API — the platform brain
docs/             Architecture, specs, execution records, guides
  ├── architecture.md
  ├── execution/   Task specs + completion records + phase recaps
  ├── references/  Quick-load context docs per subsystem
  ├── guides/      Operational how-tos
  └── diagrams/    Mermaid diagrams (ERDs, data flows)
```

Future directories (not yet built): `shared/` (KMP), `cellar-app/`, `pos-app/`, `vinebook/`, `widgets/`

## Tech Stack

**API:** Laravel 12, PHP 8.4, PostgreSQL 16, Redis 7, stancl/tenancy v3.9 (schema-per-tenant)

**Portal:** Filament v3 (admin panel on tenant subdomains)

**Auth:** Sanctum tokens scoped by client type, 7 winery roles, ~55 granular permissions

**Data pattern:** Immutable append-only event log. Every winery operation writes an event; materialized tables are derived state. PostgreSQL trigger enforces immutability at DB level.

**Testing:** Pest + PHPStan (level 6) + Pint. 478 tests across 26 test files. All against real PostgreSQL (no SQLite).

**CI/CD:** GitHub Actions — Pint → PHPStan → Pest on every push.

## Local Development

```bash
# Clone and start services
git clone <repo-url> && cd VineSuite
cp api/.env.example api/.env
docker compose up -d

# Install dependencies and set up database
docker compose exec app composer install
docker compose exec app php artisan key:generate
make fresh                  # Flushes Redis sessions + migrate:fresh --seed
docker compose exec app php artisan filament:assets

# Add local subdomain to /etc/hosts
echo "127.0.0.1 paso-robles-cellars.localhost" | sudo tee -a /etc/hosts
```

**Portal:** http://paso-robles-cellars.localhost:8000/portal
**Login:** `admin@vine.com` / `password`

**Run tests:**
```bash
make testsuite    # Pest + Pint + PHPStan (full suite)
make test         # Pest only
make test F=LabAnalysisTest  # Single test file
make analyse      # PHPStan only
make fresh        # Flush Redis + reset DB + re-seed
```

## Build Status

| Phase | Status | Sub-tasks | Tests |
|---|---|---|---|
| 1. Foundation | Complete | 15/15 | 141 |
| 2. Production Core | Complete | 14/14 | 213 |
| 3. Lab & Fermentation | Complete | 7/7 | 124 |
| 4. Inventory | Not started | 0/11 | — |
| 5. Cost Accounting | Not started | 0/8 | — |

## Demo Data

Running `make fresh` creates a demo winery ("Paso Robles Cellars") with realistic production data spanning grape reception through bottling, plus full lab and fermentation tracking:

**Production:** 38 lots across 4 vintages, 67 vessels (24 tanks + 43 barrels), 65+ chemical additions, 18 transfers, 30 work orders, 2 blend trials, 4 bottling runs.

**Lab & Fermentation:** 30+ lab analyses across 6 test types (Brix, pH, TA, VA, free SO2, malic acid), 17 industry-standard alert thresholds including VA at the 27 CFR 4.21 legal limit, 9 fermentation rounds (7 primary + 2 ML) with daily Brix/temperature entries, and 10 sensory tasting notes with 5-point and 100-point scales.

Everything writes to a fully consistent immutable event log. Enough to demo to a winemaker.

## Documentation

Start with `docs/README.md` for the business context, then `docs/architecture.md` for the technical blueprint.

For development workflow (how tasks move from spec to shipped code): `docs/WORKFLOW.md`

Key reference docs:

- `docs/references/event-log.md` — How the event log works, all 17 operation types, querying patterns
- `docs/references/multi-tenancy.md` — Tenant lifecycle, schema isolation, Filament integration gotchas
- `docs/references/auth-rbac.md` — Token abilities, roles, rate limiting
- `docs/guides/filament-tenancy.md` — Step-by-step guide for Filament + stancl/tenancy (hard-won knowledge)
- `docs/guides/testing-and-logging.md` — Test tiers, logging standards, PHP/Laravel/PostgreSQL gotchas

Phase recaps (compressed context for AI handoff): `docs/execution/phase-recaps/`

## Architecture Highlights

**Schema-per-tenant:** Each winery gets its own PostgreSQL schema. No `WHERE winery_id = ?` anywhere. Clean isolation, simple queries, works to ~500 tenants.

**Event log as source of truth:** Every cellar operation (addition, transfer, blend, bottling, lab analysis, fermentation entry, sensory note) writes an immutable event with self-contained payloads (human-readable names alongside foreign keys). TTB compliance reporting becomes a downstream aggregation query, not manual data entry. The event log also enables offline sync from mobile apps — idempotency keys prevent duplicate writes on retry.

**Filament portal on tenant subdomains:** The management portal runs Filament v3 on per-winery subdomains (e.g., `paso-robles-cellars.vinesuite.com`). Navigation groups: Production, Lab, Settings. Custom Livewire widgets use inline Alpine.js with the `@assets` directive for CDN scripts (Chart.js). Required three non-obvious middleware fixes to integrate with stancl/tenancy — documented in `docs/guides/filament-tenancy.md`.

---

*Headquartered in Paso Robles, CA. Built for winemakers, by someone who drinks their wine.*
