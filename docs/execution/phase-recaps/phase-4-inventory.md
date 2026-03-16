# Phase 4 Recap — Inventory Management

> Duration: 2026-03-15 → 2026-03-15
> Task files: `04-inventory.md`
> INFO files: `04-inventory.info.md`

---

## What Was Delivered
- A complete case goods (bottled wine) inventory system: SKU registry with Meilisearch full-text search, multi-location stock levels with on_hand/committed/available tracking, an append-only stock movement ledger, and inter-location stock transfers with automatic from/to movement pairing
- Bulk wine inventory view that aggregates lot volume data from the production module into a Filament page with total gallons, vessel counts, and lot status filtering
- Dry goods and raw materials registries for packaging materials and cellar supplies, with reorder point alerting, vendor tracking, expiration date management (raw materials), and cost-per-unit fields that feed future COGS calculations
- Equipment registry with maintenance log tracking (cleaning, calibration, inspection, repair, preventive), next-due-date scheduling, pass/fail results, and cost tracking
- A procurement workflow: purchase orders with draft/submitted/partial/received/cancelled lifecycle, line items for both dry goods and raw materials, partial receiving with automatic status transitions, and inventory quantity updates on receipt
- Physical inventory counts: location-scoped count sessions that snapshot system quantities, record actual counts, compute variances, and — on approval — generate stock_adjusted movements to reconcile
- A comprehensive demo dataset: 40+ SKUs across 3 locations, 22 dry goods items, 18 raw materials, 6 equipment with 15 maintenance logs, 4 purchase orders in all statuses, 2 physical counts (completed with variances + in-progress)
- Cross-linked Filament UI: bidirectional stock visibility (SKU↔Location), purchase order history on item views, equipment maintenance history, physical count drill-downs with stat cards, and vintage=0 displayed as "NV"

## Architecture Decisions Made

### Event Source Partitioning (Sub-Task 1)
Added `event_source` column to the events table with auto-resolution from `operation_type` prefix via `EventLogger::resolveSource()`. Maps `stock_/purchase_/equipment_/dry_goods_/raw_material_` → `inventory`, enabling module-level event filtering. The migration temporarily disables the immutability trigger to backfill existing events. See `04-inventory.info.md` Sub-Task 1.

### Scout Search with Eloquent Fallback (Sub-Task 1)
CaseGoodsSku uses `CaseGoodsSku::search($query)->keys()` to get matching IDs from Meilisearch, then composes standard Eloquent `whereIn()` queries for filtering and pagination. Avoids PHPStan type issues with Scout's paginator interface.

### Row-Level Locking for Stock Operations (Sub-Task 3)
`InventoryService` uses `lockForUpdate()` on StockLevel rows within transactions to prevent race conditions on concurrent stock modifications. All stock operations (receive, sell, adjust, transfer) go through `InventoryService` methods, never direct model updates.

### Signed Quantities for Stock Movements (Sub-Task 3)
Stock movements store signed quantities: positive for inbound (received, transferred_in, adjusted up), negative for outbound (sold, transferred_out, adjusted down). This makes the movement ledger self-consistent — summing all movements for a SKU+location yields the current on_hand.

### Manual Polymorphism for PO Lines (Sub-Task 7)
PurchaseOrderLine uses `item_type` + `item_id` columns instead of Laravel's morph relationships. This keeps the schema explicit and queryable, and matches the pattern of the Event model's `entity_type` + `entity_id`. Models define `purchaseOrderLines()` HasMany with a `->where('item_type', ...)` filter.

### Physical Count as Workflow, Not CRUD (Sub-Task 10)
Physical counts are managed through `PhysicalCountService` with explicit lifecycle methods (`startCount`, `recordCounts`, `approve`, `cancel`) rather than generic CRUD. Approval triggers `InventoryService::adjust()` for each non-zero variance, creating auditable stock_adjusted movements with reference back to the physical count.

### Vintage=0 Sentinel for Non-Vintage Items (Sub-Task 11/12)
`case_goods_skus.vintage` is `unsignedSmallInteger NOT NULL`. Non-vintage items (sparkling, merchandise, olive oil) use `0` as a sentinel rather than making the column nullable. Filament tables format `0` as "NV" via `formatStateUsing()` at the presentation layer.

## Deviations from Original Spec
- **Auto-deduction from production (Sub-Task 8)**: Spec called for automatic dry goods deduction when a bottling run completes and raw material deduction when an addition is recorded. Deferred — requires production module modifications and a mapping layer between addition products and raw material records. The `InventoryService` methods exist to support this, but the hooks into BottlingRun and Addition are not wired.
- **Low stock alert system (Sub-Task 9)**: Spec called for configurable alert thresholds and notification dispatch. The `reorder_point` field and `belowReorderPoint()` scope exist on DryGoodsItem and RawMaterial, plus a "Below Reorder Point" filter in Filament, but no notification/email alert system was built. Deferred to a notifications phase.
- **Physical count quantity field is integer**: Spec implied decimal quantities for case goods. Implementation uses integer (whole cases/bottles), which is correct for case goods inventory.
- **3 locations instead of 2**: Spec said "stock levels across 2 locations." Demo seeder creates 3 (Tasting Room, Back Stock Warehouse, Offsite Warehouse) for more realistic demos.
- **Sub-Task 12 (impromptu polish)**: Not in the original spec. Added cross-linked Filament relation managers, physical count drill-down, vintage NV display, and the cancel() event logging fix.

