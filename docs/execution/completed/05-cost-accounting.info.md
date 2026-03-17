# Cost Accounting & COGS — Completion Record

> Task spec: `docs/execution/tasks/05-cost-accounting.md`
> Phase: 5

---

## Sub-Task 1: Lot Cost Entry Model and Accumulation
**Completed:** 2026-03-16 | **Status:** Done

### Key Decisions
- **Immutable model**: `UPDATED_AT = null` constant. No edits; corrections are negative adjustment entries.
- **bcmath everywhere**: All money calculations use `bcadd`, `bcmul`, `bcdiv`, `bccomp` with 4-digit precision.
- **Cost types as constants**: `LotCostEntry::COST_TYPES` = fruit, material, labor, overhead, transfer_in.
- **Reference types as constants**: purchase, addition, work_order, manual, blend_allocation, split_allocation, bottling.
- **EventLogger integration**: `cost_entry_created` operation type → auto-resolves to `event_source = 'accounting'`.

### Files Created
- `api/database/migrations/tenant/2026_03_16_300001_create_lot_cost_entries_table.php`
- `api/app/Models/LotCostEntry.php`
- `api/app/Services/CostAccumulationService.php`
- `api/app/Http/Controllers/Api/V1/LotCostController.php`
- `api/tests/Feature/Accounting/LotCostEntryTest.php`

### Files Modified
- `api/app/Models/Lot.php` — added `costEntries()` and `cogsSummaries()` relationships
- `api/routes/api.php` — added cost routes

### Patterns Established
- **CostAccumulationService**: Single entry point for all cost mutations (parallel to EventLogger for events, InventoryService for stock).
- **Scopes**: `ofCostType()`, `forLot()`, `ofReferenceType()`, `performedBetween()`.

---

## Sub-Task 2: Labor Cost Tracking on Work Orders
**Completed:** 2026-03-16 | **Status:** Done

### Key Decisions
- **New migration for hours/labor_cost**: Handoff doc stated fields existed but they didn't — created `2026_03_16_300003_add_hours_to_work_orders_table.php`.
- **LaborRate model with active scope**: `LaborRate::getActiveRate(string $role)` static method for lookups.
- **Zero hours = no cost entry**: Work orders with 0 or no hours skip labor cost creation.
- **No lot = calculate cost but don't create entry**: Work orders without `lot_id` still get `labor_cost` on the model.

### Files Created
- `api/database/migrations/tenant/2026_03_16_300002_create_labor_rates_table.php`
- `api/database/migrations/tenant/2026_03_16_300003_add_hours_to_work_orders_table.php`
- `api/app/Models/LaborRate.php`
- `api/tests/Feature/Accounting/LaborCostTest.php`

### Files Modified
- `api/app/Services/WorkOrderService.php` — added labor cost calculation on completion
- `api/app/Models/WorkOrder.php` — added `hours`, `labor_cost` to fillable/casts

---

## Sub-Task 3: Overhead Allocation
**Completed:** 2026-03-16 | **Status:** Done

### Key Decisions
- **Three allocation methods**: per_gallon, per_case, per_labor_hour — as constants on `OverheadRate::ALLOCATION_METHODS`.
- **Batch allocation via `allocateAllActive()`**: Convenience method for monthly runs across all active overhead rates × in-progress/aging lots.
- **Filament under "Accounting" group**: OverheadRateResource (sort 1), LaborRateResource (sort 2).

### Files Created
- `api/database/migrations/tenant/2026_03_16_300004_create_overhead_rates_table.php`
- `api/app/Models/OverheadRate.php`
- `api/app/Services/OverheadAllocationService.php`
- `api/app/Filament/Resources/OverheadRateResource.php` + Pages (List, Create, Edit)
- `api/app/Filament/Resources/LaborRateResource.php` + Pages (List, Create, Edit)
- `api/tests/Feature/Accounting/OverheadAllocationTest.php`

### Deviations from Spec
- Standard vs. actual costing toggle deferred — most wineries only use actual costing. Can be added as a winery profile setting later.

---

## Sub-Task 4: Cost Rollthrough for Blends
**Completed:** 2026-03-16 | **Status:** Done

### Key Decisions
- **Proportional by volume**: When Lot A (100 gal, $1000) + Lot B (50 gal, $750) blend → blended lot gets $1000 + $750 = $1750 via `transfer_in` entries.
- **Source volume calculation**: Component volume / (source lot current volume + component volume) × source total cost. Accounts for volume already deducted from source lot at time of cost rollthrough.
- **No modification of source entries**: New `transfer_in` entries on blended lot; source lot entries untouched.

### Files Modified
- `api/app/Services/BlendService.php` — added `rollCostsToBlendedLot()` after finalization

### Tests
- `api/tests/Feature/Accounting/CostRollthroughTest.php` — proportional cost transfer, partial volume contribution

---

## Sub-Task 5: Cost Rollthrough for Lot Splits
**Completed:** 2026-03-16 | **Status:** Done

### Key Decisions
- **Cost-per-gallon preservation**: All children get same $/gal as parent.
- **Proportional by child volume**: Child cost = (child volume / parent volume) × parent total cost.

### Files Modified
- `api/app/Services/LotSplitService.php` — added `splitCostsToChildren()` after split

