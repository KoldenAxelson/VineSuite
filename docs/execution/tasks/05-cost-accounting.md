# Cost Accounting & COGS

## Phase
Phase 2

## Dependencies
- `01-foundation.md` — event log, auth, Filament
- `02-production-core.md` — Lot model (costs accumulate per lot), BottlingRun (triggers per-bottle COGS calc)
- `04-inventory.md` — Raw materials (cost per unit for additions), Dry goods (packaging costs for bottling)

> **Before starting:** This spec was written before Phases 1-4 were implemented. Verify assumptions against:
> - `CONVENTIONS.md` — established code patterns (InventoryService, EventLogger, Filament conventions)
> - `execution/phase-recaps/phase-4-inventory.md` — InventoryService is the sole stock mutation path; auto-deduction from production is listed as known debt (not yet wired)
> - `execution/phase-recaps/phase-2-production-core.md` — UUID pivot tables need manual ID generation; immutable operation logs pattern
> - `references/event-source-partitioning.md` — new event types need `event_source` mapping (prefix `cost_` → `accounting`)

## Goal
Track the true cost of producing every bottle of wine. Costs accumulate per lot through all operations — fruit purchase, material additions, labor on work orders, overhead allocation — and roll through blends proportionally. At bottling, the system calculates per-bottle and per-case COGS. This gives winemakers and owners margin visibility they've never had without a spreadsheet and an accountant.

## Data Models

- **LotCostEntry** — `id` (UUID), `lot_id`, `cost_type` (fruit/material/labor/overhead/transfer_in), `description`, `amount` (decimal), `quantity` (decimal, nullable), `unit_cost` (decimal, nullable), `reference_type` (addition/work_order/purchase/manual/blend_allocation), `reference_id` (nullable), `performed_at`, `created_at`
  - Relationships: belongsTo Lot

- **OverheadRate** — `id`, `name`, `allocation_method` (per_gallon/per_case/per_labor_hour), `rate` (decimal), `is_active`, `created_at`, `updated_at`

- **LaborRate** — `id`, `role`, `hourly_rate` (decimal), `created_at`, `updated_at`

- **LotCogsSummary** — `id`, `lot_id`, `total_fruit_cost`, `total_material_cost`, `total_labor_cost`, `total_overhead_cost`, `total_cost`, `volume_gallons_at_calc`, `cost_per_gallon`, `bottles_produced` (nullable), `cost_per_bottle` (nullable), `cost_per_case` (nullable), `calculated_at`, `created_at`

## Sub-Tasks

### 1. Lot cost entry model and accumulation
**Description:** Create the per-lot cost ledger that accumulates costs through all operations.
**Files to create:**
- `api/app/Models/LotCostEntry.php`
- `api/database/migrations/xxxx_create_lot_cost_entries_table.php`
- `api/app/Services/CostAccumulationService.php`
**Acceptance criteria:**
- Cost entries created automatically when: fruit purchased for lot, additions made (cost from raw material), labor logged on work orders
- Manual cost entries supported for one-off expenses
- Cost accumulates chronologically per lot
- Total cost queryable at any point in time
**Gotchas:** Costs must be immutable entries (append-only, like the event log). To correct a cost, add a negative adjustment entry — never edit a historical cost record.

### 2. Labor cost tracking on work orders
**Description:** When work orders are completed, record labor cost based on hours and the worker's labor rate.
**Files to create:**
- `api/app/Models/LaborRate.php`
- `api/database/migrations/xxxx_create_labor_rates_table.php`
- Modify `api/app/Services/WorkOrderService.php` — add cost entry on completion
**Acceptance criteria:**
- Work order completion captures hours worked
- Labor rate configurable per role (or per user)
- Cost entry auto-created: hours × rate → lot cost ledger
**Gotchas:** Hours may not be tracked on every work order (quick 5-min pump-over). Make hours optional with a default of 0.

### 3. Overhead allocation
**Description:** Implement configurable overhead allocation methods so fixed costs (rent, utilities, insurance) can be spread across lots.
**Files to create:**
- `api/app/Models/OverheadRate.php`
- `api/database/migrations/xxxx_create_overhead_rates_table.php`
- `api/app/Services/OverheadAllocationService.php`
- `api/app/Filament/Resources/OverheadRateResource.php`
**Acceptance criteria:**
- Overhead rates configurable: per gallon, per case, per labor hour
- Allocation can be run manually (monthly or at bottling)
- Creates cost entries against each lot proportionally
- Standard vs. actual costing toggle (winery can choose)
**Gotchas:** Overhead allocation is inherently imprecise. Keep it simple — most wineries just want a per-gallon rate they update annually.

