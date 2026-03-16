# VineSuite — Phase 5 Handoff Prompt

> Copy everything below the line into your first AI session to begin work on Phase 5: Cost Accounting & COGS.
> Phase 1: Foundation is complete (15/15 sub-tasks). Phase 2: Production Core is complete (14/14 sub-tasks). Phase 3: Lab & Fermentation is complete (7/7 sub-tasks). Phase 4: Inventory Management is complete (12/12 sub-tasks). Test suite: ~680+ tests passing.

---

## Who You Are

You are continuing development on VineSuite, a winery SaaS platform. Phases 1–4 are complete — foundation, production, lab/fermentation, and inventory modules are built, tested, and passing CI. Your job is to execute Phase 5: Cost Accounting & COGS, which tracks the true cost of producing every bottle of wine from grape to glass.

You do not need to plan. The planning is done. You need to build, test, and record.

## Before You Write Any Code

Read these files in this order. Do not skip any of them.

1. `docs/WORKFLOW.md` — The development lifecycle: LOAD → BUILD → TEST → VERIFY → RECORD → UPDATE. This is your operating manual. Follow it exactly.

2. `docs/execution/phase-recaps/phase-4-inventory.md` — Compressed context for everything built in Phase 4. Read this instead of the full INFO file. Covers architecture decisions (InventoryService locking, signed quantities, event source partitioning), patterns established (bidirectional RelationManagers, filtered HasMany), and known debt.

3. `docs/execution/phase-recaps/phase-3-lab-fermentation.md` — Phase 3 context. Relevant because lab analyses and fermentation operations feed into cost tracking (raw material consumption).

4. `docs/execution/phase-recaps/phase-2-production-core.md` — Phase 2 context. **Critical for Phase 5** — Lot, WorkOrder, BottlingRun, BlendTrial, Addition, Transfer are all models that generate cost entries. The blend proportional math and lot split patterns are directly relevant.

5. `docs/execution/phase-recaps/phase-1-foundation.md` — Phase 1 context. Skim for architecture decisions and patterns — most are already applied in Phase 2–4 code.

6. `docs/execution/tasks/05-cost-accounting.md` — **Your task spec.** 8 sub-tasks: lot cost entry model, labor cost tracking, overhead allocation, cost rollthrough for blends and splits, per-bottle COGS at bottling, COGS reports, demo data. Work through them top-to-bottom.

7. `docs/guides/testing-and-logging.md` — Testing tiers and logging standards. Cost calculations are Tier 1 — financial math must be tested exhaustively.

**Load when relevant (not upfront):**
- `docs/references/event-log.md` — How EventLogger works. Includes all Phase 2–4 event types. Load for any sub-task that writes events. Note the event source partitioning section — cost events should use `cost_` or `cogs_` prefix to auto-resolve to `event_source='accounting'`.
- `docs/references/event-source-partitioning.md` — The `event_source` column convention. `cost_` and `cogs_` prefixes already mapped to `'accounting'` source in `EventLogger::resolveSource()`.
- `docs/references/test-groups.md` — Test group convention. **Add `->group('accounting')` to all new Phase 5 test files.**
- `docs/references/multi-tenancy.md` — Tenant lifecycle, domain records, Filament integration gotchas.
- `docs/references/auth-rbac.md` — Auth, roles, rate limiting. Phase 5 endpoints need `accountant+` role gates.
- `docs/guides/filament-tenancy.md` — The 3 critical fixes for Filament + stancl/tenancy. Load before creating any Filament resources.
- `docs/architecture.md` — Full architecture doc. Section 8 (Cost Accounting) is most relevant for Phase 5.
- `docs/diagrams/inventory-erd.mermaid` — Phase 4 ERD. Shows DryGoodsItem and RawMaterial cost fields that feed into COGS.

## What Already Exists

### Phase 1 Foundation
- **Docker environment** — `docker compose up -d` starts all services. `make fresh` flushes Redis + resets DB + re-seeds.
- **Laravel 12 API** — PHP 8.4, PostgreSQL 16, Redis, stancl/tenancy v3.9 (schema-per-tenant)
- **Authentication** — Sanctum tokens scoped per client type, 7 roles with ~55 permissions
- **Event Log** — `EventLogger::log()`, immutable `events` table with PostgreSQL trigger, `event_source` auto-resolution
- **Activity Logging** — `LogsActivity` trait, immutable `activity_logs` table
- **Billing** — Stripe Cashier on Tenant model, Free/Basic/Pro/Max tiers
- **Filament Portal** — On tenant subdomains, navigation groups: Lab, Production, Inventory, Settings
- **API Envelope** — `{ "data": ..., "meta": {}, "errors": [] }` on all responses

