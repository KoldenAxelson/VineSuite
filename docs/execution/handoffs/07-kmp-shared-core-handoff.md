# Task 7 Handoff — KMP Shared Core

> Phases 1–6 complete (Foundation, Production Core, Lab & Fermentation, Inventory, Cost Accounting, TTB Compliance). Filament v4 migration done. 870+ tests passing, PHPStan level 6 (zero errors), Pint (zero style issues).

## Read Before Coding

1. `docs/README.md` — doc routing table
2. `docs/CONVENTIONS.md` — code patterns (treat as law)
3. `docs/WORKFLOW.md` — dev lifecycle
4. `docs/execution/tasks/07-kmp-shared-core.md` — your task spec (the "Before starting" block at the top has phase-specific pointers)

## What's Relevant From Previous Phases

**Phase 1 (Foundation):** Sanctum token auth with `client_type|context` naming convention. Events table schema: `entity_type`, `entity_id`, `operation_type`, `payload` (JSONB), `performed_at`, `idempotency_key`. PostgreSQL trigger enforces immutability. The sync endpoint design is described in `docs/diagrams/event-flow.mermaid` and `docs/diagrams/sync-architecture.mermaid`.

**Phase 2 (Production Core):** The API endpoints the mobile apps will consume — lots CRUD, vessel management, barrel tracking, work orders, additions, transfers, blending, bottling. All operations write through `EventLogger::log()`. The KMP layer needs to mirror these operation types in its local outbox and know the payload shapes.

**Phase 2b (Lab & Fermentation):** Lab analysis and fermentation data entry APIs. Mobile cellar workers need to submit lab results and fermentation readings offline.

**Phases 2c-2d (Inventory, Cost Accounting):** Not directly consumed by the KMP layer. Inventory mutations go through `InventoryService` and cost entries through `CostAccumulationService` — both server-side only.

**Phase 3 (TTB Compliance):** Not consumed by KMP directly. TTB report generation is server-side only. However, the event types emitted by mobile apps (additions, transfers, bottling) feed into TTB calculations. The KMP outbox events must include the same payload fields the TTB calculators expect (e.g., `volume_bottled_gallons`, `waste_pct`, `variance` for transfers).

## Carry-Over Debt

1. **Part III receipts need `volume_gallons` in stock_received payload** — TTB compliance needs this field but inventory's stock_received events don't carry it yet. If the KMP layer creates stock_received events, include `volume_gallons` in the payload.
2. **New TTB event types not yet emittable** — sweetening_completed, fortification_completed, amelioration_completed, etc. These don't need mobile support yet but are noted for awareness.

## Phase-Specific Notes

- This phase is Kotlin, not PHP. It creates the `shared/` directory at the project root for the Kotlin Multiplatform module (the middleman between the Laravel API and the native apps). The Laravel API is untouched except potentially adding/modifying sync-related endpoints.
- The existing `docs/diagrams/sync-architecture.mermaid` shows the planned sync flow. Read it before designing the outbox/sync engine.
- Event payload shapes are documented implicitly in the test fixtures: `tests/Fixtures/ttb/scenario_*.json` files show real event payload structures.
- 37+ event operation types exist across 4 source partitions. The KMP layer only needs to handle the subset relevant to cellar operations (production, lab). See `config/event-sources.php` for the full mapping.

## Rules

- **One sub-task at a time.** Complete it, write the INFO entry, run `make testsuite`, then stop and check in with the human before starting the next sub-task. Do not batch multiple sub-tasks.
- Follow sub-task order. They're sequenced for dependencies.
- Write the INFO file after every sub-task: `docs/execution/completed/07-kmp-shared-core.info.md`
- Don't break existing tests. Run `make testsuite`, not just new tests.
- New ideas go to `docs/ideas/`, not into scope.
- Tech stack is locked.

## Go

Read the files listed above. Then start Sub-Task 1 of `docs/execution/tasks/07-kmp-shared-core.md`.
