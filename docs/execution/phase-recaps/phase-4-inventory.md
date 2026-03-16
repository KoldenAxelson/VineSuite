# Phase 4 Recap — Inventory Management

> Duration: 2026-03-15
> Task files: `04-inventory.md` | INFO: `04-inventory.info.md`

---

## Delivered

- Complete case goods (bottled wine) inventory: SKU registry with Meilisearch full-text search, multi-location stock levels (on_hand/committed/available), append-only stock movement ledger, inter-location transfers with auto from/to movement pairing
- Bulk wine inventory view: aggregates lot volume data into Filament page with total gallons, vessel counts, lot status filtering
- Dry goods and raw materials registries: packaging/cellar supplies, reorder point alerting, vendor tracking, expiration dates, cost-per-unit (feeds COGS)
- Equipment registry: maintenance log tracking (cleaning, calibration, inspection, repair, preventive), next-due-date scheduling, pass/fail results, cost tracking
- Procurement workflow: POs with draft/submitted/partial/received/cancelled lifecycle, line items for dry goods and raw materials, partial receiving with auto status transitions, inventory updates on receipt
- Physical inventory counts: location-scoped sessions that snapshot system quantities, record actual counts, compute variances, and on approval generate stock_adjusted movements
- Comprehensive demo dataset: 40+ SKUs (3 locations), 22 dry goods, 18 raw materials, 6 equipment with 15 maintenance logs, 4 POs (all statuses), 2 physical counts (completed with variances + in-progress)
- Cross-linked Filament UI: SKU↔Location bidirectional visibility, PO history on item views, equipment maintenance history, physical count drill-downs, vintage=0 as "NV"

---

## Architecture Decisions

### Event Source Partitioning
Added `event_source` column with auto-resolution from `operation_type` prefix via `EventLogger::resolveSource()`. Maps `stock_/purchase_/equipment_/dry_goods_/raw_material_` → `inventory`. Enables module-level event filtering. Migration temporarily disables immutability trigger for backfill.

### Scout Search with Eloquent Fallback
CaseGoodsSku uses `search($query)->keys()` to get Meilisearch IDs, then composes Eloquent `whereIn()` queries for filtering/pagination. Avoids PHPStan type issues with Scout's paginator interface.

### Row-Level Locking for Stock Operations
`InventoryService` uses `lockForUpdate()` on StockLevel rows within transactions. Prevents race conditions on concurrent modifications. All stock ops (receive, sell, adjust, transfer) go through service, never direct model updates.

### Signed Quantities for Stock Movements
Movements store signed quantities: positive for inbound (received, transferred_in, adjusted up), negative for outbound (sold, transferred_out, adjusted down). Movement ledger self-consistent — summing all movements for SKU+location = current on_hand.

### Manual Polymorphism for PO Lines
`PurchaseOrderLine` uses `item_type` + `item_id` columns (not Laravel morph relationships). Keeps schema explicit and queryable, matches Event model's `entity_type` + `entity_id` pattern.

### Physical Count as Workflow (not CRUD)
Managed through `PhysicalCountService` with explicit lifecycle methods (`startCount`, `recordCounts`, `approve`, `cancel`). Approval triggers `InventoryService::adjust()` for each variance, creating auditable stock_adjusted movements.

### Vintage=0 Sentinel for Non-Vintage Items
`case_goods_skus.vintage` is `unsignedSmallInteger NOT NULL`. Non-vintage items (sparkling, merchandise) use `0` sentinel (not nullable). Filament tables format `0` as "NV" via `formatStateUsing()`.

---

## Deviations from Spec

- **Auto-deduction from production deferred:** Spec called for automatic dry goods deduction (bottling completion) and raw material deduction (addition recording). Deferred — requires production module modifications and mapping layer. `InventoryService` methods exist but hooks not wired.
- **Low stock alert system deferred:** `reorder_point` field and `belowReorderPoint()` scope exist, "Below Reorder Point" filter in Filament, but notification/email system not built. Deferred to notifications phase.
- **Physical count quantity is integer:** Spec implied decimals. Implementation uses whole cases/bottles (correct for case goods).
- **3 locations instead of 2:** Demo creates Tasting Room, Back Stock Warehouse, Offsite Warehouse for more realistic demos.
- **Sub-Task 12 (impromptu polish):** Not in spec. Added cross-linked relation managers, physical count drill-down, vintage NV display, cancel() event logging fix.