### Tests
- `api/tests/Feature/Accounting/CostRollthroughTest.php` — even split, uneven 3-way split

---

## Sub-Task 6: Per-Bottle COGS Calculation at Bottling
**Completed:** 2026-03-16 | **Status:** Done

### Key Decisions
- **COGS = bulk wine accumulated cost + packaging material costs**: Packaging costs from `BottlingComponent.product_name` matched to `DryGoodsItem.cost_per_unit` via ilike lookup.
- **Immutable LotCogsSummary**: `UPDATED_AT = null`, snapshot of cost state at bottling time.
- **CaseGoodsSku.cost_per_bottle updated**: Populated from COGS calculation when SKU linked to bottling run.
- **`cogs_calculated` event**: Written to event log with accounting source.

### Files Created
- `api/database/migrations/tenant/2026_03_16_300005_create_lot_cogs_summaries_table.php`
- `api/app/Models/LotCogsSummary.php`
- `api/tests/Feature/Accounting/BottlingCogsTest.php`

### Files Modified
- `api/app/Services/CostAccumulationService.php` — added `calculateBottlingCogs()`
- `api/app/Services/BottlingService.php` — calls COGS calculation on completion
- `api/app/Http/Controllers/Api/V1/LotCostController.php` — added `cogs()` endpoint
- `api/routes/api.php` — added GET `/lots/{lot}/cogs` route

---

## Sub-Task 7: COGS Reporting Filament Pages
**Completed:** 2026-03-16 | **Status:** Done

### Key Decisions
- **Single CostReports page**: Combines COGS by lot table, cost by vintage summary, and margin report in one scrollable page.
- **CSV export**: Streams full COGS data as downloadable CSV for accountants.
- **Margin report**: Active SKUs with both price and cost_per_bottle, showing gross margin % with color coding (≥50% green, ≥30% yellow, <30% red).

### Files Created
- `api/app/Filament/Pages/CostReports.php`
- `api/resources/views/filament/pages/cost-reports.blade.php`

---

## Sub-Task 8: Cost Accounting Demo Data
**Completed:** 2026-03-16 | **Status:** Done

### Key Decisions
- **Separate CostAccountingSeeder**: Runs after ProductionSeeder and InventorySeeder. Creates labor rates, overhead rates, fruit/material/labor costs across 25+ lots, overhead allocations for active lots, and COGS summaries for completed bottling runs.
- **Realistic Paso Robles pricing**: Fruit costs $7-15/gal reflecting actual Paso region grape prices by variety.
- **SKU pricing auto-generated**: 2.8-4.2× COGS multiplier for realistic margins.

### Files Created
- `api/database/seeders/CostAccountingSeeder.php`

### Files Modified
- `api/database/seeders/DemoWinerySeeder.php` — added CostAccountingSeeder call

---

## Phase 5 Summary

### New Models (4)
- `LotCostEntry` — immutable cost ledger per lot
- `LaborRate` — configurable labor rates by role
- `OverheadRate` — configurable overhead allocation rates
- `LotCogsSummary` — immutable COGS snapshot at bottling

### New Services (2)
- `CostAccumulationService` — all cost mutations (fruit, material, labor, transfer_in, manual, COGS)
- `OverheadAllocationService` — overhead allocation to lots

### New Migrations (5)
- `300001_create_lot_cost_entries_table`
- `300002_create_labor_rates_table`
- `300003_add_hours_to_work_orders_table`
- `300004_create_overhead_rates_table`
- `300005_create_lot_cogs_summaries_table`

### New API Endpoints (2)
- `GET /api/v1/lots/{lot}/costs` — cost breakdown for a lot
- `POST /api/v1/lots/{lot}/costs` — add manual cost entry
- `GET /api/v1/lots/{lot}/cogs` — COGS summary for a lot

### Services Modified (4)
- `BlendService` — cost rollthrough on finalize
- `LotSplitService` — cost split to children
- `WorkOrderService` — labor cost on completion
- `BottlingService` — COGS calculation on completion
- `AdditionService` — material cost from raw materials

### Filament (3 resources/pages)
- `OverheadRateResource` (Accounting group, sort 1)
- `LaborRateResource` (Accounting group, sort 2)
- `CostReports` page (Accounting group, sort 3)

### Test Files (4)
- `LotCostEntryTest.php` — cost entry CRUD, event logging, API endpoints
- `LaborCostTest.php` — labor cost on work order completion
- `OverheadAllocationTest.php` — per-gallon, per-labor-hour, batch allocation
- `CostRollthroughTest.php` — blend and split cost proportional math
- `BottlingCogsTest.php` — COGS calculation, SKU update, event logging

### Carry-Over Debt
- **Standard vs. actual costing toggle**: Deferred. Most wineries use actual only.
- **Auto-deduction from bottling (dry goods inventory)**: BottlingService calls COGS now but doesn't auto-deduct dry goods inventory via InventoryService. This is a Phase 4 carry-over that should be wired if inventory accuracy is prioritized.
- **COGS reports endpoint**: The spec mentions `GET /api/v1/reports/cogs` and `GET /api/v1/reports/margins` API endpoints — these are served via Filament pages instead. API versions can be added if mobile/external consumers need them.
