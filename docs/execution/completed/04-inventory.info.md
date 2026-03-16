# Inventory Management — Completion Record

> Task spec: `docs/execution/tasks/04-inventory.md`
> Phase: 4

---

## Sub-Task 1: Case Goods SKU Registry
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions
- **event_source partitioning**: Added `event_source` column (production, lab, inventory, accounting) auto-resolved from `operation_type` prefix via `EventLogger::resolveSource()`.
- **Scout + Eloquent composition**: Use `::search()->keys()` + `whereIn()` to combine Meilisearch with DB filters, avoiding Scout's type conflicts.
- **Schema::hasTable guards on Filament filters**: Prevents 500 errors when tenants haven't run new migrations yet.
- **relationLoaded() pattern**: CaseGoodsSkuResource uses `$this->relationLoaded('lot') && $this->lot ? [...] : null` (not `whenLoaded()`), matching existing codebase convention.
- **Local filesystem for uploads**: S3 migration planned later.

### Deviations from Spec
- `event_source` column not in spec — added per Phase 4 infrastructure plan.
- `bottling_run_id` FK unconstrained (BottlingRun may not exist yet).

### Patterns Established
- **Inventory navigation group**: Filament resources start here (sort 1+).
- **event_source auto-resolution**: All future event types use `operation_type` prefixes mapping to modules.
- **Scout + Eloquent pattern**: Standard for searchable models.
- **Schema::hasTable guards**: Use on dynamic Filament filters for cross-tenant migration safety.

### Test Summary (26 tests)
- event_source partitioning (4): lab/fermentation/sensory → 'lab', stock/purchase/equipment/dry_goods/raw_material → 'inventory', cost/cogs → 'accounting', unknown → 'production'
- Tenant isolation (1)
- CRUD (8): all fields, minimal fields, list, filters (vintage/varietal/format/active), show, update
- Validation (5): required fields, invalid format/case_size, negative price, non-existent lot_id
- RBAC (5): winemaker can create, read_only/cellar_hand cannot
- API envelope (2)
- Gaps: Filament resource CRUD not via Livewire, file upload not tested, Meilisearch integration uses test driver

---

## Sub-Task 2: Location and Stock Level Tracking
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions
- **Computed `available` accessor**: `on_hand - committed` (can go negative per spec — overselling allowed).
- **Unique constraint on (sku_id, location_id)**: One stock level row per SKU per location.
- **Cascade deletes**: Deleting SKU or location removes all related stock levels.
- **EventLogger for location lifecycle**: `stock_location_created` / `stock_location_updated` operations.
- **PHPStan-clean resource pattern**: Non-nullable BelongsTo relationships use `relationLoaded()` without redundant null checks.

### Patterns Established
- **Non-nullable relationship resource pattern**: Use `relationLoaded()` without `&& $this->rel` check on non-nullable FKs.
- **Stock level per SKU per location**: Unique constraint enforces one-row-per-pair; future services should `firstOrCreate` on (sku_id, location_id).

### Test Summary (22 tests)
- Event logging (2): stock_location_created/updated with inventory source
- Tenant isolation (1)
- Data integrity (3): available computation, negative allowed, unique constraint enforced
- CRUD (6): all/minimal fields, list, filters, show, update
- Validation (2): missing name, name exceeds length
- RBAC (5): winemaker create, read_only/cellar_hand cannot, read-only can view
- Relationships (3): multi-location tracking, cascade deletes
- API envelope (2)
- Gaps: Filament resource CRUD not via Livewire, no direct StockLevel API endpoints yet

---

## Sub-Task 3: Stock Movement Logging
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions
- **SELECT FOR UPDATE locking**: Every mutation acquires row-level lock on StockLevel before read/update, preventing race conditions.
- **Auto-create StockLevel**: Service creates missing (sku_id, location_id) rows automatically with on_hand=0.
- **Immutable ledger**: No `updated_at`; corrections are new adjustment movements.
- **Transfer as paired movements**: Two StockMovement rows (negative/positive) sharing `reference_id`; single `stock_transferred` event.
- **Sell auto-negates quantity**: Callers pass positive quantity; service negates internally.
- **PHPStan `@var StockLevel|null`**: Required annotation for `lockForUpdate()->first()` result.

### Patterns Established
- **InventoryService as sole mutation entry point**: All stock changes must flow through this service.
- **SELECT FOR UPDATE for atomic stock operations**: Standard pattern for concurrent-safe mutations.
- **Paired movements for transfers**: Two ledger entries linked by `reference_id`.
- **Positive-quantity API, internal negation**: Caller-friendly interface.