### Phase 2 Production Core
- **8 Eloquent models** — Lot, Vessel, Barrel, WorkOrder, Addition, Transfer, BottlingRun, BlendTrial (plus pivot/component models)
- **17+ REST endpoints** — Full CRUD + specialized operations (complete, bulk create, calendar)
- **8 Filament resources** — CRUD interfaces with custom actions, calendar view, event timeline
- **Demo winery** — 38 lots across 4 vintages, 67 vessels, 65+ additions, 18 transfers, 30 work orders, 2 blend trials, 4 bottling runs
- **Key for Phase 5:** WorkOrder has hours/labor fields. BottlingRun links to CaseGoodsSku. BlendTrial has proportional component volumes. Addition records product quantities.

### Phase 3 Lab & Fermentation
- **5 Eloquent models** — LabAnalysis, LabThreshold, FermentationRound, FermentationEntry, SensoryNote
- **15+ REST endpoints** — Lab CRUD, threshold CRUD, CSV import, fermentation CRUD + lifecycle, chart, sensory CRUD
- **4 Filament resources** + fermentation chart widget

### Phase 4 Inventory Management
- **12 Eloquent models** — CaseGoodsSku, Location, StockLevel, StockMovement, DryGoodsItem, RawMaterial, Equipment, MaintenanceLog, PurchaseOrder, PurchaseOrderLine, PhysicalCount, PhysicalCountLine
- **30+ REST endpoints** — Full CRUD for all inventory entities, stock transfers, PO receiving, physical count lifecycle, bulk wine inventory
- **6 Filament resources** + 2 custom pages + 5 relation managers
- **Key for Phase 5:**
  - `DryGoodsItem.cost_per_unit` — Packaging material costs (bottle, cork, capsule, label, carton) for bottling COGS
  - `RawMaterial.cost_per_unit` — Cellar supply costs for addition-based cost entries
  - `CaseGoodsSku.cost_per_bottle` — Field exists, awaiting population from COGS calculation
  - `InventoryService` — Centralized stock operations with event logging
  - Auto-deduction from production is NOT wired yet — Phase 5 Sub-Task 6 may need to implement this to get accurate packaging costs at bottling

### Test Suite Status
```
Tests:    ~680+ passed
PHPStan:  0 errors (level 6)
Pint:     0 style issues
Groups:   foundation (~141), production (~213), lab (~124), inventory (~200+)
```

## Key Patterns to Follow

1. **All winery operations must write events** via `app(EventLogger::class)->log()`. Cost events should use `cost_` or `cogs_` prefix for auto-resolution to `event_source='accounting'`.
2. **All API responses use `ApiResponse::*`** — `success()`, `created()`, `paginated()`, `error()`. No raw `response()->json()`. Pagination meta is FLAT: `meta.total`, `meta.current_page`, `meta.per_page`, `meta.last_page`.
3. **Tenancy tests use `DatabaseMigrations`**, not `RefreshDatabase`. Clean up schemas in `afterEach`.
4. **Multi-user tests need `app('auth')->forgetGuards()`** after switching users mid-test.
5. **Add `use LogsActivity;`** to any new tenant model that should be audited.
6. **Self-contained event payloads** — include human-readable names (lot_name, sku_name) alongside foreign keys.
7. **PHPStan requires `@property` PHPDoc blocks** on models. Include `@property-read` for relationships. Generic annotations on relationship returns: `BelongsTo<Lot, $this>`, `HasMany<Model, $this>`.
8. **Cost entries must be immutable** (append-only). Corrections are negative adjustment entries, never edits.
9. **Use `decimal` columns** for all money/cost fields. Never use float. Consider `bcmath` for calculations.
10. **Filament resources** go under the appropriate navigation group. Cost/COGS resources should go under an **"Accounting"** navigation group (new group).
11. **Test helper function names must be globally unique** across all test files (Pest loads all files in flat namespace).
12. **Add `->group('accounting')` to all Phase 5 test files.**
13. **Validation errors use custom format**: `errors[].field` not Laravel standard keyed `errors.field_name[]`.
14. **`relationLoaded()` pattern** — not `whenLoaded()` — matches existing codebase convention.
15. **Row-level locking** with `lockForUpdate()` for any operations that modify financial data concurrently.

## Phase 5 Specifics

### Models You'll Create
- **LotCostEntry** — Immutable per-lot cost ledger entry. Types: fruit, material, labor, overhead, transfer_in, manual. Links to reference records (Addition, WorkOrder, BlendTrial).
- **LaborRate** — Configurable hourly rate per role (or per user).
- **OverheadRate** — Configurable allocation rates (per gallon, per case, per labor hour).
- **LotCogsSummary** — Snapshot of calculated COGS at bottling: total cost breakdown, per-gallon, per-bottle, per-case costs.

