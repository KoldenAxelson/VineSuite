# Phase 5 Recap — Cost Accounting & COGS

> Duration: 2026-03-16
> Task files: `05-cost-accounting.md` | INFO: `05-cost-accounting.info.md`

---

## Delivered

- Lot cost ledger: immutable, append-only `LotCostEntry` records tracking fruit, material, labor, overhead, and transfer-in costs per lot with bcmath precision (4 decimal places)
- Labor cost tracking: `LaborRate` model with per-role hourly rates, auto-calculated labor costs on work order completion (hours × rate → cost entry on lot)
- Overhead allocation: `OverheadRate` model with three allocation methods (per_gallon, per_case, per_labor_hour), batch allocation service that applies active rates across in-progress/aging lots
- Cost rollthrough for blends: proportional cost transfer from source lots to blended lot on blend finalization, based on volume contribution ratio
- Cost rollthrough for splits: proportional cost distribution to child lots preserving cost-per-gallon from parent
- Per-bottle COGS at bottling: calculates accumulated bulk wine cost + packaging material costs (from DryGoodsItem.cost_per_unit × BottlingComponent.quantity_used), creates immutable `LotCogsSummary` snapshot, writes cost_per_bottle to CaseGoodsSku
- Material cost auto-capture: additions with linked raw materials automatically create material cost entries (raw_material.cost_per_unit × addition.total_amount)
- Cost reporting: Filament page with COGS-by-lot table (vintage/variety filters), cost-by-vintage summary, margin report (price vs COGS with color-coded thresholds), CSV export
- Filament admin for rates: OverheadRateResource and LaborRateResource under "Accounting" navigation group
- API endpoints: GET/POST `/lots/{lot}/costs` (breakdown + manual entry), GET `/lots/{lot}/cogs` (COGS summary)
- Demo data: 150+ cost entries across 25+ lots with realistic Paso Robles grape pricing, labor rates for 4 roles, 5 overhead rates, COGS summaries for completed bottling runs, auto-generated SKU retail pricing for margin report

---

## Architecture Decisions

### CostAccumulationService as Sole Cost Write Path
All cost mutations flow through `CostAccumulationService` — mirrors the pattern of EventLogger (sole event write path) and InventoryService (sole stock mutation path). Methods: `recordFruitCost`, `recordMaterialCost`, `recordLaborCost`, `recordManualCost`, `recordTransferInCost`, `calculateBottlingCogs`. Summary queries: `getTotalCost`, `getCostBreakdown`, `getCostPerGallon`.

### Immutable Cost Entries (Append-Only)
`LotCostEntry` uses `UPDATED_AT = null` constant — no edits, matching the Event model pattern. Corrections are negative adjustment entries. `LotCogsSummary` is also immutable (a snapshot of cost state at bottling time). Both tables have `created_at` only.

### bcmath for All Monetary Calculations
Every cost calculation uses `bcadd`, `bcmul`, `bcdiv`, `bccomp` with 4-digit scale. No floats touch money. Database columns are `decimal(12,4)` for amounts and `decimal(10,4)` for rates. CaseGoodsSku.cost_per_bottle uses `decimal:2` (existing convention from Phase 4).

### Cost Rollthrough via Volume Proportion
Blend cost rollthrough: `(componentVolume / sourceTotalVolumeBeforeDeduction) × sourceTotalCost`. Source lot volume is already deducted when cost calc runs, so the service adds back the component volume to reconstruct the original denominator. Split cost rollthrough: `(childVolume / parentVolume) × parentTotalCost`, preserving uniform cost-per-gallon across all children.

### COGS = Bulk Wine Cost + Packaging
Packaging cost calculated by matching `BottlingComponent.product_name` to `DryGoodsItem.name` via ilike lookup, then multiplying `quantity_used × cost_per_unit`. This loose coupling avoids requiring a FK from BottlingComponent to DryGoodsItem (which doesn't exist in the schema). Trade-off: name matching is fragile but works for the current data model.

### Event Source Auto-Resolution
All Phase 5 events use `cost_` and `cogs_` prefixes which auto-resolve to `event_source = 'accounting'` via the config mapping established in Phase 4. Events: `cost_entry_created`, `cogs_calculated`.

---

## Deviations from Spec

- **Standard vs. actual costing toggle deferred:** Spec mentioned both options. Only actual costing implemented — standard costing is rare in small wineries and can be added as a WineryProfile setting later.
- **COGS report API endpoints deferred:** Spec listed `GET /api/v1/reports/cogs` and `GET /api/v1/reports/margins`. These are served via the Filament CostReports page instead. API endpoints can be added if mobile/external consumers need them.
- **Bottling labor cost as separate line:** `LotCogsSummary.bottling_labor_cost` field exists but is hardcoded to `0.0000`. A future enhancement could pull labor hours from bottling-specific work orders.

---

## Patterns Established

- **Cost service injection in production services:** BlendService, LotSplitService, WorkOrderService, BottlingService, and AdditionService all inject CostAccumulationService and call it after their primary operation. Pattern: production action → cost side-effect.
- **Immutable snapshot models:** LotCogsSummary joins LotCostEntry and Event as models with `UPDATED_AT = null`. Use this for any future audit-critical records.
- **OverheadAllocationService batch pattern:** `allocateAllActive()` applies all active overhead rates to qualifying lots in one call. Suitable for monthly cron jobs.

---

## Known Debt

1. **Auto-deduction from bottling not wired** — BottlingService calls COGS calculation but doesn't auto-deduct dry goods inventory via InventoryService when packaging is consumed. Impact: medium. Affects inventory accuracy. Carried from Phase 4.
2. **Packaging cost name-matching fragility** — COGS calculation matches BottlingComponent.product_name to DryGoodsItem.name via ilike. If names diverge, packaging cost won't be captured. Impact: low. Fix: add `inventory_item_id` FK to BottlingComponent (field exists but isn't used for cost lookup).
3. **No overhead allocation scheduler** — `allocateAllActive()` exists but no cron trigger. Wineries must manually trigger via Filament or API. Impact: low. Fix in Phase 18 (Notifications/Automation).