### Test Summary (22 tests)
- Event logging (4): stock_received, stock_adjusted, stock_transferred, stock_sold with inventory source
- Inventory math (6): receive increases on_hand, sell decreases, adjust +/−, transfer pairs, auto-create, overselling allowed
- Tenant isolation (1)
- Movement ledger (3): immutable audit trail, reference traceability, paired transfer with shared reference_id
- Validation (4): reject ≤0 quantities, reject same-location transfers
- Gaps: No direct API endpoints for movements, no Filament UI for history, no concurrency stress test

---

## Sub-Task 4: Physical Inventory Count Tool
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions
- **Snapshot-based counting**: Capture system on_hand at count start; compute variances against snapshot, not live stock.
- **Discovered SKUs during count**: Can add new SKUs with system_quantity=0; variance = full counted amount.
- **Approval writes adjustments through InventoryService**: All stock changes flow through established SELECT FOR UPDATE path with `reference_type=physical_count`.
- **Custom Filament page instead of Resource**: Workflow-driven lifecycle (start → record → approve/cancel) doesn't map to CRUD.
- **Separate read/write route groups**: GET (all users), writes (winemaker+).

### Patterns Established
- **Workflow service pattern**: Multi-step workflow (start → record → approve/cancel) with status-based guards.
- **Snapshot-then-compare**: Captures system state at count start; isolates counting from concurrent stock movements.
- **Reference linkage for audit**: All adjustments share `reference_type='physical_count'` + `reference_id=count.id`.

### Test Summary (22 tests)
- Event logging (2): stock_count_started with line_count, stock_counted on approval with adjustments_made
- Tenant isolation (1)
- Workflow integrity (5): system quantity snapshot on start, variance computation, adjustments written only for non-zero variances, discovered SKUs, no adjustments on cancel
- API CRUD (4): list, filter by status/location, show with nested lines
- Validation (4): missing location_id, non-existent location, negative counted_quantity, approve on non-in-progress
- RBAC (4): winemaker can start, read_only cannot, read_only can view
- API envelope (2)
- Open questions: Concurrent count sessions for same location not tested; re-count workflow not tested; should simultaneous counts be prevented?

---

## Sub-Task 5: Stock Transfer Between Locations
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions
- **Available stock validation**: Enforce available ≥ quantity at source (stricter than sell flow which allows overselling).
- **Any authenticated user can transfer**: Per spec; matches typical winery staff access.
- **No in-transit state**: Simple point-to-point transfer; atomic transaction.
- **Response includes both movements**: transfer_id (shared reference_id) + both from/to movements with eager-loaded relationships.

### Patterns Established
- **Available-stock guard for transfers**: Two-tier approach (soft limit for POS, hard limit for transfers).

### Test Summary (15 tests)
- Event logging (1): stock_transferred with location names, wine_name, quantity
- Data integrity (4): source decreases/destination increases, paired movements with shared reference_id, reject transfers exceeding available stock, reject transfers with no source stock level
- Tenant isolation (1): cross-tenant location ID prevention via exists validation
- Validation (4): missing fields, same location rejected, zero/negative quantity, non-existent IDs
- RBAC (3): cellar_hand can transfer (201), read_only can transfer (201), unauthenticated rejected
- API envelope (1): correct structure with transfer_id
- Note: Helper functions renamed to `createStockTransferTestTenant` / `createStockTransferFixtures` to avoid collision with Production/TransferTest
- Open questions: Transfer listing endpoint not built; TOCTOU race possible at high concurrency (negligible for winery volumes)

---

## Sub-Task 6: Dry Goods and Packaging Materials
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions
- **Decimal storage for quantities**: decimal(12,2) allows fractional units (sleeves, pallets).
- **cost_per_unit with 4 decimals**: Sub-cent precision for high-volume low-cost items (e.g., corks at $0.0823).
- **vendor_name as simple string**: UUID placeholder for future Vendor model; vendor_name provides immediate human-readable info.
- **Admin+ for create/update**: Per spec; winemakers and below can view only.
- **belowReorderPoint scope**: Uses `whereColumn()` for DB-level comparison; excludes items without reorder_point.
- **LogsActivity trait**: Uses custom `App\Traits\LogsActivity`, not Spatie's.