### 4. Cost rollthrough for blends
**Description:** When lots are blended, costs from source lots roll into the new blended lot proportionally by volume.
**Files to modify:**
- `api/app/Services/BlendService.php` — add cost rollthrough on finalize
- `api/app/Services/CostAccumulationService.php` — add blend cost calculation
**Acceptance criteria:**
- When a blend is finalized, each source lot's accumulated cost transfers to the new lot proportionally
- Source lot cost entries are not modified — new `transfer_in` cost entries created on the blended lot
- Blended lot's total cost = sum of proportional costs from all sources
**Gotchas:** If Lot A ($10/gal, 100 gal) and Lot B ($15/gal, 50 gal) are blended, the blended lot costs: (100×$10 + 50×$15) / 150 = $11.67/gal. Test this math carefully.

### 5. Cost rollthrough for lot splits
**Description:** When lots are split, costs split proportionally by volume.
**Files to modify:**
- `api/app/Services/LotSplitService.php` — add cost split
**Acceptance criteria:**
- Parent lot costs split to children proportionally by volume
- Child lot cost per gallon equals parent cost per gallon at time of split
**Gotchas:** Similar to blend but in reverse. Cost per gallon should be identical for all children.

### 6. Per-bottle COGS calculation at bottling
**Description:** At bottling, calculate the final cost per bottle including bulk wine cost, packaging materials, and bottling labor.
**Files to modify:**
- `api/app/Services/BottlingService.php` — trigger COGS calc on completion
- `api/app/Services/CostAccumulationService.php` — add bottling COGS method
- `api/app/Models/LotCogsSummary.php`
- `api/database/migrations/xxxx_create_lot_cogs_summaries_table.php`
**Acceptance criteria:**
- On bottling completion: lot accumulated cost + packaging material costs + bottling labor = total
- Per-bottle cost = total / bottles_filled
- Per-case cost = per-bottle × case_size
- COGS summary record created and linked to lot and SKU
- Cost per bottle stored on the CaseGoodsSku record
**Gotchas:** Packaging costs come from dry goods inventory (cost_per_unit × quantity_used per component). Include all components: bottle, cork, capsule, front label, back label, carton.

### 7. COGS reporting Filament pages
**Description:** Build COGS reporting views in the management portal.
**Files to create:**
- `api/app/Filament/Pages/CostReports.php`
**Acceptance criteria:**
- COGS by lot, by SKU, by vintage, by variety
- Margin report: selling price vs. COGS by SKU
- Variance report (standard vs. actual if both configured)
- Export COGS data as CSV for accountant
**Gotchas:** Keep reports read-only. Data flows from cost entries → summaries → reports. No manual override of COGS numbers in reports.

### 8. Cost accounting demo data
**Description:** Extend demo seeder with cost entries for demo lots.
**Files to modify:**
- `api/database/seeders/ProductionSeeder.php`
**Acceptance criteria:**
- Demo lots have fruit cost entries, material costs from additions, labor costs from completed work orders
- At least 3 lots have COGS summaries (post-bottling)
- Margin report shows realistic data

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/lots/{lot}/costs` | Get cost breakdown for lot | accountant+ |
| POST | `/api/v1/lots/{lot}/costs` | Add manual cost entry | winemaker+ |
| GET | `/api/v1/lots/{lot}/cogs` | Get COGS summary | accountant+ |
| GET | `/api/v1/reports/cogs` | COGS report (filterable) | accountant+ |
| GET | `/api/v1/reports/margins` | Margin report | owner+ |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `cost_entry_created` | lot_id, cost_type, amount, description, reference | lot_cost_entries |
| `cogs_calculated` | lot_id, sku_id, cost_per_bottle, cost_per_case, total | lot_cogs_summaries, case_goods_skus |

## Testing Notes
- **Unit tests:** Blend cost rollthrough math (test with 2, 3, and 5 source lots at different cost rates), split cost math, per-bottle COGS calculation with all component costs
- **Integration tests:** Full lot lifecycle cost tracking: create lot with fruit cost → add materials → log labor → blend → bottle → verify final COGS
- **Critical:** Rounding — use bcmath or decimal precision for all cost calculations. Never use floating point for money.
