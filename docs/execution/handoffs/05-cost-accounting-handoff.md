# Phase 5 Handoff — Cost Accounting & COGS

> Phases 1–4 complete. 680+ tests passing, PHPStan level 6 (zero errors), Pint (zero style issues).

## Read Before Coding

1. `docs/README.md` — doc routing table
2. `docs/CONVENTIONS.md` — code patterns (treat as law)
3. `docs/WORKFLOW.md` — dev lifecycle
4. `docs/execution/tasks/05-cost-accounting.md` — your task spec (the "Before starting" block has phase-specific pointers)

## What's Relevant From Previous Phases

**Phase 2 (Production Core):** Lot, WorkOrder, Addition, Transfer, BlendTrial, BottlingRun — all models that generate cost entries. WorkOrder has `hours` and `labor_cost` fields. BlendTrial has proportional component volumes (the math for cost rollthrough). BottlingRun links to CaseGoodsSku and records `bottles_filled`.

**Phase 4 (Inventory):** `DryGoodsItem.cost_per_unit` has packaging costs (bottle, cork, capsule, label, carton) needed for bottling COGS. `RawMaterial.cost_per_unit` has cellar supply costs for addition-based cost entries. `CaseGoodsSku.cost_per_bottle` exists but is empty — Phase 5 populates it. `InventoryService` is the sole stock mutation path with `lockForUpdate()`.

**Phases 1 & 3:** Skim the phase recaps for architecture decisions and patterns. Nothing Phase 5-specific beyond what CONVENTIONS.md covers.

## Carry-Over Debt

- **Auto-deduction from production not wired.** `InventoryService` methods (`receive`, `sell`, `adjust`) exist, but hooks into BottlingRun completion (deduct dry goods) and Addition creation (deduct raw materials) are not implemented. Sub-Task 6 (per-bottle COGS at bottling) is the natural place to wire this.
- **Deferred items (not blocking):** Token ability endpoint enforcement, Filament Livewire CRUD tests, dashboard overview widgets, low stock alert notifications, CSV import partial failure handling, `confirmMlDryness()` API endpoint.

## Phase-Specific Notes

- Cost entries are immutable (append-only, like the event log). Corrections are negative adjustment entries, never edits.
- Use `decimal` columns and `bcmath` for all money/cost calculations. Never float.
- New event types use `cost_` or `cogs_` prefix → auto-resolves to `event_source='accounting'`. See `references/event-source-partitioning.md`.
- New test files get `->group('accounting')`. Run with `make test G=accounting`.
- Filament resources go under a new **"Accounting"** navigation group.

## Rules

- **One sub-task at a time.** Complete it, write the INFO entry, run `make testsuite`, then stop and check in with the human before starting the next sub-task. Do not batch multiple sub-tasks.
- Follow sub-task order. They're sequenced for dependencies.
- Write the INFO file after every sub-task: `docs/execution/completed/05-cost-accounting.info.md`
- Don't break existing tests. Run `make testsuite`, not just new tests.
- New ideas go to `docs/ideas/`, not into scope.
- Tech stack is locked.

## Go

Read the files listed above. Then start Sub-Task 1 of `docs/execution/tasks/05-cost-accounting.md`.