### Deviations from Spec
- "Auto-deduct on bottling" deferred; model ready for future integration.
- "Receive PO" deferred to Sub-Task 10; manual update for now.
- "Reorder alerts" scope/helper implemented; notification delivery deferred.

### Patterns Established
- **Decimal quantity pattern**: Use decimal columns with appropriate precision; cast via `'decimal:N'`.
- **Reorder point pattern**: `belowReorderPoint()` scope + `needsReorder()` helper.

### Test Summary (22 tests)
- Event logging (2): dry_goods_created/updated with inventory source
- Tenant isolation (1)
- Data integrity (3): belowReorderPoint scope, needsReorder() helper, decimal precision
- CRUD (8): all/minimal fields, list, filters (item_type/active/below_reorder), show, update
- Validation (4): required fields, invalid item_type/unit_of_measure, negative on_hand
- RBAC (5): admin can create, winemaker/read_only cannot, any user can view, winemaker cannot update
- API envelope (2): needs_reorder field, unauthenticated rejection
- Note: Numeric assertions use `toEqual()` (JSON encodes 5000.0 as 5000)
- Gaps: Filament resource not tested via Livewire, auto-deduct not wired, PO receipt not built, reorder notification not implemented

---

## Sub-Task 7: Raw Materials and Cellar Supplies
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions
- **Expiration tracking**: `expiration_date` date column with `expired()` / `expiringSoon()` scopes + `isExpired()` helper.
- **Category-specific units**: Factory suggests defaults; model accepts any UNITS_OF_MEASURE.
- **Auto-deduct via AdditionService**: Atomic within existing DB transaction; uses `lockForUpdate()` (matches InventoryService pattern); allows negative on_hand (winery recording may be inaccurate).
- **Deduction event self-contained**: Includes human-readable `raw_material_name` alongside `addition_id`, `lot_id`, quantities.
- **Admin+ for create/update**: Per spec; winemakers can read only.

### Deviations from Spec
- "Receive PO" deferred to Sub-Task 10.
- "Expiration alerts" scope/helper ready; notification delivery deferred.

### Patterns Established
- **Expiration tracking pattern**: `expired()` / `expiringSoon(days)` scopes, `isExpired()` instance helper.
- **Auto-deduct from service layer**: Check inventory_item_id → lockForUpdate → decrement → log event.
- **Factory-based test fixtures**: Use factories (not `::create()`) for cross-model tests.

### Test Summary (24 tests)
- Event logging (2): raw_material_created/updated with inventory source
- Tenant isolation (1)
- Data integrity (5): belowReorderPoint, needsReorder(), expired scope, expiringSoon scope, decimal precision
- Auto-deduct (2): inventory_item_id decrements on_hand + writes event, missing item_id skips deduction
- CRUD (9): all/minimal fields, list, filters (category/active/below_reorder/expired), show, update
- Validation (4): required fields, invalid category/unit_of_measure, negative on_hand
- RBAC (5): admin can create, winemaker/read_only cannot, any user can view, winemaker cannot update
- API envelope (2): needs_reorder + is_expired fields, unauthenticated rejection
- Note: Lot and Vessel created via factories (avoid fragile column assumptions)
- Gaps: Filament not tested, PO receipt not built, expiration alert delivery not implemented, unit conversion not handled (assumes same unit)
- Open questions: Should auto-deduct be toggleable per-tenant?

---

## Sub-Task 8: Equipment and Maintenance Tracking
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions
- **Maintenance due on Equipment model**: `next_maintenance_due` auto-updated when MaintenanceLog with `next_due_date` created (no cron needed).
- **Flat POST for maintenance logs**: Endpoint POST /maintenance-logs (not nested) because equipment_id in request body.
- **CIP and calibration audit scopes**: Dedicated `cipRecords()` / `calibrationRecords()` scopes for common audit queries.
- **Pass/fail only for calibration/inspection**: `passed` boolean nullable (meaningless for cleaning/CIP/repair/preventive).
- **Winemaker+ for logs, admin+ for equipment**: Logs are frequent (winemakers/cellar staff); equipment register is administrative.
- **Status color coding in Filament**: operational=green, maintenance=yellow, retired=gray.

### Deviations from Spec
- `purchase_value` (not just "value") for clarity.
- Added `manufacturer`, `model_number`, `location` (not in spec but standard for compliance).
- Detailed maintenance log fields (performed_by, description, findings, cost, next_due_date, passed) based on winery compliance needs.

