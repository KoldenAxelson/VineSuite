# VineSuite — Docs Index

Multi-surface winery SaaS platform: Laravel 12 + PostgreSQL + Filament (TALL stack), with planned KMP native mobile apps. Multi-tenant (schema-per-tenant). Phases 1-6 complete (Foundation, Production Core, Lab & Fermentation, Inventory, Cost Accounting, TTB Compliance). Phase 7 (KMP Shared Core) is next.

**~870+ tests, 50+ event types, PHPStan level 6 (zero errors), Pint (zero style issues).**

---

## Doc Index

Load only what you need. 3-5 files per session max.

### Core
- `architecture.md` — Stack, API, event log, multi-tenancy, key decisions. Load on first session or cross-cutting changes.
- `CONVENTIONS.md` — Cross-cutting code patterns from Phases 1-6: services, API, Filament, data model, tests, seeders. Load on every coding session.
- `WORKFLOW.md` — Task lifecycle (LOAD->BUILD->TEST->VERIFY->RECORD->UPDATE), phase completion, context loading cheat sheet.

### References (load when touching that subsystem)
- `references/event-log.md` — EventLogger usage, event types, patterns.
- `references/multi-tenancy.md` — Schema-per-tenant patterns, tenant lifecycle.
- `references/auth-rbac.md` — Sanctum auth, Spatie roles/permissions.
- `references/postgresql-patterns.md` — DB patterns, indexing, JSONB usage.
- `references/event-source-partitioning.md` — Event source partitioning strategy.
- `references/test-groups.md` — Test group assignments and conventions.
- `references/widget-development.md` — Embeddable widget architecture.
- `references/migration-workbench.md` — Data migration tool for winery onboarding.

### Guides (how-tos)
- `guides/testing-and-logging.md` — Test tiers, logging patterns, structured log format.
- `guides/filament-tenancy.md` — Filament + multi-tenancy integration patterns.
- `guides/forge-deployment.md` — Deployment via Laravel Forge.
- `guides/stripe-setup.md` — Stripe Connect setup and configuration.

### Execution
- `execution/handoffs/{NN}-{module}-handoff.md` — **Start here when beginning a new phase.** Phase-specific onboarding.
- `execution/tasks/00-index.md` — Master task index. Load to see what's next.
- `execution/tasks/{NN}-{module}.md` — Task specs. Load the one you're working on.
- `execution/completed/{NN}-{module}.info.md` — Completion records with past decisions.
- `execution/phase-recaps/phase-{N}-{name}.md` — Phase summaries. Load all when starting a new phase.

### Business (not for coding tasks)
- `business/README.md` — Pricing, revenue, competitors, target customer, risks.
- `business/glossary.md` — Wine industry terms.
- `business/feature-inventory.md` — Module-by-module feature list with tier tags.
- `business/selling-points.md` — Marketing positioning.

### Other
- `diagrams/` — Mermaid visualizations (ERDs, data flows, architecture).
- `templates/` — Templates for handoffs, starter prompts, INFO files, phase recaps, reference docs, sub-tasks.
- `ideas/` — Feature ideas backlog. See `ideas/TRIAGE.md`.
- `refactors/` — Active refactor specs. Check before overlapping work.

### Naming Conventions
The project uses three numbering views of the same work — all valid, different lenses:
- **Build Progress table** (project README): Groups modules into high-level phases (1, 2, 2b, 2c, 2d, 3–8). Best for the big picture.
- **Task files** (`execution/tasks/{NN}-*.md`): Numbered 01–25 sequentially. The canonical spec for each module.
- **Phase recaps** (`execution/phase-recaps/phase-{N}-*.md`): Numbered sequentially by completed module (1–6 so far). The chronological record.

---

## Makefile Commands

| Command | What it does |
|---|---|
| `make up` / `down` / `restart` | Start, stop, restart Docker services |
| `make fresh` | Drop all, flush Redis, re-migrate, re-seed |
| `make test` | Run Pest test suite |
| `make test G=inventory` | Run only one test group (`foundation`, `production`, `lab`, `inventory`, `accounting`, `compliance`) |
| `make test F=Transfer` | Run tests matching a filter name |
| `make testsuite` | Full QA: Pest → Pint → PHPStan |
| `make quicktest F=Transfer` | Filtered Pest only, no full suite |
| `make lint` | Laravel Pint (code style) |
| `make analyse` | PHPStan level 6 (static analysis) |
| `make migrate` | Run migrations (central + tenant) |
| `make shell` | Bash shell in the API container |
| `make logs` / `logs-api` | Tail service logs |

---

## Caution: Heavy Files

These files are large. Don't load them speculatively — only when your task actually needs them.

- `business/feature-inventory.md` (800 lines) — executive summaries, feature audits, marketing
- `execution/completed/*.info.md` (1,356 lines total) — investigating past decisions, debugging inherited code
- `ideas/pricing-and-plan-tiers.md` (308 lines) — billing, plan gating, PlanFeatureService work

---

## Principles

- **The event log is the source of truth.** Never mutate historical events. Corrections are new events. Materialized state tables are caches.
- **Offline is first-class, not a fallback.** The cellar app must work without internet. Period.
- **Ship compliance correctly or don't ship it.** TTB reporting is why wineries pay. Test against real data.
- **Don't ship in July.** California harvest starts August. Production freeze every year.
- **Pricing is a marketing page, not a sales call.** Transparent pricing is a competitive advantage.

---

## Rules of Engagement

- Got an idea? Append to `ideas/`. Don't modify scope mid-phase.
- Finished a refactor? Delete its entry from `refactors/`.
- Made a key decision? Record it in the INFO file. Decisions are the most valuable artifact.
- Established a pattern? Update the relevant reference doc in `references/`.
- Completed a phase? Write a phase recap before starting the next one.
