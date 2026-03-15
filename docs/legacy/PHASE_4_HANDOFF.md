# VineSuite — Phase 4 Handoff Prompt

> Copy everything below the line into your first AI session to begin work on Phase 4: Inventory Management.
> Phase 1: Foundation is complete (15/15 sub-tasks). Phase 2: Production Core is complete (14/14 sub-tasks). Phase 3: Lab & Fermentation is complete (7/7 sub-tasks). Test suite: 478 tests passing.

---

## Who You Are

You are continuing development on VineSuite, a winery SaaS platform. Phases 1–3 are complete — foundation, production, and lab/fermentation modules are built, tested, and passing CI. Your job is to execute Phase 4: Inventory Management, which tracks all winery inventory across four categories: bulk wine, case goods, dry goods, and raw materials.

You do not need to plan. The planning is done. You need to build, test, and record.

## Before You Write Any Code

Read these files in this order. Do not skip any of them.

1. `docs/WORKFLOW.md` — The development lifecycle: LOAD → BUILD → TEST → VERIFY → RECORD → UPDATE. This is your operating manual. Follow it exactly.

2. `docs/execution/phase-recaps/phase-3-lab-fermentation.md` — Compressed context for everything built in Phase 3. Read this instead of the full INFO file. Covers architecture decisions, patterns established, known debt, and the inline Alpine.js + Livewire v3 `@assets` pattern for custom widgets.

3. `docs/execution/phase-recaps/phase-2-production-core.md` — Phase 2 context. Covers the production models you'll depend on: Lot, Vessel, Barrel, BottlingRun. The `lot_vessel` pivot's volume tracking pattern is especially relevant — inventory derives from these production records.

4. `docs/execution/phase-recaps/phase-1-foundation.md` — Phase 1 context. Skim for architecture decisions and patterns — most are already applied in Phase 2–3 code.

5. `docs/execution/tasks/04-inventory.md` — **Your task spec.** 11 sub-tasks: case goods SKU registry, location/stock levels, stock movements, dry goods, raw materials, equipment/maintenance, purchase orders, auto-deduction from production, low stock alerts, inventory counts, demo data. Work through them top-to-bottom.

6. `docs/guides/testing-and-logging.md` — Testing tiers and logging standards. Note the **PHP / Laravel Testing Gotchas** section — it covers `forgetGuards()` for multi-user tests, `DatabaseMigrations` vs `RefreshDatabase`, UUID pivot attach patterns, and PostgreSQL HAVING alias restrictions.

**Load when relevant (not upfront):**
- `docs/references/event-log.md` — How EventLogger works. Includes all Phase 2 and Phase 3 event types. Load for any sub-task that writes events.
- `docs/references/multi-tenancy.md` — Tenant lifecycle, domain records, Filament integration gotchas.
- `docs/references/auth-rbac.md` — Auth, roles, rate limiting. Includes the Token Name Contract.
- `docs/guides/filament-tenancy.md` — The 3 critical fixes for Filament + stancl/tenancy. Load before creating any Filament resources.
- `docs/architecture.md` — Full architecture doc. Section 6 (Inventory) is most relevant for Phase 4.

## What Already Exists

### Phase 1 Foundation
- **Docker environment** — `docker compose up -d` starts all services. `make fresh` flushes Redis + resets DB + re-seeds.
- **Laravel 12 API** — PHP 8.4, PostgreSQL 16, Redis, stancl/tenancy v3.9 (schema-per-tenant)
- **Authentication** — Sanctum tokens scoped per client type, 7 roles with ~55 permissions
- **Event Log** — `EventLogger::log()`, immutable `events` table with PostgreSQL trigger
- **Activity Logging** — `LogsActivity` trait, immutable `activity_logs` table
- **Billing** — Stripe Cashier on Tenant model, Free/Basic/Pro/Max tiers
- **Filament Portal** — On tenant subdomains, navigation groups: Lab, Production, Settings
- **API Envelope** — `{ "data": ..., "meta": {}, "errors": [] }` on all responses