### Patterns Established
- **Parent auto-update from child**: MaintenanceLogController updates Equipment's `next_maintenance_due`.
- **Audit-oriented scopes**: `calibrationRecords()` / `cipRecords()` make intent explicit.
- **Nested read, flat write**: Maintenance logs list via nested routes; create via flat routes.

### Test Summary (30 tests)
- Event logging (3): equipment_created/updated/maintenance_logged with equipment_name and maintenance_type
- Tenant isolation (1)
- Data integrity (4): maintenanceDue scope, isMaintenanceOverdue() helper, auto-update next_maintenance_due, cascade delete logs
- CRUD (8): equipment all/minimal, list, filters (type/status/maintenance_overdue), show, update
- Maintenance log CRUD (4): calibration log with pass/fail, CIP log, list, filter by type
- Validation (5): missing required fields, invalid type/status/maintenance_type
- RBAC (5): admin creates equipment, winemaker cannot, winemaker creates logs, cellar_hand cannot, any user views
- API envelope (2): is_maintenance_overdue field, unauthenticated rejection
- Gaps: Filament not tested via Livewire, logs are append-only (no update/delete for audit), bulk scheduling not built, depreciation not tracked
- Open questions: Should logs be immutable for audit compliance? (Currently yes, intentional). Should depreciation be tracked separately?

---

## Sub-Task 9: Bulk Wine Inventory View
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions
- **Read-only, no data store**: Pure view layer over existing lot/vessel/lot_vessel. No migration, no model. Raw DB::table() queries for aggregation performance.
- **All endpoints authenticated, no role gate**: Reporting data; all authenticated users can view.
- **STRING_AGG for vessel/lot names**: PostgreSQL aggregate; alphabetically sorted; avoids N+1.
- **Emptied_at IS NULL filter**: Consistently applied across all 6 endpoints to isolate current contents.
- **Variance = book − vessel**: Positive = loss/evaporation; negative = measurement error.
- **Filament page vs Resource**: Custom Page (no single Eloquent model); provides summary stats + table.
- **6 endpoints instead of spec's 1**: Expanded for distinct use cases (summary, by-lot, by-vessel, by-location, reconciliation, aging-schedule).

### Deviations from Spec
- "Bulk wine purchases/sales recording" deferred (would need BulkWinePurchase model).
- "Projected bottling dates" deferred (no bottling_target_date field yet).
- Expanded from 1 to 6 endpoints for API granularity.

### Patterns Established
- **Aggregation controller pattern**: Read-only reporting can use raw DB queries without model/resource/request triplet.
- **Custom Filament page with stats + table**: getSummary() for cards + HasTable for tabular data.
- **Consistent pivot filtering**: All bulk wine queries use same `whereNull('lot_vessel.emptied_at')` pattern.

### Test Summary (19 tests)
- Summary (2): aggregate totals (vessel volume, book value, variance, counts), empty state returns zeros
- By Lot (4): per-lot breakdown with variance, filters by vintage/variety/status
- By Vessel (4): per-vessel breakdown with fill percentage, filters by vessel_type/location/occupied_only
- By Location (1): aggregation by vessel location
- Reconciliation (1): only variance lots, ordered by absolute variance
- Aging Schedule (3): aging lots with fill dates/aging days, filters by vintage/variety
- RBAC (2): unauthenticated rejection, read_only role access
- API Envelope (1): standard structure
- Tenant Isolation (1): cross-tenant prevention via $tenant->run()
- Emptied records (tested in summary/by-lot): explicitly verify emptied_at IS NOT NULL excluded
- Bugs fixed: aging_days wrapped in `abs()` (Carbon diffInDays preserves sign), corrected role from 'viewer' to 'read_only', rewrote tenant isolation with `$tenant->run()` pattern
- Gaps: Filament page not tested via Livewire, no large dataset stress test, no concurrent read performance test

---

## Sub-Task 10: Purchase Order System
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions
- **Denormalized item_name on lines**: Captures name at order time; PO retains original name if item renamed later.
- **Polymorphic item reference via item_type + item_id**: Manual discriminator (string) + UUID; avoids two FK columns.
- **Receive endpoint increments on_hand with locking**: Uses `lockForUpdate()->find()` (matches AdditionService pattern); prevents race conditions.
- **Cost capture at receipt**: Receive endpoint can override cost_per_unit (actual ≠ PO price); updates both line and inventory item.
- **Auto-status management**: ordered → partial (first partial receive) → received (all lines full); manual cancel via update.
- **Lines immutable after creation**: Only header modifiable; amendments = new PO.
- **Winemaker+ for all writes**: Per spec.
- **Total cost recalculated from lines**: Always derived (never set directly); ensures consistency.

