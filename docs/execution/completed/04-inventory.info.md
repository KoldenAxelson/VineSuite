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