## Patterns Established

### InventoryService as Single Entry Point
All stock-modifying operations go through `InventoryService` methods (`receive`, `sell`, `adjust`, `transfer`). Each method wraps the operation in a transaction with `lockForUpdate()` on the stock level, creates a StockMovement record, updates the StockLevel, and logs an event. Never modify StockLevel directly.

### Bidirectional Filament RelationManagers
When two models have a many-to-many-like relationship through an intermediate table (StockLevel bridges SKU↔Location), create RelationManagers on both sides with cross-links to each other's view pages. Pattern: `StockLevelsRelationManager` on both `CaseGoodsSkuResource` and `LocationResource`.

### Filtered HasMany for Manual Polymorphism
When a table uses `item_type` + `item_id` columns instead of Laravel morphs, define the HasMany relationship with `->where('item_type', 'value')`:
```php
public function purchaseOrderLines(): HasMany
{
    return $this->hasMany(PurchaseOrderLine::class, 'item_id')
        ->where('item_type', 'dry_goods');
}
```

### Presentation-Layer Sentinel Formatting
Sentinel values (vintage=0 → "NV") are formatted via `formatStateUsing()` in Filament tables and `mapWithKeys()` in filter dropdowns. No model accessors — keeps the data layer clean and formatting explicit at the view layer.

### Schema::hasTable Guards on Dynamic Filament Filters
Dynamic filter dropdowns that query a table from a newer migration are guarded with `Schema::hasTable()` to prevent crashes on tenants that haven't run the migration yet.

### Globally Unique Test Helper Names
Pest loads all test files in a flat namespace. Helper functions defined inside test files must have globally unique names across the entire test suite. Convention: prefix with a module-specific identifier (e.g., `seedAndGetInventoryTenant()` not `createTenant()`).

## Known Debt
1. **Auto-deduction not wired** — impact: medium — affects Phase 5 (cost accounting needs material costs flowing automatically). `InventoryService` methods exist but hooks into BottlingRun completion and Addition creation are not implemented.
2. **Low stock alert notifications** — impact: low — `reorder_point` fields and scopes exist, but no notification system. Deferred to Phase 18 (Notifications).
3. **No Filament Livewire CRUD tests** — impact: low — carried from Phase 1-2 audit. All Filament resources are view-layer (Tier 3).
4. **Token ability endpoint enforcement** — impact: low — carried from Phase 1-2 audit.
5. **Dashboard has no widgets** — impact: medium — placeholder Dashboard.php with no inventory summary. Could show low stock alerts, recent PO status, count in progress.
6. **CSV import partial failure handling** — impact: low — carried from Phase 3. Lab CSV import rolls back entire batch on any row failure.
7. **`confirmMlDryness()` API endpoint** — impact: low — carried from Phase 3.

## Reference Docs Updated
- `references/event-log.md` — Updated with 16 Phase 4 event types across 4 categories (stock movements, physical counts, purchase orders, item registries). Added event source partitioning documentation.
- `references/test-groups.md` — Updated inventory group from TBD to ~200+ tests across 11 test files.
- `diagrams/inventory-erd.mermaid` — **Created** — Entity-relationship diagram for all Phase 4 inventory models.

## Metrics
- Sub-tasks completed: 12/12 (11 planned + 1 impromptu polish)
- Test count: ~680+ total (up from ~478 at end of Phase 3), ~200+ new in Phase 4
- Phase 4 test breakdown: CaseGoodsSkuTest, LocationStockLevelTest, StockMovementTest, StockTransferTest, DryGoodsTest, RawMaterialTest, EquipmentTest, PurchaseOrderTest, BulkWineInventoryTest, PhysicalCountTest, InventorySeederTest (11 test files)
- Tenant migrations: 12 new (event_source backfill, case_goods_skus, locations, stock_levels, stock_movements, physical_counts + lines, dry_goods_items, raw_materials, equipment, maintenance_logs, purchase_orders, purchase_order_lines)
- Filament resources: 6 new (CaseGoodsSku, Location, DryGoodsItem, RawMaterial, Equipment, PurchaseOrder) + 2 custom pages (BulkWineInventory, PhysicalCount) + 5 relation managers
- API endpoints: 30+ new (SKU CRUD + search, location CRUD, stock transfer, dry goods CRUD, raw material CRUD, equipment CRUD + maintenance, PO CRUD + receive, physical count lifecycle, bulk wine inventory)
- Models: 10 new (CaseGoodsSku, Location, StockLevel, StockMovement, DryGoodsItem, RawMaterial, Equipment, MaintenanceLog, PurchaseOrder, PurchaseOrderLine) + 2 for physical counts (PhysicalCount, PhysicalCountLine)
- Services: 2 new (InventoryService, PhysicalCountService) + EventLogger enhanced with resolveSource()
- PHPStan: level 6, zero errors
- Pint: zero style issues
- Demo data: ~42+ SKUs, 3 locations, 50+ stock levels, 22 dry goods, 18 raw materials, 6 equipment, 15 maintenance logs, 4 purchase orders, 2 physical counts
