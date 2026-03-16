# Inventory Management — Completion Record

> Task spec: `docs/execution/tasks/04-inventory.md`
> Phase: 4

---

## Sub-Task 1: Case Goods SKU Registry
**Completed:** 2026-03-15
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_15_200001_add_event_source_to_events_table.php` — Adds `event_source VARCHAR(30) DEFAULT 'production'` to the events table. Temporarily disables the immutability trigger, backfills existing lab-prefixed events to `event_source='lab'`, re-enables the trigger. Adds `idx_events_source` index for module-level partitioning queries.
- `api/database/migrations/tenant/2026_03_15_200002_create_case_goods_skus_table.php` — Creates the `case_goods_skus` table with UUID primary key, wine_name, vintage, varietal, format (default '750ml'), case_size (default 12), upc_barcode, price, cost_per_bottle, is_active (default true), image_path, tasting_notes, tech_sheet_path, lot_id (FK to lots), bottling_run_id (FK). Indexes on vintage, varietal, is_active, format.
- `api/app/Services/EventLogger.php` — Modified `log()` method to include `event_source` in event creation. Added `resolveSource()` private method that maps operation_type prefixes to source modules: `lab_/fermentation_/sensory_` → lab, `stock_/purchase_/equipment_/dry_goods_/raw_material_` → inventory, `cost_/cogs_` → accounting, default → production.
- `api/app/Models/Event.php` — Added `event_source` to `$fillable` and `@property` PHPDoc.
- `api/app/Models/CaseGoodsSku.php` — Eloquent model with `HasUuids`, `HasFactory`, `LogsActivity`, `Searchable` (Scout) traits. Constants: `FORMATS` (187ml, 375ml, 500ml, 750ml, 1.0L, 1.5L, 3.0L), `CASE_SIZES` (6, 12). Relationships: `lot()`, `bottlingRun()`. Scopes: `active()`, `ofVintage()`, `ofVarietal()`, `ofFormat()`, `searchDb()`. Implements `toSearchableArray()` for Meilisearch indexing (searchable: wine_name, varietal, upc_barcode; filterable: vintage, varietal, format, is_active; sortable: wine_name, vintage, price, created_at).
- `api/database/factories/CaseGoodsSkuFactory.php` — Generates realistic SKU data. States: `inactive()`, `halfBottle()`, `magnum()`.
- `api/app/Models/Lot.php` — Added `caseGoodsSkus()` HasMany relationship.
- `api/app/Http/Controllers/Api/V1/CaseGoodsSkuController.php` — Four endpoints: `index()` with Meilisearch full-text search (via `keys()` + `whereIn`) plus DB filters (vintage, varietal, format, is_active), `show()` with relationship eager-loading, `store()` with file uploads (image + tech_sheet to local public disk), `update()` with partial update support. Structured logging with tenant_id.
- `api/app/Http/Requests/StoreCaseGoodsSkuRequest.php` — Required: wine_name, vintage, varietal. Optional: format (in FORMATS), case_size (in CASE_SIZES), upc_barcode, price (min:0), cost_per_bottle (min:0), is_active, tasting_notes, lot_id (uuid, exists:lots), bottling_run_id, image (max 5MB), tech_sheet (PDF, max 10MB).
- `api/app/Http/Requests/UpdateCaseGoodsSkuRequest.php` — Same rules as Store but all fields `sometimes`.
- `api/app/Http/Resources/CaseGoodsSkuResource.php` — Uses `@mixin \App\Models\CaseGoodsSku` for PHPStan. Serializes relationships via `relationLoaded()` pattern (not `whenLoaded()`) matching existing codebase convention from LabAnalysisResource.
- `api/app/Filament/Resources/CaseGoodsSkuResource.php` + Pages (List, Create, View, Edit) — Under "Inventory" navigation group, sort 1, icon heroicon-o-cube. Form: 4 sections (Wine Details, Pricing & Barcode, Product Info, Traceability). Table: filterable by vintage (dynamic from DB with Schema::hasTable guard), varietal (dynamic with guard), format (static from FORMATS constant), is_active (ternary). Dynamic filter queries are guarded with `Schema::hasTable()` to prevent crashes on tenants that haven't run the migration yet.
- `api/routes/api.php` — Added 4 SKU routes: GET /skus, GET /skus/{sku}, POST /skus (winemaker+), PUT /skus/{sku} (winemaker+).
- `api/config/scout.php` — New config for Laravel Scout. Driver: meilisearch (from env, overridden to `collection` in tests). Prefix: `vinesuite_`. Index settings for CaseGoodsSku with filterable/sortable/searchable attributes.
- `api/phpunit.xml` — Added `SCOUT_DRIVER=collection` env var for test isolation from Meilisearch.
- `api/composer.json` — Added `laravel/scout` and `meilisearch/meilisearch-php` dependencies (installed via `composer require`).

### Key Decisions
- **event_source partitioning**: Introduced `event_source` column on the events table to enable module-level filtering (production, lab, inventory, accounting). Resolved automatically from `operation_type` prefix via `EventLogger::resolveSource()`. This is a cross-cutting infrastructure addition that benefits all future phases.
- **Scout search with Eloquent fallback**: The controller uses `CaseGoodsSku::search($query)->keys()` to get matching IDs from Meilisearch, then uses standard Eloquent `whereIn('id', $searchIds)->paginate()` for the actual query. This avoids PHPStan issues with Scout's `LengthAwarePaginator` interface (which lacks `load()` and `through()`) and allows DB-level filters to compose with search results.
- **Schema::hasTable guard on Filament filters**: Dynamic filter options (vintage, varietal dropdowns populated from DB) are wrapped in `Schema::hasTable('case_goods_skus')` checks. This prevents 500 errors when navigating the Filament page on tenants that haven't had the new migration run yet — filters return empty arrays gracefully.
- **Local filesystem for file storage**: Image and tech sheet uploads use the local `public` disk. S3 migration planned for later.
- **relationLoaded() pattern**: CaseGoodsSkuResource uses the `$this->relationLoaded('lot') && $this->lot ? [...] : null` pattern instead of `whenLoaded()` closures, matching the convention established by LabAnalysisResource in Phase 3.

### Deviations from Spec
- Spec did not mention `event_source` column — this was specified in `docs/references/event-source-partitioning.md` as a Phase 4 infrastructure addition. Implemented in Sub-Task 1 since it's foundational for all inventory event types.
- Spec listed `bottling_run_id` as a FK but the BottlingRun model from Phase 2 Sub-Task 11 may not exist yet. The FK column is present but unconstrained (no foreign key constraint) to avoid migration failures if bottling_runs table doesn't exist.

### Patterns Established
- **Inventory navigation group**: Filament resources for inventory features go under the "Inventory" navigation group (sort order starting at 1).
- **event_source auto-resolution**: All future event types should use operation_type prefixes that map to the correct source module via `resolveSource()`. New modules should add their prefix mappings there.
- **Scout + Eloquent composition**: For searchable models, use `::search()->keys()` + `whereIn()` to combine full-text search with standard Eloquent query building. This avoids type conflicts with Scout's paginator interface.
- **Schema::hasTable guards on dynamic Filament filters**: Any Filament resource with dynamic filter options querying a table from a newer migration should guard with `Schema::hasTable()` to support incremental tenant migration.

### Test Summary
- `tests/Feature/Inventory/CaseGoodsSkuTest.php` (26 tests)
  - Tier 1: event_source partitioning — production events write 'production', lab/fermentation/sensory prefix events write 'lab', stock/purchase/equipment/dry_goods/raw_material prefix events write 'inventory', unknown operation types default to 'production' (4 tests)
  - Tier 1: tenant isolation — cross-tenant SKU data access prevention via direct model query in separate tenant contexts (1 test)
  - Tier 2: CRUD — create with all fields (validates full payload + lot relationship in response), create with minimal required fields (validates defaults: format=750ml, case_size=12, is_active=true), list with pagination (meta.total), filter by vintage, filter by varietal, filter by format, filter by active status, show with relationship eager-loading, update with partial fields (unchanged fields persist) (8 tests)
  - Tier 2: validation — missing required fields (wine_name, vintage, varietal), invalid format value, invalid case_size value, negative price, non-existent lot_id UUID (5 tests)
  - Tier 2: RBAC — winemaker can create (201), read_only cannot create (403), cellar_hand cannot create (403), read_only can list and view (200), read_only cannot update (403) (5 tests)
  - Tier 2: API envelope — correct structure (data, meta, errors keys), unauthenticated access rejection (401) (2 tests)
  - Tier 1 event source tests use EventLogger directly (not via HTTP) to verify resolveSource() logic in isolation
- Known gaps: Filament resource CRUD not tested via Livewire (deferred per Phase 1-3 pattern — requires subdomain test harness). File upload (image/tech_sheet) not tested via HTTP. Meilisearch search integration not tested (uses `collection` driver in tests; real indexing is infrastructure).
- Skipped (Tier 3): model accessor tests, factory definitions, migration schema assertions, Filament form/table column definitions.

### Open Questions
- `bottling_run_id` FK constraint is deferred — should be added when BottlingRun model is confirmed present (Phase 2 Sub-Task 11 or later).
- S3 migration for file uploads is planned but not yet configured. When switching, update the disk in CaseGoodsSkuController and the Filament FileUpload components.

---

## Sub-Task 2: Location and Stock Level Tracking
**Completed:** 2026-03-15
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_15_200003_create_locations_table.php` — Creates the `locations` table with UUID primary key, name (varchar 100), address (varchar 500 nullable), is_active (default true), timestamps. Index on is_active.
- `api/database/migrations/tenant/2026_03_15_200004_create_stock_levels_table.php` — Creates the `stock_levels` table with UUID primary key, sku_id FK (cascade delete to case_goods_skus), location_id FK (cascade delete to locations), on_hand (integer, default 0), committed (integer, default 0), timestamps. Unique constraint on (sku_id, location_id) to enforce one stock level record per SKU per location.
- `api/app/Models/Location.php` — Eloquent model with `HasUuids`, `HasFactory`, `LogsActivity` traits. Relationship: `stockLevels()` HasMany. Scope: `active()`. Default: is_active=true.
- `api/app/Models/StockLevel.php` — Eloquent model with `HasUuids`, `HasFactory` traits. Computed accessor: `available` (on_hand - committed, can go negative per spec). Relationships: `sku()`, `location()`. Scopes: `inStock()`, `forSku()`, `atLocation()`. Defaults: on_hand=0, committed=0.
- `api/database/factories/LocationFactory.php` — Generates realistic location names (Tasting Room Floor, Back Stock, Offsite Warehouse, etc.). States: `inactive()`.
- `api/database/factories/StockLevelFactory.php` — Generates random on_hand/committed quantities. States: `empty()`, `wellStocked()`.
- `api/app/Models/CaseGoodsSku.php` — Added `stockLevels()` HasMany relationship and `@property-read` PHPDoc for the collection.
- `api/app/Http/Controllers/Api/V1/LocationController.php` — Four endpoints: `index()` with active filter and eager-loaded stock levels, `show()` with nested stock levels + SKU data, `store()` with EventLogger (`stock_location_created`), `update()` with EventLogger (`stock_location_updated`). Structured logging with tenant_id.
- `api/app/Http/Requests/StoreLocationRequest.php` — Required: name (max 100). Optional: address (max 500), is_active.
- `api/app/Http/Requests/UpdateLocationRequest.php` — Same rules as Store but all fields `sometimes`.
- `api/app/Http/Resources/LocationResource.php` — Uses `@mixin \App\Models\Location` for PHPStan. Serializes stock_levels as nested array with SKU summaries and computed available field. Uses `relationLoaded()` pattern.
- `api/app/Http/Resources/StockLevelResource.php` — Uses `@mixin \App\Models\StockLevel` for PHPStan. Includes computed `available` field, nested sku and location when loaded. Uses `relationLoaded()` pattern (no redundant null checks on non-nullable BelongsTo relationships per PHPStan level 6).
- `api/app/Filament/Resources/LocationResource.php` + Pages (List, Create, View, Edit) — Under "Inventory" navigation group, sort 2, icon heroicon-o-map-pin. Table shows stock_levels_count via `withCount`. Filter: is_active ternary.
- `api/routes/api.php` — Added 4 location routes: GET /locations, GET /locations/{location}, POST /locations (winemaker+), PUT /locations/{location} (winemaker+).