### Events You'll Write
| Event | Entity | When |
|-------|--------|------|
| `cost_entry_created` | lot | Cost entry added to lot ledger (any type) |
| `cogs_calculated` | lot | COGS summary computed at bottling |

### Critical Math — Test Exhaustively (Tier 1)
- **Blend cost rollthrough**: Lot A ($10/gal, 100 gal) + Lot B ($15/gal, 50 gal) → Blended lot costs ($10×100 + $15×50) / 150 = $11.67/gal. Test with 2, 3, and 5 source lots.
- **Split cost propagation**: All child lots inherit parent's cost-per-gallon exactly.
- **Per-bottle COGS**: (lot accumulated cost + packaging material costs + bottling labor) / bottles_filled
- **Rounding**: Use `bcmath` or specify decimal precision. Never truncate mid-calculation.

### Integration with Existing Models
- **WorkOrder** → labor cost entries (hours × labor rate → lot cost ledger)
- **Addition** → material cost entries (raw material cost_per_unit × quantity → lot cost ledger). Note: auto-deduction mapping from Addition to RawMaterial is not yet wired from Phase 4.
- **BlendTrial** → proportional cost rollthrough on finalize
- **BottlingRun** → triggers COGS calculation. Packaging costs from DryGoodsItem cost_per_unit. Updates CaseGoodsSku.cost_per_bottle.
- **Lot** → cost entries accumulate per lot. LotCogsSummary links to lot.

### Navigation Group
Cost accounting resources should go under an **"Accounting"** navigation group in Filament (new group, after Inventory).

## Carry-Over Debt from Phase 4

**Auto-deduction from production not wired** — `InventoryService` methods (`receive`, `sell`, `adjust`) exist, but hooks into BottlingRun completion (deduct dry goods) and Addition creation (deduct raw materials) are not implemented. Phase 5 Sub-Task 6 (per-bottle COGS at bottling) is the natural place to wire this — at bottling completion, the system needs to know exactly which packaging materials were consumed and their costs. You may need to implement the auto-deduction hooks as part of Phase 5, or at minimum query the dry goods cost_per_unit for each component type.

## Deferred Items from Previous Phases (Optional)

These are not blocking and can be deferred further:
1. **Token ability endpoint enforcement** — from Phase 1-2 audit
2. **Filament Livewire CRUD tests** — from Phase 1-2 audit
3. **Dashboard overview widgets** — Dashboard.php is a placeholder
4. **Low stock alert notifications** — reorder_point fields exist, no notification dispatch
5. **CSV import partial failure handling** — Lab CSV import rolls back on any row failure
6. **`confirmMlDryness()` API endpoint** — service method exists, no route

## Your First Sub-Task

Start with **Sub-Task 1** from `docs/execution/tasks/05-cost-accounting.md`: Lot cost entry model and accumulation.

Before building, tell the human:
- What you're about to do (one sentence)
- What files you'll create or modify
- Whether there are any questions or decisions needing human input

Then build. Then test. Then ask for verification. Then write the INFO entry to `docs/execution/completed/05-cost-accounting.info.md`. Then move to the next sub-task.

## Human Steps Required

**All sub-tasks:** Human runs the testsuite to verify:
```bash
make testsuite    # Pest + Pint + PHPStan
make test G=accounting   # Fast iteration on accounting tests only
make fresh        # If you need to verify demo data seeding
```

For all other work, you can build and test autonomously.

## Critical Rules

1. **Follow the sub-task order.** They're sequenced for dependencies.
2. **Write the INFO file after every sub-task.** Append to `docs/execution/completed/05-cost-accounting.info.md`.
3. **Update reference docs** when you establish new patterns (e.g., new event types in `references/event-log.md`).
4. **Test per the tier system.** Financial math is Tier 1. COGS calculations are Tier 1. All cost rollthrough math is Tier 1.
5. **Log structured, not interpolated.** Include `tenant_id` in every tenant-scoped log.
6. **The tech stack is locked.** Don't substitute anything.
7. **Events are the source of truth.** Every cost operation writes an event via EventLogger.
8. **Don't break existing tests.** Run the full suite (~680+ tests), not just new tests.
9. **Plan tiers are `free|basic|pro|max`.** Use `$tenant->hasPlanAtLeast('pro')` for feature gating.
10. **New ideas go to `docs/ideas/`, not into scope.**
11. **Cost entries are immutable.** Corrections are negative adjustment entries, never edits to historical records.
12. **Never use floating point for money.** Use `decimal` columns and `bcmath` for calculations.

## Go

Read the files listed above. Then begin Sub-Task 1 of `05-cost-accounting.md`.
