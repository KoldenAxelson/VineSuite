# VineSuite — Docs Index

Multi-surface winery SaaS platform: Laravel 12 + PostgreSQL + Filament (TALL stack), with planned KMP native mobile apps. Multi-tenant (schema-per-tenant). Phases 1-4 complete (Foundation, Production Core, Lab & Fermentation, Inventory). Phase 5 (Cost Accounting & COGS) is next.

**~680+ tests, 33 event types, PHPStan level 6 (zero errors), Pint (zero style issues).**

---

## Doc Index

Load only what you need. 3-5 files per session max.

### Core
- `architecture.md` — Stack, API, event log, multi-tenancy, key decisions. Load on first session or when making cross-cutting changes.
- `WORKFLOW.md` — Task lifecycle (LOAD→BUILD→TEST→VERIFY→RECORD→UPDATE), phase completion process, context loading cheat sheet. Load when starting a new phase.

### References (load when touching that subsystem)
- `references/event-log.md` — EventLogger usage, event types, patterns. Load when writing any service that creates events.
- `references/multi-tenancy.md` — Schema-per-tenant patterns, tenant lifecycle. Load when touching tenant isolation or migrations.
- `references/auth-rbac.md` — Sanctum auth, Spatie roles/permissions. Load when touching auth or access control.
- `references/postgresql-patterns.md` — DB patterns, indexing, JSONB usage. Load when writing migrations or complex queries.
- `references/event-source-partitioning.md` — Event source partitioning strategy. Load when working with event source categorization.
- `references/test-groups.md` — Test group assignments and conventions. Load when creating test files.
- `references/widget-development.md` — Embeddable widget architecture. Load when working on widgets.
- `references/migration-workbench.md` — Data migration tool for winery onboarding. Load when working on import/migration features.

### Guides (operational how-tos)
- `guides/testing-and-logging.md` — Test tiers, logging patterns, structured log format. Load when writing or debugging tests.
- `guides/filament-tenancy.md` — Filament + multi-tenancy integration patterns. Load when building Filament resources.
- `guides/forge-deployment.md` — Deployment via Laravel Forge. Load when deploying.
- `guides/stripe-setup.md` — Stripe Connect setup and configuration. Load when working on payments.

### Execution (task tracking)
- `execution/tasks/00-index.md` — Master task index for all phases. Load to see what's next.
- `execution/tasks/{NN}-{module}.md` — Individual task specs. Load the one you're working on.
- `execution/completed/{NN}-{module}.info.md` — Completion records. Load for detail on past decisions.
- `execution/phase-recaps/phase-{N}-{name}.md` — Phase summaries. Load all completed recaps when starting a new phase.

### Business (not needed for coding tasks)
- `business/README.md` — Pricing, revenue model, competitive landscape, target customer, key risks.
- `business/glossary.md` — Wine industry terms. Load when encountering unfamiliar terminology.
- `business/feature-inventory.md` — Comprehensive module-by-module feature list with tier tags.
- `business/selling-points.md` — Marketing positioning and selling points.

### Other
- `diagrams/` — Mermaid/JSX system visualizations (ERDs, data flows, architecture). Browse as needed.
- `templates/` — Templates for INFO files, phase recaps, reference docs, sub-tasks.
- `ideas/` — Feature ideas backlog. See `ideas/TRIAGE.md` for the triage process.
- `refactors/` — Active refactor specs. Check before starting work that might overlap.
- `legacy/` — Phase handoff docs. `PHASE_5_HANDOFF.md` is the active handoff for next phase.

---

## Principles

- **The event log is the source of truth.** Never mutate historical events. Corrections are new events. Materialized state tables are caches.
- **Offline is first-class, not a fallback.** The cellar app must work without internet. Period.
- **Ship compliance correctly or don't ship it.** TTB reporting is why wineries pay. Test against real data.
- **Don't ship in July.** California harvest starts August. Production freeze every year.
- **Pricing is a marketing page, not a sales call.** Transparent pricing is a competitive advantage.

---

## Rules of Engagement

- **Got an idea?** Append to `ideas/`. Don't modify scope mid-phase.
- **Finished a refactor?** Delete its entry from `refactors/`.
- **Made a key decision?** Record it in the INFO file. Decisions are the most valuable artifact.
- **Established a pattern?** Update the relevant reference doc in `references/`.
- **Completed a phase?** Write a phase recap before starting the next one.