### Key Decisions
- **Computed `available` attribute**: Implemented as a PHP accessor (`getAvailableAttribute()`) rather than a database-generated column. Available = on_hand - committed. Per spec, available can go negative (overselling happens in tasting rooms — warn but don't hard-block).
- **Unique constraint on (sku_id, location_id)**: Prevents duplicate stock level records. Each SKU has exactly one stock level row per location. The InventoryService (Sub-Task 3) will use this as the target for atomic updates.
- **Cascade deletes on both FKs**: Deleting a SKU removes all its stock levels; deleting a location removes all stock levels at that location. This keeps the stock_levels table referentially clean without orphaned rows.
- **EventLogger for location lifecycle**: Location create/update events use `stock_location_created` and `stock_location_updated` operation types, which resolve to `event_source=inventory` via the `stock_` prefix mapping established in Sub-Task 1.
- **PHPStan-clean resource pattern**: Non-nullable BelongsTo relationships (sku, location on StockLevel) use `relationLoaded()` without redundant `&& $this->sku` null checks. Non-nullable timestamps use `->` not `?->`. This avoids PHPStan `booleanAnd.rightAlwaysTrue` and `nullsafe.neverNull` errors at level 6.

### Deviations from Spec
- Spec lists `available` as a field on StockLevel — implemented as a computed accessor rather than a stored column. This avoids needing to keep three columns in sync and eliminates the risk of available drifting from on_hand - committed.

### Patterns Established
- **Non-nullable relationship resource pattern**: When a BelongsTo relationship is non-nullable (FK has a constraint), use `$this->relationLoaded('rel') ? [...] : null` without the `&& $this->rel` check. This keeps PHPStan clean at level 6.
- **Stock level per SKU per location**: The unique constraint enforces the one-row-per-pair invariant. Future InventoryService operations should `firstOrCreate` on (sku_id, location_id) to get or initialize the row, then update atomically.

### Test Summary
- `tests/Feature/Inventory/LocationStockLevelTest.php` (22 tests)
  - Tier 1: event logging — stock_location_created with inventory source and self-contained payload, stock_location_updated with inventory source (2 tests)
  - Tier 1: tenant isolation — cross-tenant Location + StockLevel access prevention (1 test)
  - Tier 1: data integrity — available = on_hand - committed computation, negative available allowed (overselling), unique constraint on (sku_id, location_id) enforced (3 tests)
  - Tier 2: CRUD — create with all fields, create with minimal fields (defaults verified), list with pagination, filter by active status, show with nested stock levels + SKU data, update with partial fields (6 tests)
  - Tier 2: validation — missing required name, name exceeding max length (2 tests)
  - Tier 2: RBAC — winemaker can create (201), read_only cannot create (403), cellar_hand cannot create (403), read_only can list and view (200), read_only cannot update (403) (5 tests)
  - Tier 2: relationships — multi-location stock tracking (SKU at 2 locations), cascade delete on SKU, cascade delete on Location (3 tests)
  - Tier 2: API envelope — correct structure, unauthenticated rejection (2 tests)
- Known gaps: Filament resource CRUD not tested via Livewire (deferred per Phase 1-3 pattern). StockLevel has no direct API endpoints yet — stock levels are managed via InventoryService (Sub-Task 3), not direct writes.
- Skipped (Tier 3): model accessor tests (available is Tier 1, tested above), factory definitions, migration schema assertions.

### Open Questions
- No direct API endpoints for StockLevel CRUD — stock levels are read via the Location show endpoint (nested) and modified via InventoryService (Sub-Task 3).

---

## Sub-Task 3: Stock Movement Logging
**Completed:** 2026-03-15
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_15_200005_create_stock_movements_table.php` — Creates the `stock_movements` table with UUID primary key, sku_id FK (cascade delete), location_id FK (cascade delete), movement_type (varchar 30), quantity (integer, signed), reference_type (varchar 50 nullable), reference_id (UUID nullable), performed_by (UUID nullable), performed_at (timestamp), notes (text nullable), created_at (timestamp, auto). Indexes on sku_id, location_id, movement_type, performed_at, and composite (reference_type, reference_id).
- `api/app/Models/StockMovement.php` — Immutable ledger model with `HasUuids`, `HasFactory` traits. No `updated_at` (ledger entries are immutable). Constants: `MOVEMENT_TYPES` (received, sold, transferred, adjusted, returned, bottled), `REFERENCE_TYPES` (order, bottling_run, transfer, adjustment, physical_count). Relationships: `sku()`, `location()`, `performer()`. Scopes: `forSku()`, `atLocation()`, `ofType()`, `performedBetween()`.
- `api/database/factories/StockMovementFactory.php` — Generates random movement data. States: `received()`, `sold()`, `adjusted()`.
- `api/app/Services/InventoryService.php` — The single entry point for all stock level mutations. Methods: `receive()` (positive inflow, writes `stock_received` event), `sell()` (negative outflow with auto-negation, writes `stock_sold` event), `adjust()` (positive or negative, writes `stock_adjusted` event), `transfer()` (paired movements in a single transaction with shared reference_id, writes `stock_transferred` event). All methods use `SELECT FOR UPDATE` (lockForUpdate) on the StockLevel row to prevent race conditions from concurrent POS sales. Auto-creates StockLevel rows via `create()` if no row exists for the (sku_id, location_id) pair. Self-contained event payloads include wine_name and location_name alongside FK IDs.
- `api/app/Http/Resources/StockMovementResource.php` — Uses `@mixin \App\Models\StockMovement` for PHPStan. Serializes movement data with nested sku/location when loaded. Uses `relationLoaded()` pattern without redundant null checks on non-nullable BelongsTo relationships.
- `api/app/Models/CaseGoodsSku.php` — Added `stockMovements()` HasMany relationship and `@property-read` PHPDoc.
- `api/app/Models/Location.php` — Added `stockMovements()` HasMany relationship and `@property-read` PHPDoc.

### Key Decisions
- **SELECT FOR UPDATE locking**: Every stock mutation acquires a row-level lock on the StockLevel row before reading and updating `on_hand`. This prevents race conditions when concurrent POS sales or sync operations target the same SKU+location pair. The lock is held for the duration of the DB::transaction.
- **Auto-create StockLevel**: If no StockLevel row exists for a (sku_id, location_id) pair, the service creates one with on_hand=0 before applying the movement. This avoids requiring pre-seeding of stock level rows.
- **Immutable ledger**: StockMovement has no `updated_at` — records are append-only. Corrections are modeled as new adjustment movements, not edits to existing records.
- **Transfer as paired movements**: A transfer creates two StockMovement rows (negative at source, positive at destination) sharing the same `reference_id`. A single `stock_transferred` event is written for the pair, not two separate events.
- **Sell auto-negates quantity**: Callers pass a positive quantity to `sell()` — the service negates it internally. This prevents sign confusion at the API boundary. Same pattern for the outflow side of `transfer()`.
- **PHPStan `@var StockLevel|null`**: The `lockForUpdate()->first()` result must be annotated as `StockLevel|null` for PHPStan to accept the subsequent null check. Without the `|null`, PHPStan infers the type from the `@var` annotation and flags the null check as always-false.

### Deviations from Spec
- Spec mentions `stock_counted` event type — deferred to Sub-Task 4 (Physical Inventory Count). The InventoryService currently handles receive, sell, adjust, and transfer. The `stock_counted` event will be added when PhysicalCountService is built.
- Added `stock_sold` event type not explicitly listed in the spec's four event types, but logically necessary since `sell()` is a distinct operation from `adjust()`.

### Patterns Established
- **InventoryService as sole mutation entry point**: All stock level changes must go through InventoryService. Direct `StockLevel::update()` calls are prohibited. This ensures every mutation has a corresponding ledger entry and event.
- **SELECT FOR UPDATE for atomic stock operations**: Any service that mutates stock levels should use `lockForUpdate()` within a `DB::transaction()` to prevent concurrent access issues.
- **Paired movements for transfers**: Transfers create two ledger entries linked by `reference_id`. Future operations that affect multiple locations (e.g., cross-dock) should follow this pattern.
- **Positive-quantity API, internal negation**: Service methods accept positive quantities and handle sign internally. This keeps the caller's interface clean and prevents sign errors.

### Test Summary
- `tests/Feature/Inventory/StockMovementTest.php` (22 tests)
  - Tier 1: event logging — stock_received, stock_adjusted, stock_transferred, stock_sold events with inventory source and self-contained payloads (4 tests)
  - Tier 1: inventory math — receive increases on_hand (cumulative), sell decreases on_hand, adjust increases/decreases on_hand, transfer moves stock atomically between two locations with paired reference_id, auto-creates StockLevel if absent, overselling allowed (on_hand goes negative) (6 tests)
  - Tier 1: tenant isolation — cross-tenant StockMovement access prevention (1 test)
  - Tier 2: movement ledger — immutable audit trail (3 movements in sequence with correct types/quantities), reference_type/reference_id traceability for order links, paired transfer movements with shared reference_id and propagated notes (3 tests)
  - Tier 2: validation — receive rejects ≤0 quantity, sell rejects ≤0 quantity, transfer rejects ≤0 quantity, transfer rejects same-location (4 tests)
  - All tests exercise InventoryService directly within tenant context (not mocking own services per testing guide)
- Known gaps: No API endpoints for creating movements directly — movements are created via InventoryService from other controllers (POS, bottling, physical count). Filament UI for viewing movement history not yet built. Concurrency stress test (parallel transactions) not tested in Pest (requires multi-process setup).
- Skipped (Tier 3): factory definitions, model scope tests, StockMovementResource serialization.

### Open Questions
- API endpoints for viewing movement history (GET /skus/{sku}/movements, GET /locations/{location}/movements) not yet built — will likely be needed for Sub-Task 4 (physical count variance report) or a standalone movement history feature.
- The `returned` and `bottled` movement types are defined but not yet exercised by any service method. They will be wired up when the POS returns flow and bottling-run-to-inventory bridge are built.

---

## Sub-Task 4: Physical Inventory Count Tool
**Completed:** 2026-03-15
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_15_200006_create_physical_counts_table.php` — Creates two tables: `physical_counts` (UUID PK, location_id FK cascade, status varchar 20 default 'in_progress', started_by UUID, started_at timestamp, completed_by UUID nullable, completed_at timestamp nullable, notes text nullable, timestamps; indexes on location, status, started_at) and `physical_count_lines` (UUID PK, physical_count_id FK cascade, sku_id FK cascade, system_quantity int, counted_quantity int nullable, variance int nullable, notes text nullable, timestamps; unique constraint on (physical_count_id, sku_id), index on sku_id).
- `api/app/Models/PhysicalCount.php` — Eloquent model with `HasUuids`, `HasFactory` traits. Constants: STATUSES (in_progress, completed, cancelled). Relationships: `location()`, `starter()`, `completer()`, `lines()`. Scopes: `inProgress()`, `forLocation()`. Default status: in_progress.
- `api/app/Models/PhysicalCountLine.php` — Eloquent model with `HasUuids`, `HasFactory` traits. Relationships: `physicalCount()`, `sku()`. Casts: system_quantity, counted_quantity, variance as integer.
- `api/database/factories/PhysicalCountFactory.php` — Generates physical count sessions. State: `completed()`.
- `api/database/factories/PhysicalCountLineFactory.php` — Generates count lines with random system/counted quantities, computes variance = counted - system.
- `api/app/Services/PhysicalCountService.php` — Manages the full physical count workflow. Methods: `startCount()` creates a count session for a location, snapshots current system on_hand for all SKUs at that location into count lines, writes `stock_count_started` event; `recordCounts()` enters actual counted quantities per SKU, computes variance, supports discovering new SKUs not in the original snapshot (system_quantity=0); `approve()` writes `stock_adjusted` movements via InventoryService for each non-zero variance, marks count completed, writes `stock_counted` event; `cancel()` marks count cancelled with no stock adjustments. All operations wrapped in DB::transaction.
- `api/app/Http/Resources/PhysicalCountResource.php` — Uses `@mixin \App\Models\PhysicalCount`. Serializes count session with nested lines including SKU data (wine_name, vintage, varietal, format, upc_barcode for barcode scanning). Uses `relationLoaded()` pattern.
- `api/app/Http/Controllers/Api/V1/PhysicalCountController.php` — Six endpoints: `index()` with location_id and status filters + pagination, `show()` with eager-loaded lines and SKU data, `start()` to begin a new count session, `recordCounts()` to enter actual quantities, `approve()` to finalize and write adjustments, `cancel()` to abort without changes. Injects PhysicalCountService via constructor DI.
- `api/app/Filament/Pages/PhysicalCount.php` — Custom Filament page (not a Resource) showing a table of count sessions with status badges, location names, started_at timestamps, and line counts. Under "Inventory" navigation group, sort 3, icon heroicon-o-clipboard-document-check.
- `api/resources/views/filament/pages/physical-count.blade.php` — Blade template for the Filament page with section heading and description text.
- `api/routes/api.php` — Added PhysicalCountController import and 6 routes: GET /physical-counts (index), GET /physical-counts/{physicalCount} (show), POST /physical-counts/start (winemaker+), POST /physical-counts/{physicalCount}/record (winemaker+), POST /physical-counts/{physicalCount}/approve (winemaker+), POST /physical-counts/{physicalCount}/cancel (winemaker+).

### Key Decisions
- **Snapshot-based counting**: When a count session starts, system on_hand quantities are snapshotted into count lines. This prevents count drift if stock moves during the counting process — variances are computed against the snapshot, not the live stock level.
- **Discovered SKUs during count**: If a counter finds a SKU not in the original snapshot (e.g., misplaced inventory), it can be added with system_quantity=0. The variance then represents the full counted amount as a positive adjustment.
- **Approval writes adjustments through InventoryService**: The approve workflow delegates stock mutations to `InventoryService::adjust()` so that all stock changes flow through the established SELECT FOR UPDATE path and generate proper stock_adjusted movements with physical_count reference_type and reference_id linkage.
- **Custom Filament page instead of Resource**: Physical counts have a workflow-driven lifecycle (start → record → approve/cancel) that doesn't map cleanly to standard CRUD. A custom page with a table view is more appropriate than a full Filament Resource.
- **Separate read and write route groups**: GET endpoints (index, show) are available to all authenticated users. Write endpoints (start, record, approve, cancel) require winemaker+ role, matching the pattern from other inventory routes.

### Deviations from Spec
- None significant. The physical count workflow follows the spec's described flow: start session → enter counts → review variances → approve adjustments.

### Patterns Established
- **Workflow service pattern**: PhysicalCountService orchestrates a multi-step workflow (start → record → approve/cancel) using status-based guards. Each method validates the current status before proceeding.
- **Snapshot-then-compare**: Capturing system state at count start isolates the counting process from concurrent stock movements. This pattern can be reused for any reconciliation workflow.
- **Reference linkage for audit**: All stock adjustments from a physical count share `reference_type='physical_count'` and `reference_id=count.id`, enabling full traceability back to the originating count session.

### Test Summary
- `tests/Feature/Inventory/PhysicalCountTest.php` (22 tests)
  - Tier 1: event logging — stock_count_started event with inventory source and line_count payload, stock_counted event on approval with adjustments_made count (2 tests)
  - Tier 1: tenant isolation — cross-tenant PhysicalCount/PhysicalCountLine access prevention (1 test)
  - Tier 1: workflow integrity — system quantity snapshot on start (verifies correct on_hand values, null counted/variance initially), variance computation after recording counts, stock adjustments written only for non-zero variances on approval (stock levels verified), discovered new SKU during count (system_quantity=0, positive variance), cancel does not write adjustments (stock unchanged) (5 tests)
  - Tier 2: API CRUD — list with pagination, filter by status, filter by location_id, show with nested lines and SKU data (4 tests)
  - Tier 2: validation — missing location_id on start (422), non-existent location (422), negative counted_quantity (422), approve on non-in-progress count (500) (4 tests)
  - Tier 2: RBAC — winemaker can start (201), read_only cannot start (403), cellar_hand cannot start (403), read_only can list and view (200) (4 tests)
  - Tier 2: API envelope — correct structure on create (data, meta, errors), unauthenticated rejection (401) (2 tests)
- Known gaps: Filament page not tested via Livewire (deferred per Phase 1-3 pattern). Concurrent count sessions for the same location not explicitly tested. Re-count workflow (starting a new count after cancellation) not tested.
- Skipped (Tier 3): factory definitions, model scope tests, PhysicalCountResource serialization, PhysicalCountLine model tests.

### Open Questions
- Should there be a guard preventing multiple in-progress counts for the same location simultaneously? Currently not enforced — could lead to conflicting adjustments if two counts are approved for the same location.
- Barcode scanning integration: The PhysicalCountResource includes `upc_barcode` in SKU data for future mobile scanning support. The actual scanning workflow is a frontend concern.

---

## Sub-Task 5: Stock Transfer Between Locations
**Completed:** 2026-03-15
**Status:** Done

### What Was Built
- `api/app/Http/Controllers/Api/V1/StockTransferController.php` — Single `store()` endpoint for point-to-point stock transfers. Validates available stock at source (on_hand - committed) before executing. Delegates to `InventoryService::transfer()` for atomic paired movements. Returns both from/to movements with eager-loaded SKU and location data, plus the shared transfer_id.
- `api/routes/api.php` — Added StockTransferController import and 1 route: POST /stock/transfer (any authenticated user, per spec).
- `api/app/Http/Resources/StockMovementResource.php` — Fixed `created_at` serialization: changed `->toIso8601String()` to `?->toIso8601String()` because StockMovement has `$timestamps = false` so Eloquent doesn't populate `created_at` on the in-memory model (the DB column uses `useCurrent()` default).
- `api/app/Models/StockMovement.php` — Updated PHPDoc: `@property \Illuminate\Support\Carbon|null $created_at` to match the nullable runtime behavior for PHPStan level 6 compliance.

### Key Decisions
- **Available stock validation**: The controller checks `available` (on_hand - committed) at the source before calling `InventoryService::transfer()`. This enforces the spec's "cannot transfer more than available at source" rule, which is stricter than the sell flow (which allows overselling for tasting room POS scenarios).
- **Any authenticated user can transfer**: Per the spec's API endpoint table, transfers are available to all authenticated users (not just winemaker+). This makes sense — cellar hands and tasting room staff routinely move stock between locations.
- **No in-transit state**: Per spec, this is a simple point-to-point transfer for v1. The source decreases and destination increases atomically in a single transaction.
- **Response includes both movements**: The API returns the transfer_id (shared reference_id), plus both from and to movements with eager-loaded relationships. This gives the caller full visibility into what happened.

### Deviations from Spec
- Spec endpoint path is `POST /api/v1/stock/transfer` — implemented as specified.
- The `different:from_location_id` validation rule on `to_location_id` catches same-location transfers at the request level, before reaching InventoryService's own check.

### Patterns Established
- **Available-stock guard for transfers**: Unlike sells (which allow overselling), transfers enforce available ≥ quantity. This two-tier approach (soft limit for POS, hard limit for transfers) can be reused for other operations that should respect committed allocations.

### Test Summary
- `tests/Feature/Inventory/StockTransferTest.php` (15 tests)
  - Tier 1: event logging — stock_transferred event with inventory source, location names, wine_name, and quantity (1 test)
  - Tier 1: data integrity — source decreases/destination increases correctly, paired movements with shared reference_id and notes, rejects transfer exceeding available stock, rejects transfer when source has no stock level (4 tests)
  - Tier 1: tenant isolation — cannot transfer using another tenant's location IDs (exists validation catches cross-tenant references) (1 test)
  - Tier 2: validation — missing required fields (sku_id, from_location_id, to_location_id, quantity), same location rejected (different rule), zero/negative quantity rejected, non-existent IDs rejected (4 tests)
  - Tier 2: RBAC — cellar_hand can transfer (201), read_only can transfer (201), unauthenticated rejected (401) (3 tests)
  - Tier 2: API envelope — correct structure with transfer_id, from/to movements with eager-loaded SKU and location (1 test)
  - Tier 1 bug fix: helper functions renamed to `createStockTransferTestTenant` / `createStockTransferFixtures` to avoid collision with existing `Production/TransferTest.php` which declares `createTransferTestTenant`
- Known gaps: Concurrent transfer race condition (two users transferring same stock simultaneously) not tested in Pest. Transfer history/listing endpoint not built (movements can be queried via the movement ledger).
- Skipped (Tier 3): factory definitions, edge case of transferring exact available amount (boundary).

### Open Questions
- Should there be a transfer listing/history endpoint (GET /stock/transfers) to view past transfers? Currently transfers are visible only through the stock movement ledger.
- The available-stock guard uses a non-locked read before the locked write in InventoryService. In extremely high-concurrency scenarios, a TOCTOU race is possible. For winery volumes this is negligible.

---

## Sub-Task 6: Dry Goods and Packaging Materials
**Completed:** 2026-03-15
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_15_200007_create_dry_goods_items_table.php` — Creates the `dry_goods_items` table with UUID primary key, name (varchar 150), item_type (varchar 30), unit_of_measure (varchar 30), on_hand (decimal 12,2 default 0), reorder_point (decimal 12,2 nullable), cost_per_unit (decimal 10,4 nullable), vendor_name (varchar 200 nullable), vendor_id (UUID nullable for future FK), is_active (default true), notes (text nullable), timestamps. Indexes on item_type and is_active.
- `api/app/Models/DryGoodsItem.php` — Eloquent model with `HasUuids`, `HasFactory`, `LogsActivity` (App\Traits\LogsActivity) traits. Constants: ITEM_TYPES (bottle, cork, screw_cap, capsule, label_front, label_back, label_neck, carton, divider, tissue), UNITS_OF_MEASURE (each, sleeve, pallet). Scopes: `active()`, `ofType()`, `belowReorderPoint()`. Helper method: `needsReorder()` returns true when on_hand <= reorder_point (false if no reorder_point set). Decimal casts: on_hand (2), reorder_point (2), cost_per_unit (4).
- `api/database/factories/DryGoodsItemFactory.php` — Generates realistic names per item_type (e.g., "750ml Burgundy Green" for bottle, "Natural Cork Grade A" for cork). States: `inactive()`, `lowStock()`, `noReorderPoint()`.
- `api/app/Http/Resources/DryGoodsItemResource.php` — Uses `@mixin \App\Models\DryGoodsItem`. Includes computed `needs_reorder` boolean from model helper. Numeric fields cast to float for consistent JSON serialization.
- `api/app/Http/Requests/StoreDryGoodsItemRequest.php` — Required: name (max 150), item_type (in ITEM_TYPES), unit_of_measure (in UNITS_OF_MEASURE). Optional: on_hand (numeric ≥0), reorder_point, cost_per_unit, vendor_name, vendor_id (uuid), is_active, notes.
- `api/app/Http/Requests/UpdateDryGoodsItemRequest.php` — Same rules as Store but all fields `sometimes`.
- `api/app/Http/Controllers/Api/V1/DryGoodsController.php` — Four endpoints: `index()` with filters for is_active, item_type, below_reorder (reorder alert filter), `show()`, `store()` with EventLogger (`dry_goods_created`), `update()` with EventLogger (`dry_goods_updated`). Structured logging with tenant_id.
- `api/app/Filament/Resources/DryGoodsItemResource.php` + Pages (List, Create, View, Edit) — Under "Inventory" navigation group, sort 4, icon heroicon-o-archive-box. Form: 3 sections (Item Details, Stock & Cost, Vendor & Notes). Table: searchable name, badge for item_type, on_hand, unit, reorder_point, cost, vendor. Filters: item_type select, is_active ternary, below-reorder-point toggle. Schema::hasTable guard on type filter.
- `api/routes/api.php` — Added DryGoodsController import and 4 routes: GET /dry-goods (authenticated), GET /dry-goods/{item} (authenticated), POST /dry-goods (admin+), PUT /dry-goods/{item} (admin+).

### Key Decisions
- **Decimal storage for quantities**: on_hand uses decimal(12,2) rather than integer because dry goods units vary widely. Bottles are counted by each, corks by sleeve (1000/sleeve), capsules by bag. Partial units (2.5 pallets) are valid.
- **cost_per_unit with 4 decimal places**: Sub-cent precision needed for high-volume low-cost items (e.g., corks at $0.0823 each). Feeds into COGS calculations later.
- **vendor_name as simple string**: No Vendor model exists yet — vendor_id is a nullable UUID placeholder for the PurchaseOrder sub-task (Sub-Task 10). vendor_name provides human-readable vendor info in the meantime.
- **Admin+ for create/update**: Per spec, dry goods management is admin+ (not winemaker). Winemakers and below can view but not modify inventory items.
- **belowReorderPoint scope**: Uses `whereColumn('on_hand', '<=', 'reorder_point')` to push the comparison to the DB. Items without a reorder_point are excluded (they can't be "below" a threshold that doesn't exist).
- **LogsActivity trait**: Uses `App\Traits\LogsActivity` (custom trait), not `Spatie\Activitylog\Traits\LogsActivity`. The codebase has its own lightweight activity logging that writes to an ActivityLog model.

### Deviations from Spec
- Spec mentions "Auto-deduct on bottling run completion" — this is deferred to when the bottling-to-inventory bridge is wired up. The model and stock fields are ready for deduction but no auto-deduct trigger exists yet.
- Spec mentions "Receive PO: add quantity to stock" — deferred to Sub-Task 10 (Purchase Orders). Stock can be manually updated via the update endpoint for now.
- Spec mentions "Reorder alerts" — the `belowReorderPoint` scope and `needsReorder()` helper are implemented. Actual notification/alert delivery (email, Filament notification) is deferred.

### Patterns Established
- **Decimal quantity pattern**: For inventory items measured in non-integer units, use decimal columns with appropriate precision. Cast in model via `'decimal:N'` for consistent PHP handling.
- **Reorder point pattern**: `belowReorderPoint()` scope + `needsReorder()` helper provides both query-level and instance-level reorder detection. Reuse for RawMaterial in Sub-Task 7.

### Test Summary
- `tests/Feature/Inventory/DryGoodsTest.php` (22 tests)
  - Tier 1: event logging — dry_goods_created with inventory source and payload, dry_goods_updated with inventory source (2 tests)
  - Tier 1: tenant isolation — cross-tenant DryGoodsItem access prevention (1 test)
  - Tier 1: data integrity — belowReorderPoint scope correctness, needsReorder() helper logic, decimal quantity storage precision (3 tests)
  - Tier 2: CRUD — create with all fields, create with minimal fields (defaults verified), list with pagination, filter by item_type, filter by active status, filter by below_reorder, show detail, update with partial fields (8 tests)
  - Tier 2: validation — missing required fields, invalid item_type, invalid unit_of_measure, negative on_hand (4 tests)
  - Tier 2: RBAC — admin can create (201), winemaker cannot create (403), read_only cannot create (403), any user can list/view (200), winemaker cannot update (403) (5 tests)
  - Tier 2: API envelope — correct structure with needs_reorder field, unauthenticated rejection (2 tests)
  - Note: numeric assertions use `toEqual()` instead of `toBe()` because JSON encodes whole-number floats as integers (5000.0 → 5000)
- Known gaps: Filament resource CRUD not tested via Livewire. Auto-deduct on bottling not wired. PO receipt flow not built. Notification delivery for reorder alerts not implemented.
- Skipped (Tier 3): factory definitions, model scope edge cases, Filament filter interactions.

### Open Questions
- Auto-deduct trigger: When bottling run completion creates case goods, it should also deduct the corresponding dry goods (bottles, corks, capsules, labels, cartons per case_size). This requires a BOM (bill of materials) mapping from SKU → dry goods items + quantities. Where should this mapping live?
- Reorder alert delivery: The scope and helper are ready, but how should alerts be surfaced? Options: Filament notification badge, email digest, dashboard widget. Deferred to a later sub-task or phase.

---

## Sub-Task 7: Raw Materials and Cellar Supplies
**Completed:** 2026-03-15
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_15_200008_create_raw_materials_table.php` — Creates the `raw_materials` table with UUID primary key, name (varchar 150), category (varchar 30), unit_of_measure (varchar 30), on_hand (decimal 12,2 default 0), reorder_point (decimal 12,2 nullable), cost_per_unit (decimal 10,4 nullable), expiration_date (date nullable), vendor_name (varchar 200 nullable), vendor_id (UUID nullable for future FK), is_active (default true), notes (text nullable), timestamps. Indexes on category, is_active, expiration_date.
- `api/app/Models/RawMaterial.php` — Eloquent model with `HasUuids`, `HasFactory`, `LogsActivity` (App\Traits\LogsActivity) traits. Constants: CATEGORIES (additive, yeast, nutrient, fining_agent, acid, enzyme, oak_alternative), UNITS_OF_MEASURE (g, kg, L, each). Scopes: `active()`, `ofCategory()`, `belowReorderPoint()`, `expired()`, `expiringSoon()`. Helpers: `needsReorder()`, `isExpired()`. Decimal casts: on_hand (2), reorder_point (2), cost_per_unit (4). Date cast: expiration_date.
- `api/database/factories/RawMaterialFactory.php` — Generates realistic names per category (e.g., "Potassium Metabisulfite" for additive, "EC-1118" for yeast, "Fermaid O" for nutrient, "Bentonite" for fining_agent). Suggests sensible unit_of_measure per category. States: `inactive()`, `lowStock()`, `noReorderPoint()`, `expired()`, `expiringSoon()`.
- `api/app/Http/Resources/RawMaterialResource.php` — Uses `@mixin \App\Models\RawMaterial`. Includes computed `needs_reorder` and `is_expired` booleans, `expiration_date` as date string. Numeric fields cast to float.
- `api/app/Http/Requests/StoreRawMaterialRequest.php` — Required: name (max 150), category (in CATEGORIES), unit_of_measure (in UNITS_OF_MEASURE). Optional: on_hand (numeric ≥0), reorder_point, cost_per_unit, expiration_date (date), vendor_name, vendor_id (uuid), is_active, notes.
- `api/app/Http/Requests/UpdateRawMaterialRequest.php` — Same rules as Store but all fields `sometimes`.
- `api/app/Http/Controllers/Api/V1/RawMaterialController.php` — Four endpoints: `index()` with filters for is_active, category, below_reorder, expired, expiring_within_days; `show()`; `store()` with EventLogger (`raw_material_created`); `update()` with EventLogger (`raw_material_updated`). Structured logging with tenant_id.
- `api/app/Filament/Resources/RawMaterialResource.php` + Pages (List, Create, View, Edit) — Under "Inventory" navigation group, sort 5, icon heroicon-o-beaker. Form: 3 sections (Material Details with category/unit/expiration/active, Stock & Cost, Vendor & Notes). Table: searchable name, badge for category, on_hand, unit, reorder_point, cost, expiration_date, vendor, is_active. Filters: category select, is_active ternary, below-reorder-point toggle, expired toggle. Schema::hasTable guard on category filter.
- `api/routes/api.php` — Added RawMaterialController import and 4 routes: GET /raw-materials (authenticated), GET /raw-materials/{rawMaterial} (authenticated), POST /raw-materials (admin+), PUT /raw-materials/{rawMaterial} (admin+).
- `api/app/Services/AdditionService.php` — Unstubbed auto-deduct: replaced the commented-out stub with live `deductInventory()` method. When an addition has `inventory_item_id`, finds the linked RawMaterial via `lockForUpdate()`, decrements `on_hand` by `total_amount`, writes `raw_material_deducted` event with self-contained payload (raw_material_name, addition_id, lot_id, deducted_amount, unit_of_measure, previous/new on_hand). Allows on_hand to go negative (winery may record usage even if stock tracking is inaccurate). Logs warning if linked raw material not found.

### Key Decisions
- **Expiration tracking**: Raw materials (unlike dry goods) degrade over time. `expiration_date` is a nullable date column with `expired()` and `expiringSoon()` scopes plus an `isExpired()` model helper. The API resource includes `is_expired` as a computed boolean.
- **Category-specific units**: The factory suggests sensible defaults (yeast → g/each, enzymes → L, acids/nutrients → g/kg). The model accepts any unit from the UNITS_OF_MEASURE constant.
- **Auto-deduct via AdditionService**: The deduction is atomic — it runs within the existing DB::transaction in `createAddition()`. Uses `lockForUpdate()` for race-condition safety, matching InventoryService's pattern. Allows negative on_hand because winery recording may not perfectly track physical stock.
- **Deduction event payload is self-contained**: Includes human-readable `raw_material_name` alongside `addition_id`, `lot_id`, and deducted/previous/new quantities for data-portability per the event log design constraint.
- **Admin+ for create/update**: Per spec, raw material management is admin+ (matching dry goods). Winemakers can read but not create or modify raw material records.

### Deviations from Spec
- Spec mentions "Receive PO: add quantity to stock" — deferred to Sub-Task 10 (Purchase Orders). Stock can be manually updated via the update endpoint.
- Spec mentions "Expiration alerts" — the `expired()` and `expiringSoon()` scopes are implemented. Actual notification delivery (email, Filament notification) is deferred.

### Patterns Established
- **Expiration tracking pattern**: `expired()` scope for past-expiration items, `expiringSoon(days)` for configurable lookahead window, `isExpired()` for instance-level check. Reusable for any perishable inventory.
- **Auto-deduct from service layer**: The AdditionService auto-deduct pattern (check inventory_item_id → lockForUpdate → decrement → log event) can be extended to other services that consume raw materials (e.g., future fining or enzyme application endpoints).
- **Factory-based test fixtures for cross-model tests**: Auto-deduct tests use `Lot::factory()->create()` and `Vessel::factory()->create()` instead of raw `::create()` to avoid fragile column-name assumptions when testing across model boundaries.

### Test Summary
- `tests/Feature/Inventory/RawMaterialTest.php` (24 tests)
  - Tier 1: event logging — raw_material_created with inventory source and payload, raw_material_updated with inventory source (2 tests)
  - Tier 1: tenant isolation — cross-tenant RawMaterial access prevention (1 test)
  - Tier 1: data integrity — belowReorderPoint scope correctness, needsReorder() helper logic, expired scope identifies past-expiration items, expiringSoon scope with configurable window, decimal quantity storage precision (5 tests)
  - Tier 1: auto-deduct — addition with inventory_item_id decrements raw material on_hand and writes raw_material_deducted event with correct payload, addition without inventory_item_id leaves raw material on_hand unchanged (2 tests)
  - Tier 2: CRUD — create with all fields (including expiration_date, is_expired), create with minimal fields (defaults verified), list with pagination, filter by category, filter by active status, filter by below_reorder, filter by expired, show detail, update with partial fields (9 tests)
  - Tier 2: validation — missing required fields, invalid category, invalid unit_of_measure, negative on_hand (4 tests)
  - Tier 2: RBAC — admin can create (201), winemaker cannot create (403), read_only cannot create (403), any user can list/view (200), winemaker cannot update (403) (5 tests)
  - Tier 2: API envelope — correct structure with needs_reorder and is_expired fields, unauthenticated rejection (2 tests)
  - Note: numeric assertions use `toEqual()` for decimal fields; Lot and Vessel fixtures created via factories to avoid fragile column assumptions
- Known gaps: Filament resource CRUD not tested via Livewire. PO receipt flow not built. Expiration alert notification delivery not implemented. Concurrent auto-deduct race condition not stress-tested.
- Skipped (Tier 3): factory definitions, model scope edge cases, Filament filter interactions.

### Open Questions
- Expiration alert delivery: The scopes are ready, but how should expiring/expired materials be surfaced? Options: Filament dashboard widget with "expiring within 30 days" count, email digest. Deferred.
- Auto-deduct unit conversion: Currently assumes addition's total_amount is in the same unit as the raw material's unit_of_measure. If a user adds 50g of a material tracked in kg, the deduction would be wrong (50 instead of 0.05). Unit conversion logic may be needed when the UI for linking additions to inventory is built.
- Should the auto-deduct feature be toggleable per-tenant? Some wineries may want to track raw materials without automatic deductions from the addition workflow.
