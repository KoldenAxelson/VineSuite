# VineSuite — Docs Index

Multi-surface winery SaaS platform: Laravel 12 + PostgreSQL + Filament (TALL stack), with planned KMP native mobile apps. Multi-tenant (schema-per-tenant). Phases 1-4 complete (Foundation, Production Core, Lab & Fermentation, Inventory). Phase 5 (Cost Accounting & COGS) is next.

**~680+ tests, 33 event types, PHPStan level 6 (zero errors), Pint (zero style issues).**

---

## Doc Index

Load only what you need. 3-5 files per session max.

### Core
- `architecture.md` — Stack, API, event log, multi-tenancy, key decisions. Load on first session or cross-cutting changes.
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
- `diagrams/` — Mermaid/JSX visualizations (ERDs, data flows, architecture).
- `templates/` — Templates for INFO files, phase recaps, reference docs, sub-tasks.
- `ideas/` — Feature ideas backlog. See `ideas/TRIAGE.md`.
- `refactors/` — Active refactor specs. Check before overlapping work.
- `legacy/` — `PHASE_5_HANDOFF.md` is the active handoff for next phase.

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
