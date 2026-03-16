# Inventory Management

## Phase
Phase 2

## Dependencies
- `01-foundation.md` — event log, auth, Filament
- `02-production-core.md` — Lot and Vessel models (bulk wine inventory derives from lot/vessel data), BottlingRun (creates case goods)

## Goal
Track all winery inventory across four categories: bulk wine (gallons in lots/vessels), case goods (bottled wine SKUs), dry goods/packaging materials (bottles, corks, labels), and raw materials/cellar supplies (additives, yeast, fining agents). Inventory auto-deducts from production operations (additions deduct raw materials, bottling deducts dry goods and creates case goods, POS sales deduct case goods). This module is the bridge between production and sales.

## Data Models

- **CaseGoodsSku** — `id` (UUID), `wine_name`, `vintage`, `varietal`, `format` (750ml/375ml/1.5L/etc), `case_size` (6/12), `upc_barcode`, `price` (default retail price), `cost_per_bottle` (from COGS), `is_active`, `image_path`, `tasting_notes`, `tech_sheet_path`, `created_at`, `updated_at`
  - Relationships: hasMany StockLevels, hasMany StockMovements, belongsTo Lot (origin lot)

- **StockLevel** — `id`, `sku_id`, `location_id`, `on_hand` (integer), `committed` (integer — allocated to unfulfilled orders), `available` (computed: on_hand - committed), `updated_at`
  - Relationships: belongsTo CaseGoodsSku, belongsTo Location

- **Location** — `id`, `name` (tasting_room_floor/back_stock/offsite_warehouse/3pl), `address`, `is_active`, `created_at`

- **StockMovement** — `id` (UUID), `sku_id`, `location_id`, `movement_type` (received/sold/transferred/adjusted/returned/bottled), `quantity` (positive=in, negative=out), `reference_type` (order/bottling_run/transfer/adjustment), `reference_id`, `performed_by`, `performed_at`, `notes`, `created_at`

- **DryGoodsItem** — `id` (UUID), `name`, `item_type` (bottle/cork/screw_cap/capsule/label_front/label_back/label_neck/carton/divider/tissue), `unit_of_measure` (each/sleeve/pallet), `on_hand` (decimal), `reorder_point`, `cost_per_unit` (decimal), `vendor_id` (nullable), `notes`, `created_at`, `updated_at`

- **RawMaterial** — `id` (UUID), `name`, `category` (additive/yeast/nutrient/fining_agent/acid/enzyme/oak_alternative), `unit_of_measure` (g/kg/L/each), `on_hand` (decimal), `reorder_point`, `cost_per_unit` (decimal), `expiration_date` (nullable), `vendor_id` (nullable), `notes`, `created_at`, `updated_at`

- **Equipment** — `id` (UUID), `name`, `type`, `serial_number`, `purchase_date`, `value` (decimal), `location`, `notes`, `created_at`, `updated_at`
  - Relationships: hasMany MaintenanceLogs

- **MaintenanceLog** — `id`, `equipment_id`, `maintenance_type` (cleaning/calibration/repair/inspection), `date`, `performed_by`, `notes`, `next_due_date`, `created_at`

- **PurchaseOrder** — `id` (UUID), `vendor_name`, `vendor_id` (nullable), `order_date`, `expected_date`, `status` (ordered/partial/received/cancelled), `total_cost`, `notes`, `created_at`, `updated_at`
  - Relationships: hasMany PurchaseOrderLines

- **PurchaseOrderLine** — `id`, `purchase_order_id`, `item_type` (dry_goods/raw_material), `item_id`, `quantity_ordered`, `quantity_received`, `cost_per_unit`

## Sub-Tasks

### 1. Case goods SKU registry
**Description:** Create the SKU model and management UI. SKUs represent bottled wine products available for sale.
**Files to create:**
- `api/app/Models/CaseGoodsSku.php`
- `api/database/migrations/xxxx_create_case_goods_skus_table.php`
- `api/app/Http/Controllers/Api/V1/CaseGoodsSkuController.php`
- `api/app/Http/Resources/CaseGoodsSkuResource.php`
- `api/app/Filament/Resources/CaseGoodsSkuResource.php`
**Acceptance criteria:**
- SKUs created with: wine name, vintage, varietal, format, case size, UPC, price
- SKU list filterable by vintage, varietal, format, active status
- Image upload for product photos
- Tech sheet PDF attachment
- SKU search via Meilisearch
**Gotchas:** SKUs can be auto-created from bottling runs (02-production-core.md sub-task 11). Also support manual creation for purchased finished wine.