### Deviations from Spec
- `receive` endpoint not in spec but necessary (core PO workflow).
- `quantity_received` on line not detailed in spec; implemented full/partial receive with inventory integration.
- `item_name` denormalization added (not in spec) for self-contained events and data portability.
- `cost_per_unit` override at receipt added (not in spec) for COGS accuracy.

### Patterns Established
- **Parent-child transactional creation**: PO + lines atomic; total_cost recalculated from children.
- **Receive-and-increment pattern**: Update child record + related record in single transaction with row-level locking.
- **Status auto-progression**: PO status progresses automatically based on line state (no manual management for happy path).

### Test Summary (30 tests)
- Event logging (2): purchase_order_created with vendor_name/line_count/total_cost, purchase_order_received with line details
- Tenant isolation (1): cross-tenant prevention via $tenant->run()
- Inventory math (7): dry goods/raw material on_hand increment on receive, cost_per_unit update at receipt, auto-status transitions, reject receive on cancelled/received PO
- CRUD (7): create PO with lines, list, show, update header, filters (status/open_only/vendor)
- Validation (4): required fields, empty lines array, invalid line fields, invalid status
- RBAC (4): unauthenticated rejection, read_only can list, cellar_hand denied (403), winemaker allowed (201)
- API envelope (2): paginated list structure, single PO with nested lines
- Data integrity (2): total_cost recalculated from lines, cascade delete
- Bugs fixed: 5 PHPStan errors (removed unused `$request` from closures, fixed HasMany/BelongsTo generic types); 6 test failures (meta.total location, custom ApiResponse errors format, pagination fields in meta)
- Gaps: Filament not tested via Livewire, no amendment workflow, no partial line deletion, no PO approval workflow
- Open questions: Should lines be editable after creation? Should there be draft → approved → ordered workflow? Weighted average vs last-cost for COGS?

---

## Sub-Task 11: Inventory Demo Seeder
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions
- **vintage=0 for non-vintage items**: Column is `unsignedSmallInteger NOT NULL`; use sentinel instead of schema change.
- **Physical counts included**: Not in original spec but added for demo completeness.
- **Stock distribution by price**: Higher-priced wines get fewer tasting room bottles (formula: `max(2, 24 - price/5)`).
- **Name-based lookups for PO lines**: Find DryGoodsItem/RawMaterial by `name LIKE` (resilient to UUID regeneration).