### Phase 2 Production Core
- **8 Eloquent models** — Lot, Vessel, Barrel, WorkOrder, Addition, Transfer, BottlingRun, BlendTrial (plus pivot/component models)
- **17+ REST endpoints** — Full CRUD + specialized operations (complete, bulk create, calendar)
- **8 Filament resources** — CRUD interfaces with custom actions, calendar view, event timeline
- **Demo winery** — 38 lots across 4 vintages, 67 vessels, 65+ additions, 18 transfers, 30 work orders, 2 blend trials, 4 bottling runs

### Phase 3 Lab & Fermentation
- **5 Eloquent models** — LabAnalysis, LabThreshold, FermentationRound, FermentationEntry, SensoryNote
- **15+ REST endpoints** — Lab CRUD, threshold CRUD, CSV import (preview/commit), fermentation CRUD + lifecycle, chart endpoints, sensory CRUD
- **4 Filament resources** — LabAnalysis (view-only), LabThreshold (full CRUD), FermentationRound (with chart widget), SensoryNote (view-only)
- **CSV Import pipeline** — ETS Labs parser + generic fallback, two-phase preview→commit workflow
- **Fermentation Chart** — Custom Livewire widget with Chart.js, dual-axis (Brix + temperature), inline Alpine.js
- **Demo data** — 30+ lab analyses, 9 fermentation rounds, 10 sensory notes, 17 default thresholds

### Test Suite Status
```
Tests:    478 passed
PHPStan:  0 errors (level 6)
Pint:     0 style issues
```

## Key Patterns to Follow

1. **All winery operations must write events** via `app(EventLogger::class)->log()`. Never bypass this. Never `Event::create()` directly.
2. **All API responses use `ApiResponse::*`** — `success()`, `created()`, `message()`, `error()`. No raw `response()->json()`.
3. **Tenancy tests use `DatabaseMigrations`**, not `RefreshDatabase`. Clean up schemas in `afterEach`.
4. **Multi-user tests need `app('auth')->forgetGuards()`** after switching users mid-test.
5. **UUID pivot tables need manual `'id' => (string) Str::uuid()`** in all `attach()` calls.
6. **Add `use LogsActivity;`** to any new tenant model that should be audited.
7. **Immutable records** (lab analyses, additions, transfers, sensory notes) have Create + View pages only in Filament. No Edit, no Delete.
8. **Self-contained event payloads** — include human-readable names (lot_name, sku_name) alongside foreign keys.
9. **Nested lot routes** pattern: `/lots/{lotId}/...` with route parameter overriding body `lot_id`.
10. **PHPStan requires `@property` PHPDoc blocks** on models with dynamic attribute access. Include `@property-read` for relationships used in closures or resources.
11. **PostgreSQL doesn't allow column aliases in HAVING** — use `havingRaw('count(*) > 1')` not `having('cnt', '>', 1)`.
12. **Filament custom widgets** use inline `x-data` + `@assets` directive for CDN scripts, not `Alpine.data()` with `alpine:init` listener.
13. **Page-specific widgets** need `protected static bool $isDiscovered = false` to prevent Dashboard auto-discovery.

## Phase 4 Specifics

### Models You'll Create
- **CaseGoodsSku** — bottled wine product. Linked to origin Lot. Has StockLevels per Location.
- **Location** — where inventory lives (tasting room, back stock, offsite, 3PL).
- **StockLevel** — per-SKU per-Location: on_hand, committed, available (computed).
- **StockMovement** — append-only log of all stock changes (received, sold, transferred, adjusted, bottled).
- **DryGoodsItem** — packaging materials (bottles, corks, capsules, labels, cartons).
- **RawMaterial** — cellar supplies (additives, yeast, nutrients, fining agents).
- **Equipment** — cellar equipment with maintenance tracking.
- **MaintenanceLog** — scheduled/performed maintenance records.
- **PurchaseOrder** + **PurchaseOrderLine** — procurement workflow.

