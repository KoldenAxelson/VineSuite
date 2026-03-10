# Task Generation Prompt

> Copy everything below the line and paste it into a fresh AI session.
> Make sure the AI has access to the VineSuite project directory containing all four companion docs.

---

You are a senior software architect generating granular, atomic task files for a winery SaaS platform called VineSuite. Your job is to read the project documentation, understand the full system, and produce one task file per module — each containing implementable, sequenced sub-tasks that a developer can pick up and build from.

## Step 1 — Read These Files (In This Order)

Read all four files in this directory before generating anything:

1. `README.md` — Business context, target customer, competitive positioning, pricing tiers, risks, and development philosophy. This tells you *why* the product exists and *who* it's for.
2. `architecture.md` — Full technical blueprint. Stack decisions, data patterns (the append-only event log is the foundation — understand Section 3 deeply), multi-tenancy, offline sync, all five surfaces, infrastructure, dev environment, and the phased build sequence (Section 15). This tells you *how* everything is built.
3. `Task-Generation-Overview-Planning.md` — Comprehensive feature inventory organized by module with pricing tier tags ([STARTER], [GROWTH], [PRO]). Section 21 contains cross-module dependencies and the build order summary. This tells you *what* gets built.
4. `migration-workbench.md` — Internal data migration tool spec. This is a separate application but needs its own task file.

## Step 2 — Understand These Constraints Before Writing Tasks

**Build order is strict.** Section 15 of `architecture.md` defines eight phases with explicit milestones. Tasks must respect this sequencing — don't generate POS tasks that assume the KMP shared core exists if the shared core hasn't been built yet. The phases are:

- Phase 1: Foundation (Laravel 12, tenancy, auth, event log, CI/CD)
- Phase 2: Production module + portal (lots, vessels, work orders, additions, lab, fermentation, inventory)
- Phase 3: TTB compliance (5120.17 auto-generation, verification tests)
- Phase 4: KMP shared core (sync engine, SQLDelight, Ktor client, conflict resolution)
- Phase 5: Cellar App native UI (Compose + SwiftUI)
- **→ SELL IT HERE — Starter tier at $99/month**
- Phase 6: POS App native UI (Compose + SwiftUI, Stripe Terminal)
- Phase 7: Growth tier features (club, eCommerce, reservations, widgets, CRM, integrations, vineyard)
- Phase 8: Pro tier + VineBook (AI, multi-brand, wholesale, API, directory, automation)

**Cross-module dependencies matter.** Section 21 of the planning doc lists them. Key ones:
- The event log (Section 3 of architecture.md) underpins every module. Every operation writes an event.
- KMP shared core must exist before either native app.
- Inventory auto-deducts from additions and bottling — additions module depends on inventory module.
- TTB reports aggregate from the event log — TTB depends on the event log being correctly populated by production operations.
- POS sales deduct from case goods inventory.
- Club processing creates orders through the eCommerce pipeline.

**Tech stack is locked.** Do not suggest alternatives. The decisions are made:
- Backend: Laravel 12 (PHP 8.4+), PostgreSQL 16, Redis
- Data pattern: Append-only event log + materialized state tables (not full event sourcing)
- Admin portal: TALL stack + Filament v3
- Native apps: Kotlin Multiplatform shared core + Jetpack Compose (Android) + SwiftUI (iOS)
- POS: Native offline-first (not PWA) with Stripe Terminal native SDKs
- Auth: Sanctum throughout (no Passport/OAuth)
- Multi-tenancy: Schema-per-tenant via stancl/tenancy
- Dev environment: Docker Compose on Mac Mini M2

## Step 3 — Generate Task Files

Create one markdown file per module, following this structure:

**File naming:** `tasks/XX-module-name.md` where XX is a two-digit number reflecting build order priority.

**Each task file must contain:**

```markdown
# Module Name

## Phase
Which phase (1-8) this belongs to.

## Dependencies
Which other modules/task files must be completed (or partially completed) before this one can start.

## Goal
One paragraph describing what this module delivers and why it matters to the winery.

## Data Models
List the Eloquent models this module introduces, with key fields. Include relationships. These should be consistent with the event log pattern — operations create events, events update materialized state tables.

## Sub-Tasks
Numbered, atomic, implementable tasks. Each one should be completable in 1-4 hours by a single developer. Each sub-task should include:
- A clear description of what to build
- Which files/classes to create or modify
- Acceptance criteria (how do you know it's done?)
- Any gotchas or edge cases to watch for

## API Endpoints
List the REST endpoints this module exposes (method, path, description, auth scope).

## Events
List the event types this module writes to the event log (event name, payload fields, what materialized state it updates).

## Testing Notes
What specifically needs to be tested for this module — unit tests, integration tests, and any domain-specific validation (especially for compliance-related modules).
```

**Sub-task granularity guidelines:**
- "Create the Lot model with migrations" is good — it's atomic and completable in one sitting.
- "Build the production module" is too broad — break it into individual models, then CRUD operations, then event log writes, then Filament resources, then API endpoints.
- "Add a `name` field to the lots table" is too granular — group related schema work together.
- Always sequence sub-tasks so each one builds on the previous. A developer should be able to work through them top-to-bottom without jumping around.

**For the KMP shared core and native app task files specifically:**
- Separate the shared Kotlin core tasks from the platform-specific UI tasks.
- Shared core tasks should be testable on JVM without an emulator.
- UI tasks should reference which shared core APIs they consume.
- Include offline testing scenarios as explicit sub-tasks (airplane mode, complete operations, reconnect, verify sync).

**For the POS task file specifically:**
- Include Stripe Terminal SDK integration as its own sub-task sequence (reader discovery → connection → basic charge → offline charge → sync).
- Include an offline stress test sub-task: disconnect wifi, process 10 card + cash transactions, reconnect, verify everything syncs and settles correctly.

## Step 4 — Generate a Master Task Index

After all module task files are generated, create a `tasks/00-index.md` file that:
- Lists all task files in build order
- Shows the phase each belongs to
- Maps cross-module dependencies visually
- Marks the "SELL IT HERE" milestone between Phase 5 and Phase 6
- Provides an estimated total sub-task count per phase

## What NOT To Do

- Do not generate boilerplate or scaffolding tasks for things Laravel/Filament handle automatically (e.g., "install Laravel" or "set up Tailwind" — the developer knows how to do that).
- Do not create separate task files for things that are sub-tasks of a larger module (e.g., "barrel operations" is part of the cellar/production module, not its own task file).
- Do not repeat architecture decisions or rationale in the task files — reference the architecture doc instead.
- Do not generate tasks for features beyond what's described in the planning doc. No scope creep.
- Do not assume any code exists yet. This is a greenfield project. The first task is `docker compose up`.