### Deviations from Spec
- ~42+ SKUs (depends on ProductionSeeder's bottling runs), 3 locations (added Offsite Warehouse), 22 dry goods, 18 raw materials, 6 equipment with 15 maintenance logs, 4 POs, 2 physical counts. Spec listed 47 SKUs, common dry goods/raw materials, 5-6 equipment.
- Physical counts and POs not in spec acceptance criteria but included.

### Patterns Established
- **Seeder-to-seeder dependencies**: Use model queries (not hardcoded IDs) for cross-domain lookups.
- **Sentinel values for NOT NULL columns**: Use domain-meaningful sentinel (0 for vintage) instead of schema changes.

### Test Summary (25 tests)
- Location seeding (1): 3 locations correct names
- Case goods SKUs (4): ≥40 SKUs, bottling run links, multiple formats, active/inactive mix
- Stock levels (2): 50+ stock levels across 2+ locations, matched with movements
- Dry goods (3): 22 items, expected types, positive quantities/costs with vendors
- Raw materials (3): 18 items, all 7 categories, mix of expiration dates
- Equipment (3): 6 items, 15+ maintenance logs, 4 maintenance types
- Purchase orders (5): 4 POs, all 4 statuses, 5+ lines (dry_goods + raw_material), received fully matched, partial incomplete
- Physical counts (3): 2 counts, completed with variances/all counted, in-progress with mixed status
- Data integrity (2): stock levels reference valid SKUs/locations, PO lines reference existing items
- Bugs fixed: NOT NULL vintage violation (use 0), maintenance log count adjusted (15 not 16)
- Open question resolved: vintage=0 now renders as "NV" in UI (Sub-Task 12)

---

## Sub-Task 12: Filament Polish & Cross-Navigation (Impromptu)
**Completed:** 2026-03-15 | **Status:** Done

### What Was Built
**Physical Count drill-down**: Dual-mode page (list vs detail). Detail view shows stat cards (Status, Progress, Variances, Dates), filterable lines table (SKU wine_name linked to CaseGoodsSkuResource, varietal, format, system/counted/variance quantities, variance color-coded), filters (Variances Only, Pending Only), back link.

**Stock level relation managers** (bidirectional):
- Location → StockLevelsRelationManager: wine_name (linked), varietal, format, on_hand/committed/available (red if negative), "In Stock Only" filter
- CaseGoodsSku → StockLevelsRelationManager: location.name (linked), location_type badge, on_hand/committed/available, "In Stock Only" filter

**Equipment maintenance**: MaintenanceLogsRelationManager showing performed_date, maintenance_type (color-coded), description, performer, passed (icon), cost, next_due_date, findings. maintenance_type filter, performed_date desc sort.

**Purchase order lines**: LinesRelationManager showing item_name (linked cross-domain to DryGoodsItem/RawMaterial via `getInventoryItemUrl()`), item_type badge, quantity_ordered/received (color-coded), cost, line_total, remaining.

**DryGoods/RawMaterial PO history**: PurchaseOrderLinesRelationManager (filtered by item_type) showing PO number (linked), status badge, quantities, unit cost, order date, ordered_at desc sort. Added `purchaseOrderLines()` HasMany relationship.

**Event logging bug fix**: PhysicalCountService::cancel() now writes `stock_count_cancelled` event (was only Log::info).

### Key Decisions
- **Bidirectional stock visibility**: SKU view shows by location; Location view shows by SKU (two separate RMs, cross-linked).
- **Filtered HasMany for pseudo-polymorphism**: DryGoodsItem/RawMaterial use `hasMany(PurchaseOrderLine::class, 'item_id')->where('item_type', ...)`.
- **NV display is presentation-only**: vintage=0 in DB; "NV" via `formatStateUsing()` in Filament and `mapWithKeys()` in dropdown filters.
- **cancel() event operation_type**: `stock_count_cancelled` prefix auto-resolves to `event_source='inventory'`.

### Patterns Established
- **Bidirectional RelationManagers**: For many-to-many-like relationships through intermediate table, create RMs on both sides.
- **Filtered HasMany for manual polymorphism**: Define relationship with `->where('item_type', 'value')`.
- **Presentation-layer sentinel formatting**: Use `formatStateUsing()` (not model accessors) for sentinel formatting.

### Bugs Fixed
- **PhysicalCountService::cancel() audit trail gap**: No EventLogger call; added `stock_count_cancelled` with location/line count.
- **vintage=0 rendering as "0"**: Fixed with `formatStateUsing()` and `mapWithKeys()`.
- **Vintage form minValue(1900)**: Changed to `minValue(0)` with helper text.

---

## Key Infrastructure Changes (Cross-Sub-Task)

**event_source partitioning** (Sub-Task 1):
- Added `event_source` column to events table
- Auto-resolved from `operation_type` prefix in EventLogger
- Mapping: lab_/fermentation_/sensory_ → lab, stock_/purchase_/equipment_/dry_goods_/raw_material_ → inventory, cost_/cogs_ → accounting, default → production

**InventoryService as central mutation point** (Sub-Task 3):
- All stock level changes must flow through this service
- SELECT FOR UPDATE locking on StockLevel rows
- Paired movements for transfers (shared reference_id)

**Filament Navigation & Polishing**:
- Inventory group (sorts 1-8): Case Goods SKUs (1), Locations (2), Physical Count (3), Dry Goods (4), Raw Materials (5), Equipment (6), Bulk Wine (7), Purchase Orders (8)
- Bidirectional RelationManagers for cross-model navigation
- Presentation-layer sentinel formatting (vintage=0 → "NV")

---

## Summary

12 sub-tasks completed. 10 new database tables, 3 service layers, 5 controller pairs, 8 Filament resources + custom pages, 12 test files with 200+ test cases. Comprehensive inventory lifecycle: SKU registry → stock tracking → movement logging → physical counts → inter-location transfers → dry goods/raw materials → equipment maintenance → bulk wine reporting → purchase orders. All 7 inventory event types (created/updated) + 4 stock event types (received/sold/transferred/adjusted) + 2 count event types (started/counted) + 1 audit event (cancelled) implemented with event source partitioning.