### Events You'll Write
| Event | Entity | When |
|-------|--------|------|
| `stock_received` | sku | Case goods received into inventory |
| `stock_sold` | sku | Case goods sold (POS or online) |
| `stock_transferred` | sku | Case goods moved between locations |
| `stock_adjusted` | sku | Manual inventory adjustment |
| `dry_goods_received` | dry_goods | Dry goods received from vendor |
| `dry_goods_consumed` | dry_goods | Dry goods used in bottling |
| `raw_material_received` | raw_material | Raw materials received |
| `raw_material_consumed` | raw_material | Raw materials used in additions |
| `purchase_order_created` | purchase_order | New PO submitted |
| `purchase_order_received` | purchase_order | PO items received |

### Auto-Deduction Logic (Sub-Task 8)
This is the most complex sub-task. When a bottling run completes, the system should:
1. Create CaseGoodsSku if it doesn't exist
2. Create StockMovement (type: bottled) to add bottles to inventory
3. Deduct DryGoodsItems consumed (bottles, corks, labels, capsules)
4. All within a transaction, all with event logging

When an Addition is recorded, the system should deduct the corresponding RawMaterial if mapped.

### Relationship to Existing Models
- **BottlingRun** → creates CaseGoodsSku + StockMovement. The `sku` field on BottlingRun links to CaseGoodsSku.
- **Addition** → optionally deducts RawMaterial. Need a mapping between addition product names and raw material records.
- **Lot** → CaseGoodsSku may reference origin lot for traceability.
- **Events** → all inventory operations write to the same immutable event log.

### Navigation Group
Inventory resources should go under an **"Inventory"** navigation group in Filament (new group, after Production).

## Deferred Items from Previous Phases (Optional)

These are not blocking and can be deferred further:
1. **Token ability endpoint enforcement** — from Phase 1-2 audit
2. **Filament Livewire CRUD tests** — from Phase 1-2 audit
3. **Dashboard overview widgets** — Dashboard.php is a placeholder with no widgets. Could add an inventory summary widget.
4. **`confirmMlDryness()` API endpoint** — service method exists, no route

## Your First Sub-Task

Start with **Sub-Task 1** from `docs/execution/tasks/04-inventory.md`: Case goods SKU registry.

Before building, tell the human:
- What you're about to do (one sentence)
- What files you'll create or modify
- Whether there are any questions or decisions needing human input

Then build. Then test. Then ask for verification. Then write the INFO entry to `docs/execution/completed/04-inventory.info.md`. Then move to the next sub-task.

## Human Steps Required

**All sub-tasks:** Human runs the testsuite to verify:
```bash
make testsuite    # Pest + Pint + PHPStan
make fresh        # If you need to verify demo data seeding
```

For all other work, you can build and test autonomously.

## Critical Rules

1. **Follow the sub-task order.** They're sequenced for dependencies.
2. **Write the INFO file after every sub-task.** Append to `docs/execution/completed/04-inventory.info.md`.
3. **Update reference docs** when you establish new patterns (e.g., new event types in `references/event-log.md`).
4. **Test per the tier system.** Inventory math is Tier 1. Auto-deduction is Tier 1. CRUD is Tier 2.
5. **Log structured, not interpolated.** Include `tenant_id` in every tenant-scoped log.
6. **The tech stack is locked.** Don't substitute anything.
7. **Events are the source of truth.** Every inventory operation writes an event via EventLogger.
8. **Don't break existing tests.** Run the full suite (478 tests), not just new tests.
9. **Plan tiers are `free|basic|pro|max`.** Use `$tenant->hasPlanAtLeast('pro')` for feature gating.
10. **New ideas go to `docs/ideas/`, not into scope.**

## Go

Read the files listed above. Then begin Sub-Task 1 of `04-inventory.md`.
