# VineSuite — Master Task Index

> This document maps all task files, their phases, dependencies, and the build sequence.
> See `architecture.md` Key Architectural Decisions table and `to-be-deleted/architecture-original.md` Section 15 for the rationale behind this phasing.

---

## Build Sequence Overview

```
Phase 1: Foundation ──────────────────────────────────── COMPLETE
  └── 01-foundation.md (moved to completed/)

Phase 2: Production Module + Portal ──────────────────── Phases 2a-2c COMPLETE
  ├── 02-production-core.md (moved to completed/)
  ├── 03-lab-fermentation.md (moved to completed/)
  ├── 04-inventory.md (moved to completed/)
  └── 05-cost-accounting.md  ← UP NEXT

Phase 3: TTB Compliance ──────────────────────────────── (~2-3 weeks)
  └── 06-ttb-compliance.md

Phase 4: KMP Shared Core ─────────────────────────────── (~4-6 weeks)
  └── 07-kmp-shared-core.md

Phase 5: Cellar App ──────────────────────────────────── (~3-4 weeks)
  └── 08-cellar-app.md

  ╔══════════════════════════════════════════════════════╗
  ║  ★ SELL IT HERE — Starter tier at $99/month ★       ║
  ║                                                      ║
  ║  Portal + Cellar App + TTB reports = shippable       ║
  ║  Starter product. Get 5 paying customers before      ║
  ║  writing another line of feature code.               ║
  ╚══════════════════════════════════════════════════════╝

Phase 6: POS App ─────────────────────────────────────── (~3-4 weeks)
  └── 09-pos-app.md

Phase 7: Growth Tier Features ────────────────────────── (~8-12 weeks)
  ├── 10-wine-club.md
  ├── 11-ecommerce.md
  ├── 12-reservations-events.md
  ├── 13-crm-email.md
  ├── 14-widgets.md
  ├── 15-payments-advanced.md
  ├── 16-accounting-integrations.md
  ├── 17-vineyard.md
  ├── 18-notifications.md
  └── 19-reporting.md

Phase 8: Pro Tier + VineBook ─────────────────────────── (build when Growth revenue justifies)
  ├── 20-ai-features.md
  ├── 21-multi-brand.md
  ├── 22-wholesale.md
  ├── 23-public-api.md
  ├── 24-vinebook.md
  └── 25-migration-workbench.md
```

---

## Task File Index

| # | File | Phase | Est. Sub-Tasks | Description |
|---|------|-------|----------------|-------------|
| 01 | `completed/01-foundation.md` | 1 | 15 | COMPLETE — Docker, Laravel 12, multi-tenancy, auth/RBAC, event log, Stripe billing, Filament shell, CI/CD |
| 02 | `completed/02-production-core.md` | 2 | 14 | COMPLETE — Lots, vessels, barrels, work orders, additions, transfers, pressing, filtering, blending, bottling |
| 03 | `completed/03-lab-fermentation.md` | 2 | 7 | COMPLETE — Lab analysis entry/import, threshold alerts, fermentation tracking, fermentation curves |
| 04 | `completed/04-inventory.md` | 2 | 11 | COMPLETE — Case goods SKUs, multi-location stock, dry goods, raw materials, equipment, physical counts |
| 05 | `05-cost-accounting.md` | 2 | 8 | Per-lot cost ledger, labor costs, overhead allocation, blend/split cost rollthrough, COGS |
| 06 | `06-ttb-compliance.md` | 3 | 9 | TTB 5120.17 auto-generation, PDF export, verification tests, DTC compliance, lot traceability |
| 07 | `07-kmp-shared-core.md` | 4 | 8 | SQLDelight, event queue, sync engine, Ktor client, conflict resolution, JVM test suite |
| 08 | `08-cellar-app.md` | 5 | 13 | Android + iOS cellar app: work orders, additions, transfers, barrel scan, lab/ferm entry, offline test |
| 09 | `09-pos-app.md` | 6 | 15 | Android + iOS POS: product grid, cart, Stripe Terminal, cash, splits, tips, receipts, offline stress test |
| 10 | `10-wine-club.md` | 7 | 8 | Club tiers, member management, batch processing, customization window, failed payment recovery |
| 11 | `11-ecommerce.md` | 7 | 8 | Hosted store, product listings, cart/checkout, order management, shipping, allocations |
| 12 | `12-reservations-events.md` | 7 | 6 | Tasting experiences, availability, booking flow, event ticketing, calendar, POS check-in |
| 13 | `13-crm-email.md` | 7 | 6 | Customer profiles, segmentation, email campaigns, Mailchimp/Klaviyo integration, loyalty |
| 14 | `14-widgets.md` | 7 | 6 | Embeddable Web Components: store, reservations, club signup, member portal |
| 15 | `15-payments-advanced.md` | 7 | 5 | Payment processor abstraction, BYO processor, card-on-file, gift cards, reconciliation |
| 16 | `16-accounting-integrations.md` | 7 | 3 | QuickBooks Online + Xero two-way sync, COGS export |
| 17 | `17-vineyard.md` | 7 | 5 | Blocks, seasonal activities, sampling, spray logs, harvest → lot creation |
| 18 | `18-notifications.md` | 7/8 | 5 | In-app notifications, email/SMS, staff alerts, automation rules engine [PRO] |
| 19 | `19-reporting.md` | 2-8 | 7 | Sales, production, inventory, club, financial, compliance reports + export |
| 20 | `20-ai-features.md` | 8 | 6 | Weekly digest, demand forecasting, fermentation prediction, churn scoring, margin optimization |
| 21 | `21-multi-brand.md` | 8 | 3 | Multi-winery under one login, consolidated reporting, custom crush support |
| 22 | `22-wholesale.md` | 8 | 5 | Wholesale accounts, price lists, wholesale orders, AR tracking, distributor portal |
| 23 | `23-public-api.md` | 8 | 4 | Scoped Sanctum tokens, OpenAPI docs, webhook subscriptions, sandbox |
| 24 | `24-vinebook.md` | 8 | 6 | Astro directory site, TTB seed data, enrichment, subscriber islands, claim flow |
| 25 | `25-migration-workbench.md` | 8 | 10 | Source connectors, transformers, AI normalization, dry run, verification, cut-over |
| | | **TOTAL** | **~186** | |