### 2. Location and stock level tracking
**Description:** Implement multi-location stock tracking for case goods. Each SKU has separate stock levels per location.
**Files to create:**
- `api/app/Models/Location.php`
- `api/app/Models/StockLevel.php`
- `api/database/migrations/xxxx_create_locations_table.php`
- `api/database/migrations/xxxx_create_stock_levels_table.php`
- `api/app/Filament/Resources/LocationResource.php`
**Acceptance criteria:**
- Multiple locations (tasting room floor, back stock, offsite, 3PL)
- Per-SKU per-location stock levels: on_hand, committed, available
- Available = on_hand - committed (calculated field)
- Stock levels update atomically on movements
**Gotchas:** Committed quantity increases when an order is placed, decreases when shipped or cancelled. Available must never go negative in the UI (warn but don't hard-block — overselling happens in winery tasting rooms).

### 3. Stock movement logging
**Description:** Create the stock movement ledger. Every change to stock levels is recorded as a movement entry for audit trail.
**Files to create:**
- `api/app/Models/StockMovement.php`
- `api/database/migrations/xxxx_create_stock_movements_table.php`
- `api/app/Services/InventoryService.php` — handles all stock changes via movements
**Acceptance criteria:**
- All stock changes go through InventoryService (never direct StockLevel updates)
- Movements record: SKU, location, type, quantity, reference to source (order, bottling run, etc.)
- Positive quantities = stock in, negative = stock out
- Writes `stock_received`, `stock_adjusted`, `stock_transferred`, or `stock_counted` events
**Gotchas:** Use database-level locking on StockLevel rows during movements to prevent race conditions (concurrent POS sales). SELECT FOR UPDATE pattern.

### 4. Physical inventory count tool
**Description:** Build a physical inventory count workflow — enter actual counts, compare to system, generate variance report.
**Files to create:**
- `api/app/Services/PhysicalCountService.php`
- `api/app/Http/Controllers/Api/V1/PhysicalCountController.php`
- `api/app/Filament/Pages/PhysicalCount.php` (custom Filament page)
**Acceptance criteria:**
- Start a count session for a location
- Enter actual quantities per SKU (scan barcode or manual)
- Variance report: system vs. actual, per SKU
- Approve adjustments → writes stock_adjusted movements for each variance
- Count history retained for auditing
**Gotchas:** Physical counts should be for one location at a time. Don't auto-adjust — show variances and let the user approve.

### 5. Stock transfer between locations
**Description:** Move case goods from one location to another (e.g., back stock → tasting room floor).
**Files to create:**
- `api/app/Http/Controllers/Api/V1/StockTransferController.php`
**Acceptance criteria:**
- Transfer N units of SKU from location A to location B
- Source location decreases, target location increases
- Writes `stock_transferred` event
- Cannot transfer more than available at source
**Gotchas:** Simple point-to-point transfer. No in-transit state needed for v1.

### 6. Dry goods and packaging materials
**Description:** Track packaging materials (bottles, corks, capsules, labels, cartons). Auto-deduct on bottling run completion.
**Files to create:**
- `api/app/Models/DryGoodsItem.php`
- `api/database/migrations/xxxx_create_dry_goods_items_table.php`
- `api/app/Http/Controllers/Api/V1/DryGoodsController.php`
- `api/app/Filament/Resources/DryGoodsItemResource.php`
**Acceptance criteria:**
- CRUD for dry goods items with types, units, stock levels, reorder points
- Receive PO: add quantity to stock with cost per unit
- Auto-deduct on bottling run completion (when bottling module triggers it)
- Reorder alerts when stock falls below reorder point
- Vendor linkage per item
**Gotchas:** Units vary widely (bottles by each, corks by sleeve of 1000, capsules by bag of 500). Store quantities in the item's native unit.

### 7. Raw materials and cellar supplies
**Description:** Track cellar additives (SO2, yeast, nutrients, fining agents, acids, enzymes). Auto-deduct when additions are logged in the production module.
**Files to create:**
- `api/app/Models/RawMaterial.php`
- `api/database/migrations/xxxx_create_raw_materials_table.php`
- `api/app/Http/Controllers/Api/V1/RawMaterialController.php`
- `api/app/Filament/Resources/RawMaterialResource.php`
**Acceptance criteria:**
- CRUD for raw materials with categories, units, stock, reorder points
- Auto-deduct when additions logged (AdditionService triggers deduction)
- Expiration date tracking with alerts for expired items
- Cost per unit stored (feeds into COGS)
- Reorder alerts
**Gotchas:** This is where the additions auto-deduct from. The AdditionService in 02-production-core.md calls InventoryService.deductRawMaterial(). Wire this up now.

### 8. Equipment and maintenance tracking
**Description:** Basic equipment register with maintenance logs — required for compliance audits (especially CIP records, calibration).
**Files to create:**
- `api/app/Models/Equipment.php`
- `api/app/Models/MaintenanceLog.php`
- `api/database/migrations/xxxx_create_equipment_table.php`
- `api/database/migrations/xxxx_create_maintenance_logs_table.php`
- `api/app/Filament/Resources/EquipmentResource.php`
**Acceptance criteria:**
- Equipment register: name, type, serial number, purchase date, value
- Maintenance logs: cleaning, calibration, repair with dates and notes
- Next maintenance due date tracking
- Calibration records for lab equipment
**Gotchas:** CIP (Clean In Place) records are often requested during audits. Keep maintenance log entries simple but complete.

### 9. Bulk wine inventory view
**Description:** Create a consolidated view of bulk wine inventory — real-time gallons by lot, by vessel, by location. This is a read-only aggregation of lot/vessel data, not a separate data store.
**Files to create:**
- `api/app/Filament/Pages/BulkWineInventory.php` (custom Filament page)
- `api/app/Http/Controllers/Api/V1/BulkWineInventoryController.php`
**Acceptance criteria:**
- Real-time gallons by lot, by vessel, by location
- Volume reconciliation: book value (from events) vs. sum of current vessel contents
- Bulk wine aging schedule (projected bottling dates if configured)
- Bulk wine purchases/sales recording
**Gotchas:** Bulk wine inventory is derived from the lot_vessel pivot and lot volumes — do NOT duplicate this data. This view queries existing data.

### 10. Purchase order system
**Description:** Simple PO tracking for ordering dry goods and raw materials from vendors.
**Files to create:**
- `api/app/Models/PurchaseOrder.php`
- `api/app/Models/PurchaseOrderLine.php`
- `api/database/migrations/xxxx_create_purchase_orders_table.php`
- `api/database/migrations/xxxx_create_purchase_order_lines_table.php`
- `api/app/Filament/Resources/PurchaseOrderResource.php`
**Acceptance criteria:**
- Create PO with vendor, items, quantities, expected costs
- Receive PO (full or partial) — updates stock levels
- PO status tracking: ordered, partial, received, cancelled
- Cost per unit captured on receipt (for COGS)
**Gotchas:** Keep it simple — this is not a full procurement system. Just enough to track what was ordered and received.

### 11. Inventory demo seeder
**Description:** Extend demo seeder with realistic inventory data.
**Files to modify:**
- `api/database/seeders/InventorySeeder.php`
**Acceptance criteria:**
- Demo winery has: 47 case goods SKUs, stock levels across 2 locations, common dry goods and raw materials with realistic quantities, 5-6 pieces of equipment with maintenance history
**Gotchas:** Case goods SKUs should correspond to demo lots that have been "bottled" in the event log.

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/skus` | List case goods SKUs | Authenticated |
| POST | `/api/v1/skus` | Create SKU | winemaker+ |
| GET | `/api/v1/skus/{sku}` | Get SKU detail with stock levels | Authenticated |
| GET | `/api/v1/skus/{sku}/stock` | Get stock levels per location | Authenticated |
| POST | `/api/v1/stock/receive` | Receive stock (from bottling or purchase) | winemaker+ |
| POST | `/api/v1/stock/transfer` | Transfer between locations | Authenticated |
| POST | `/api/v1/stock/adjust` | Manual adjustment | winemaker+ |
| POST | `/api/v1/stock/count` | Physical inventory count | winemaker+ |
| GET | `/api/v1/dry-goods` | List dry goods | Authenticated |
| POST | `/api/v1/dry-goods` | Create dry goods item | admin+ |
| GET | `/api/v1/raw-materials` | List raw materials | Authenticated |
| POST | `/api/v1/raw-materials` | Create raw material | admin+ |
| GET | `/api/v1/bulk-wine` | Bulk wine inventory summary | Authenticated |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `stock_received` | sku_id, location_id, quantity, source, cost_per_unit | stock_levels, stock_movements |
| `stock_adjusted` | sku_id, location_id, old_qty, new_qty, reason | stock_levels, stock_movements |
| `stock_transferred` | sku_id, from_location, to_location, quantity | stock_levels (both), stock_movements |
| `stock_counted` | location_id, counts [{sku_id, system_qty, actual_qty}] | stock_levels, stock_movements |

## Testing Notes
- **Unit tests:** Stock level calculations (available = on_hand - committed), movement ledger consistency, reorder alert triggers, auto-deduct calculations
- **Integration tests:** Bottling → case goods creation → stock level update. Addition → raw material deduction. Full physical count → variance → adjustment flow.
- **Critical:** Race condition testing — simulate concurrent POS sales decrementing the same SKU at the same location. StockLevel updates must be atomic.