---

## Patterns Established

### InventoryService as Single Entry Point
All stock-modifying operations go through `InventoryService` methods (`receive`, `sell`, `adjust`, `transfer`). Each wraps operation in transaction with `lockForUpdate()`, creates StockMovement, updates StockLevel, logs event. Never modify StockLevel directly.

### Bidirectional Filament RelationManagers
When two models have many-to-many-like relationship through intermediate table (StockLevel bridges SKU↔Location), create RelationManagers on both sides. Pattern: `StockLevelsRelationManager` on both `CaseGoodsSkuResource` and `LocationResource`.

### Filtered HasMany for Manual Polymorphism
Models define HasMany with `->where('item_type', 'value')` filter:
```php
public function purchaseOrderLines(): HasMany
{
    return $this->hasMany(PurchaseOrderLine::class, 'item_id')
        ->where('item_type', 'dry_goods');
}
```

### Presentation-Layer Sentinel Formatting
Sentinels (vintage=0 → "NV") formatted via `formatStateUsing()` in Filament. No model accessors — keeps data layer clean, formatting explicit at view layer.

### Schema::hasTable Guards on Dynamic Filament Filters
Dynamic filter dropdowns that query newer migration tables guarded with `Schema::hasTable()` to prevent crashes on tenants not yet migrated.

### Globally Unique Test Helper Names
Pest loads all test files flat. Helper functions inside tests must be globally unique. Convention: prefix with module ID (e.g., `seedAndGetInventoryTenant()` not `createTenant()`).

---

## Known Debt

1. **Auto-deduction not wired** — impact: medium — affects Phase 5 (cost accounting). `InventoryService` methods exist but hooks into BottlingRun/Addition not implemented.
2. **Low stock alert notifications** — impact: low — fields and scopes exist, notification system not built. Deferred to Phase 18.
3. **No Filament Livewire CRUD tests** — impact: low — carried from Phase 1-2 audit.
4. **Token ability endpoint enforcement** — impact: low — carried from Phase 1-2 audit.
5. **Dashboard has no widgets** — impact: medium — placeholder Dashboard.php. Should show low stock alerts, recent PO status, count in progress.
6. **CSV import partial failure handling** — impact: low — carried from Phase 3. Lab CSV rolls back entire batch.
7. **`confirmMlDryness()` API endpoint** — impact: low — carried from Phase 3.

---

## Reference Docs Updated

- `references/event-log.md` — Updated with 16 Phase 4 event types (4 categories: stock movements, physical counts, purchase orders, item registries). Event source partitioning documented.
- `references/test-groups.md` — Updated inventory group from TBD to ~200+ tests (11 test files).
- `diagrams/inventory-erd.mermaid` — **Created** — Entity-relationship diagram for all Phase 4 models.

---

## Metrics

| Metric | Value |
|--------|-------|
| Sub-tasks | 12/12 (11 planned + 1 polish) |
| Tests | ~680+ total (~200+ new in Phase 4) |
| Test files | 11 (CaseGoodsSkuTest, LocationStockLevelTest, StockMovementTest, StockTransferTest, DryGoodsTest, RawMaterialTest, EquipmentTest, PurchaseOrderTest, BulkWineInventoryTest, PhysicalCountTest, InventorySeederTest) |
| Tenant migrations | 12 new |
| Filament resources | 6 new + 2 custom pages + 5 relation managers |
| API endpoints | 30+ new |
| Models | 10 new + 2 for physical counts |
| Services | 2 new (InventoryService, PhysicalCountService) + EventLogger enhanced |
| PHPStan level 6 | 0 errors |
| Pint | 0 style issues |
| Demo data | ~42 SKUs, 3 locations, 50+ stock levels, 22 dry goods, 18 raw materials, 6 equipment, 15 maintenance logs, 4 POs, 2 physical counts |