---

## Estimated Sub-Task Counts by Phase

| Phase | Description | Task Files | Sub-Tasks | Est. Duration |
|-------|-------------|------------|-----------|---------------|
| 1 | Foundation | 1 | 15 | ~2 weeks |
| 2 | Production + Portal | 4 | 40 | ~6-8 weeks |
| 3 | TTB Compliance | 1 | 9 | ~2-3 weeks |
| 4 | KMP Shared Core | 1 | 8 | ~4-6 weeks |
| 5 | Cellar App | 1 | 13 | ~3-4 weeks |
| | **★ SELL IT HERE ★** | | **85** | **~17-23 weeks** |
| 6 | POS App | 1 | 15 | ~3-4 weeks |
| 7 | Growth Features | 10 | 52 | ~8-12 weeks |
| 8 | Pro + VineBook | 6 | 34 | As revenue justifies |
| | **TOTAL** | **25** | **~186** | |

---

## Cross-Module Dependency Map

```
01-foundation ─────────────────────────────────────────────────────────┐
  │ (event log, auth, tenancy, Filament — everything depends on this) │
  ├──→ 02-production-core                                              │
  │      ├──→ 03-lab-fermentation                                      │
  │      ├──→ 04-inventory ←──────── (bottling creates case goods)     │
  │      │      └──→ 05-cost-accounting ←── (materials cost, labor)    │
  │      └──→ 06-ttb-compliance ←──── (event log aggregation)          │
  │                                                                     │
  ├──→ 07-kmp-shared-core ←──── (consumes API from 01, 02, 03)        │
  │      ├──→ 08-cellar-app (UI on shared core)                        │
  │      └──→ 09-pos-app (UI on shared core + Stripe Terminal)         │
  │             └──→ needs 04-inventory (product catalog)               │
  │                                                                     │
  ├──→ 10-wine-club ←──── needs 11-ecommerce (order pipeline)         │
  │      │                  needs 15-payments (card-on-file)            │
  ├──→ 11-ecommerce ←──── needs 04-inventory, 06-ttb (DTC compliance) │
  ├──→ 12-reservations ←── needs 11-ecommerce (payments)              │
  ├──→ 13-crm-email ←──── needs 11, 10, 12 (customer touchpoints)    │
  ├──→ 14-widgets ←─────── needs 11, 12, 10 (APIs to embed)          │
  ├──→ 15-payments ←────── extends 01 Stripe billing                   │
  ├──→ 16-accounting ←──── needs 05, 11 (COGS, orders to sync)       │
  ├──→ 17-vineyard ←────── needs 02 (harvest creates lots)            │
  ├──→ 18-notifications ←─ needs all modules (triggers)               │
  ├──→ 19-reporting ←───── needs all modules (data sources)           │
  │                                                                     │
  ├──→ 20-ai-features ←─── needs 19-reporting, all data modules       │
  ├──→ 21-multi-brand ←─── extends 01 tenancy                         │
  ├──→ 22-wholesale ←───── needs 04, 11, 13                           │
  ├──→ 23-public-api ←──── needs all modules (exposes endpoints)      │
  ├──→ 24-vinebook ←────── needs 11, 12, 10 (widget APIs)            │
  └──→ 25-migration-workbench ←── needs 01-13 (target schema)         │
───────────────────────────────────────────────────────────────────────┘
```

---

## Key Constraints (Reference)

- **Tech stack is locked** — see `architecture.md` for full rationale. Do not suggest alternatives.
- **Event log is the source of truth** — every winery operation writes an event. Materialized tables are caches.
- **Build order is strict** — don't start Phase N+1 until Phase N delivers a milestone.
- **Don't ship in July** — California harvest starts August. Production freeze every year.
- **TTB compliance is safety-critical** — test against real winery data before launch.
- **Offline is first-class** — Cellar App and POS App must work fully without internet.
- **The KMP shared core is the hardest engineering** — dedicated focus, no distractions.

---

## How to Use These Task Files

1. A developer picks up a task file for their current phase
2. Reads the Goal and Dependencies sections
3. Works through Sub-Tasks top-to-bottom (they're sequenced)
4. Each sub-task is completable in 1-4 hours
5. Acceptance criteria define "done"
6. Gotchas section prevents common mistakes
7. When all sub-tasks in a file are complete, the module is done
8. Cross-reference the dependency map before starting a new module
