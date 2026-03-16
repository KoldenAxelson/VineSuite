# Inventory Management — COMPLETED

> **Status: COMPLETED** — This phase is historical. Agents should use phase recaps instead.

## Quick Reference

**Phase:** 2
**Dependencies:** Foundation, Production Core (lots, bottling).
**Core accomplishments:** Case goods SKU registry, multi-location stock tracking, stock movements, dry goods/packaging, raw materials, equipment maintenance, purchase orders, physical counts.

---

## Sub-Tasks (Completed)

1. **Case goods SKU registry** — CaseGoodsSku: wine_name, vintage, varietal, format, case_size, UPC, price, cost_per_bottle, image_path, tasting_notes, tech_sheet_path. Auto-created from bottling runs; manual creation for purchased wine. Meilisearch indexing.

2. **Location + stock levels** — Multi-location support (tasting_room_floor, back_stock, offsite_warehouse, 3PL). StockLevel per SKU per location: on_hand, committed, available (computed: on_hand - committed). Atomic updates.

3. **Stock movement logging** — All changes via InventoryService (never direct updates). StockMovement ledger: positive = in, negative = out. Types: received, sold, transferred, adjusted, returned, bottled. SELECT FOR UPDATE pattern for concurrency.

4. **Physical inventory count** — Count session per location. Enter actual counts (scan or manual). Variance report. Approve adjustments → writes stock_adjusted movements. Count history retained.

5. **Stock transfers** — Move SKU quantity location A → B. Source decreases, target increases. Cannot exceed available (but allows non-blocking warning for oversell).

6. **Dry goods/packaging** — DryGoodsItem: bottles, corks, capsules, labels, cartons, dividers. Units vary (each, sleeve, pallet). Reorder points. Auto-deduct on bottling completion.

7. **Raw materials/cellar supplies** — RawMaterial: additives, yeast, nutrients, fining agents, acids, enzymes. Reorder points. Expiration tracking with alerts. Cost per unit (COGS). Auto-deduct when additions logged.

8. **Equipment + maintenance** — Equipment register with CIP/calibration records. MaintenanceLog: cleaning, calibration, repair, inspection. Next due date tracking.

9. **Bulk wine inventory view** — Read-only aggregation of lot/vessel data. Real-time gallons by lot/vessel. Volume reconciliation (book value vs. sum of contents). No separate data store.

10. **Purchase order system** — Simple PO tracking: vendor, items, quantities, expected costs. Receive full or partial. Status: ordered, partial, received, cancelled. Cost per unit captured on receipt.

11. **Inventory demo seeder** — 47 case goods SKUs, stock across 2 locations, common dry goods/raw materials, 5-6 equipment with maintenance history. SKUs correspond to demo lots.

---

## Remaining Gotchas

- **Stock level atomicity:** Race condition prevention via SELECT FOR UPDATE (concurrent POS sales).
- **Unit variance:** Bottles = each, corks = sleeve of 1000, capsules = bag of 500. Store in native unit.
- **Available oversell:** No hard block—UI warns only. Tasting room reality.
- **Bulk wine is derived:** Do NOT duplicate lot/vessel data. This view aggregates.
- **Auto-deduct integration:** AdditionService (02-production-core) calls InventoryService.deductRawMaterial(). BottlingService calls InventoryService.deductDryGoods().

---

## Critical Tests

- Stock level calculations (available = on_hand - committed).
- Concurrent sales: atomic updates prevent race conditions.
- Bottling → case goods creation → stock update flow.
- Addition → raw material deduction flow.
- Physical count → variance → adjustment flow.
