# Production Core — Completion Record

## Sub-Task 1: Lot Model, Migration, and Basic CRUD
**Completed:** 2026-03-10
**Status:** Awaiting verification

### What Was Built
- `api/database/migrations/tenant/2026_03_10_100001_create_lots_table.php` — Creates the `lots` table with UUID primary key, variety/vintage/source fields, volume stored as `decimal(12,4)` in gallons, status enum, and self-referencing `parent_lot_id` for splits/blends. Indexed on variety, vintage, status, and parent_lot_id.
- `api/app/Models/Lot.php` — Eloquent model with `HasFactory`, `HasUuids`, `LogsActivity` traits. Defines `STATUSES` and `SOURCE_TYPES` constants. Relationships: `parentLot()`, `childLots()`, `events()` (polymorphic via entity_type/entity_id on events table). Scopes: `ofVariety()`, `ofVintage()`, `withStatus()`, `search()` (ilike on name, variety, vintage cast).
- `api/database/factories/LotFactory.php` — Generates realistic lot data with 12 grape varieties, random vintages, estate/purchased source types with appropriate source_details JSON. States: `inProgress()`, `aging()`, `bottled()`.
- `api/app/Services/LotService.php` — Business logic layer. `createLot()` creates the lot and writes a `lot_created` event via EventLogger with full payload (name, variety, vintage, source, initial_volume). `updateLot()` captures old values, updates, and writes a `lot_status_changed` event when status changes. Structured logging with tenant_id on all operations.
- `api/app/Http/Requests/StoreLotRequest.php` — Validates: name (required, string, max 255), variety (required), vintage (required, 1900–2100), source_type (required, in estate/purchased), volume_gallons (required, numeric, 0–999999.9999), source_details (optional array), status (optional, valid enum), parent_lot_id (optional, UUID, exists in lots).
- `api/app/Http/Requests/UpdateLotRequest.php` — Partial update validation for name, status, source_details, volume_gallons.
- `api/app/Http/Resources/LotResource.php` — Extends BaseResource for standard envelope wrapping. Returns all lot fields with volume as float and ISO 8601 timestamps.
- `api/app/Http/Controllers/Api/V1/LotController.php` — RESTful controller with `index()` (paginated, filterable by variety/vintage/status/search), `store()` (creates via LotService), `show()` (eager loads childLots and parentLot), `update()` (partial update via LotService).
- `api/routes/api.php` — Added lot routes under `auth:sanctum` middleware. GET `/lots` and GET `/lots/{lot}` available to all authenticated users. POST `/lots` and PUT `/lots/{lot}` require `role:owner,admin,winemaker`.
- `api/tests/Feature/Production/LotTest.php` — 18 tests covering event log writes, volume precision, CRUD, filtering, search, validation, RBAC, and API envelope format.

### Key Decisions
- **Route RBAC uses `role:owner,admin,winemaker` for mutations** — matches the task spec's "winemaker+" auth scope. Read endpoints are available to all authenticated users (including cellar_hand, read_only, etc.) since the spec says "Authenticated" for GET operations.
- **Volume stored as `decimal(12,4)`** — 4 decimal places matches the spec's precision requirements for gallon tracking. Using PostgreSQL DECIMAL avoids floating-point rounding issues critical for TTB compliance math.
- **Source details is JSONB, not normalized** — vineyard/block/grower info varies too much between estate and purchased fruit to warrant separate columns. JSONB allows flexibility while remaining queryable via PostgreSQL JSON operators.
- **Search uses `ilike` (case-insensitive)** — winery lot names have inconsistent casing. PostgreSQL's `ilike` handles this without a separate search index. Will transition to Meilisearch if performance becomes an issue with large datasets.
- **Status changes write events, name changes don't** — the event log tracks operational state transitions (in_progress → aging → bottled). Name edits are tracked by the LogsActivity trait in the activity_logs table instead.
- **LotResource extends BaseResource** — ensures all responses go through the standard `{ data, meta, errors }` envelope. Used `new LotResource($lot)` for single items and `ApiResponse::paginated()` for lists (which doesn't use resources directly but returns paginator items).

### Deviations from Spec
- None. Implementation matches the spec exactly.

### Patterns Established
- **Production Service Pattern** — `LotService` takes `EventLogger` via constructor injection, creates the model, then writes the event. All future production services (VesselService, TransferService, BlendService, etc.) should follow this pattern: model mutation → event log write → structured log.
- **Production Test Pattern** — `createLotTestTenant()` helper creates a tenant with a specific role, logs in, returns `[$tenant, $token]`. Tests grouped by tier: Tier 1 (event log writes, volume math), Tier 2 (CRUD, validation, RBAC, envelope). Located in `tests/Feature/Production/`.
- **Production Route Pattern** — Read endpoints open to all authenticated users, write endpoints gated by `role:owner,admin,winemaker`. Pattern: `GET /resource` and `GET /resource/{id}` outside role gate, `POST` and `PUT` inside role gate.

### Test Summary
- `tests/Feature/Production/LotTest.php` (18 tests)
  - Tier 1: lot_created event written with correct payload (name, variety, vintage, source, initial_volume)
  - Tier 1: lot_status_changed event written with old/new status
  - Tier 1: volume stored with 4-decimal precision
  - Tier 2: full CRUD (create, list, show, update)
  - Tier 2: pagination and filtering (variety, vintage, status, search)
  - Tier 2: validation (missing fields, invalid source_type, invalid status, negative volume)
  - Tier 2: RBAC (read-only can view not create, cellar_hand can view not create, winemaker can CRUD)
  - Tier 2: API envelope format, unauthenticated rejection
- Known gaps: Filament resource not built yet (Sub-Task 13), lot_vessel pivot not yet (Sub-Task 2)

### Open Questions
- None for this sub-task.

---

## Sub-Task 2: Vessel Model, Migration, and CRUD
**Completed:** 2026-03-10
**Status:** Awaiting verification

### What Was Built
- `api/database/migrations/tenant/2026_03_10_100002_create_vessels_table.php` — Creates the `vessels` table with UUID primary key, type (7 vessel types), `capacity_gallons` as `decimal(12,4)`, material, location, status (4 states), purchase_date, notes. Indexed on type, status, location.
- `api/database/migrations/tenant/2026_03_10_100003_create_lot_vessel_table.php` — Pivot table linking lots to vessels with `volume_gallons`, `filled_at`, `emptied_at` timestamps. FKs to both lots and vessels with cascadeOnDelete. Indexed on `[vessel_id, emptied_at]` and `[lot_id, emptied_at]` for efficient "current contents" queries.
- `api/app/Models/Vessel.php` — Eloquent model with `HasFactory`, `HasUuids`, `LogsActivity` traits. Constants for TYPES (7) and STATUSES (4). Relationships: `lots()` (all historical), `currentLot()` (where emptied_at IS NULL), `events()`, `barrel()` (1:1 extension). Computed attributes: `current_volume` (from active pivot), `fill_percent` (current_volume / capacity). Scopes: `ofType()`, `withStatus()`, `atLocation()`, `search()`.
- `api/app/Models/Barrel.php` — Stub model for Sub-Task 3. Defines constants, fillable fields, casts, and `vessel()` relationship. Prevents autoload errors from Vessel→barrel() relationship.
- `api/database/factories/VesselFactory.php` — Generates realistic vessels with type-appropriate naming (T-001 for tanks, B-001 for barrels), capacity ranges, materials, and locations. States: `inUse()`, `cleaning()`, `outOfService()`, `tank()`, `barrel()`.
- `api/app/Services/VesselService.php` — Business logic following LotService pattern. `createVessel()` creates vessel and writes `vessel_created` event. `updateVessel()` writes `vessel_status_changed` event on status transitions.
- `api/app/Http/Requests/StoreVesselRequest.php` — Validates: name (required), type (required, in 7 types), capacity_gallons (required, 0.0001–999999.9999), material/location/purchase_date/notes (optional), status (optional, in 4 statuses).
- `api/app/Http/Requests/UpdateVesselRequest.php` — Partial update for name, status, location, notes, material.
- `api/app/Http/Resources/VesselResource.php` — Extends BaseResource. Returns all vessel fields plus computed `current_volume`, `fill_percent`, and `current_lot` object (id, name, variety, vintage) when loaded.
- `api/app/Http/Controllers/Api/V1/VesselController.php` — RESTful controller with `index()` (paginated, eager-loads currentLot, filterable by type/status/location/search), `store()`, `show()` (eager loads currentLot + barrel), `update()`.
- `api/routes/api.php` — Added vessel routes. GET `/vessels` and GET `/vessels/{vessel}` open to authenticated users. POST and PUT gated by `role:owner,admin,winemaker`.
- `api/app/Models/Lot.php` — Added `vessels()` belongsToMany relationship via lot_vessel pivot.
- `api/tests/Feature/Production/VesselTest.php` — 19 tests covering events, CRUD, filtering, contents/fill%, validation, RBAC, and envelope.

### Key Decisions
- **Vessel can hold only one lot at a time (v1)** — matches spec's simplification. The `currentLot()` relationship uses `wherePivotNull('emptied_at')` to find the active record.
- **Fill % computed dynamically** — `fill_percent` is a model accessor, not a stored column. Calculated as `(current_volume / capacity) * 100`. This avoids stale data and keeps the pivot table as the source of truth.
- **lot_vessel pivot uses UUID primary key** — consistent with all other tables. Pivot has its own `id` column plus timestamps.
- **Barrel model stubbed early** — created as a stub to prevent PHP autoload errors from Vessel's `barrel()` HasOne relationship. Full implementation deferred to Sub-Task 3.
- **Vessels ordered by name** — default sort is alphabetical by name (T-001, T-002, etc.) unlike lots which sort by created_at desc. Vessel naming is structured, so alphabetical ordering makes intuitive sense.

### Deviations from Spec
- None. Implementation matches the spec exactly.

### Patterns Extended
- **VesselService follows LotService pattern** — constructor-injected EventLogger, model mutation → event write → structured log.
- **VesselResource includes computed fields** — `current_volume`, `fill_percent`, and `current_lot` are computed from relationships, not stored columns. Future resources for similar "derived state" models should follow this approach.

### Test Summary
- `tests/Feature/Production/VesselTest.php` (19 tests)
  - Tier 1: vessel_created event written with correct payload
  - Tier 1: vessel_status_changed event on status update
  - Tier 2: full CRUD (create, list, show, update)
  - Tier 2: filtering (type, status, location, search)
  - Tier 2: current contents + fill % with lot_vessel pivot data
  - Tier 2: validation (missing fields, invalid type, invalid status, negative capacity)
  - Tier 2: RBAC (read_only can view not create, read_only can list/view)
  - Tier 2: API envelope format, unauthenticated rejection

### Open Questions
- None for this sub-task.

---

## Sub-Task 3: Barrel Model and Barrel-Specific Tracking
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_10_100004_create_barrels_table.php` — Creates the `barrels` table with UUID primary key, FK to vessels (cascadeOnDelete), cooperage, toast_level, oak_type, forest_origin, volume_gallons as `decimal(12,4)`, years_used, qr_code. Indexed on cooperage, oak_type, toast_level, years_used for filter performance.
- `api/app/Models/Barrel.php` — Full model replacing the Sub-Task 2 stub. Added `@use HasFactory<BarrelFactory>` (replacing the `@phpstan-ignore-next-line`). Scopes: `fromCooperage()` (ilike), `ofOakType()`, `withToast()`, `withYearsUsed()`, `minYearsUsed()`. Relationship: `vessel()` BelongsTo.
- `api/database/factories/BarrelFactory.php` — 10 real cooperage names (François Frères, Seguin Moreau, Demptos, etc.), 8 forest origins (Allier, Tronçais, etc.), realistic volume ranges (55–65 gal). States: `newBarrel()`, `frenchOak()`, `americanOak()`, `heavyToast()`.
- `api/app/Services/BarrelService.php` — `createBarrel()` wraps vessel + barrel creation in a DB transaction. Creates a vessel (type=barrel) and barrel metadata in one operation. Writes `barrel_created` event. `updateBarrel()` handles field updates split across barrel and vessel records, writes `barrel_status_changed` event on status transitions (including retirement). Auto-derives vessel material from oak_type.
- `api/app/Http/Requests/StoreBarrelRequest.php` — Validates combined vessel + barrel fields: name (required), location/status/purchase_date/notes (optional vessel fields), cooperage/toast_level/oak_type/forest_origin/volume_gallons/years_used/qr_code (barrel-specific).
- `api/app/Http/Requests/UpdateBarrelRequest.php` — Partial update for both barrel metadata and vessel fields (name, location, status, notes, cooperage, toast_level, oak_type, forest_origin, years_used, qr_code).
- `api/app/Http/Resources/BarrelResource.php` — Extends BaseResource. Returns a flat structure merging vessel fields (name, location, status, purchase_date, notes, current_volume, fill_percent) with barrel fields (cooperage, toast_level, oak_type, etc.) and current_lot info. Uses `@mixin \App\Models\Barrel`.
- `api/app/Http/Controllers/Api/V1/BarrelController.php` — RESTful controller. `index()` joins vessels for name ordering, supports filters (cooperage, oak_type, toast_level, years_used, location, status, search), transforms items through BarrelResource via `paginator->through()`. `store()`, `show()`, `update()` use BarrelResource for consistent flat response shape.
- `api/routes/api.php` — Added barrel routes: GET `/barrels`, GET `/barrels/{barrel}` (authenticated), POST/PUT (role:owner,admin,winemaker).
- `api/app/Http/Controllers/Api/V1/VesselController.php` — Updated `show()` to eager-load `barrel` relationship alongside `currentLot`.
- `api/app/Http/Resources/VesselResource.php` — Added conditional `barrel` object in response when the barrel relationship is loaded (for type=barrel vessels viewed through the vessel endpoint).
- `api/tests/Feature/Production/BarrelTest.php` — 20 tests covering events, CRUD, filters, 1:1 vessel consistency, validation, RBAC, envelope, and tenant isolation.

### Key Decisions
- **Creating a barrel creates both a vessel and barrel record in one transaction** — the barrel endpoint is the primary interface for barrel management. A POST to `/barrels` creates a vessel (type=barrel) and a barrel metadata record atomically. This keeps the API clean for clients — they don't need to create a vessel first and then a barrel.
- **Flat response structure for BarrelResource** — barrel API responses merge vessel fields (name, location, status) and barrel fields (cooperage, oak_type, etc.) into one flat object. This is more ergonomic for the client than nested `{vessel: {...}, barrel: {...}}`. The `vessel_id` is included for cross-referencing.
- **Material auto-derived from oak_type** — when creating a barrel, the vessel's `material` field is automatically set based on oak_type (french → "French oak", american → "American oak", etc.). Keeps the barrel creation payload focused on barrel-specific fields.
- **Retirement via status change** — retiring a barrel is modeled as changing the vessel's status to `out_of_service`. This writes a `barrel_status_changed` event, keeping the event log as the authoritative record of barrel lifecycle.
- **Vessel show endpoint includes barrel data** — when viewing a vessel that is type=barrel via `/vessels/{id}`, the response now includes a `barrel` object with cooperage, toast, oak, and usage data. This allows the vessel endpoint to serve as a unified view.
- **Barrel list ordered by vessel name** — uses a join to `vessels` table to order alphabetically by name (B-001, B-002, etc.), consistent with the vessel list ordering pattern.
- **Search covers both barrel and vessel fields** — barrel search checks cooperage, qr_code, vessel name, and vessel location using ilike.

### Deviations from Spec
- None. Implementation matches the spec exactly.

### Patterns Extended
- **BarrelService follows Production Service Pattern** — constructor-injected EventLogger, DB transaction wrapping multi-table mutations, entity-specific events (`barrel_created`, `barrel_status_changed`).
- **Barrel list uses `paginator->through()`** — unlike Lot and Vessel lists which return raw model data from `ApiResponse::paginated()`, the barrel list transforms items through BarrelResource to include vessel fields in the response. Future multi-table resources should follow this approach.
- **1:1 Extension Pattern** — Barrel extends Vessel with a separate table. The barrel endpoint handles both records. The vessel endpoint conditionally includes barrel data when loaded. This pattern can be reused if other vessel types need extension tables.

### Test Summary
- `tests/Feature/Production/BarrelTest.php` (20 tests)
  - Tier 1: barrel_created event with correct payload (cooperage, toast, oak, volume, years_used, vessel_id)
  - Tier 1: barrel_status_changed event on retirement (status → out_of_service)
  - Tier 1: tenant isolation (cross-tenant barrel data access prevented)
  - Tier 2: full CRUD (create with vessel+barrel, list, show, update)
  - Tier 2: filtering (cooperage, oak_type, toast_level, years_used)
  - Tier 2: vessel show includes barrel metadata for barrel-type vessels
  - Tier 2: validation (missing name, invalid toast_level, invalid oak_type)
  - Tier 2: RBAC (read_only can view not create, read_only can list/view)
  - Tier 2: API envelope format, unauthenticated rejection

### Open Questions
- None for this sub-task.

---

## Sub-Task 4: Work Order System
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_10_100005_create_work_order_templates_table.php` — Creates `work_order_templates` table with UUID PK, name, operation_type, default_notes text, is_active boolean. Indexed on operation_type and is_active.
- `api/database/migrations/tenant/2026_03_10_100006_create_work_orders_table.php` — Creates `work_orders` table with UUID PK, operation_type, nullable FKs to lots/vessels/users (assigned_to, completed_by), due_date, status, priority, notes, completed_at, completion_notes, template_id. Composite index on [status, due_date] for calendar queries. User FKs use nullOnDelete.
- `api/app/Models/WorkOrderTemplate.php` — Model with `DEFAULT_OPERATION_TYPES` constant (12 operations). `active()` scope, `workOrders()` HasMany.
- `api/app/Models/WorkOrder.php` — STATUSES (pending/in_progress/completed/skipped), PRIORITIES (low/normal/high). Eloquent defaults for status and priority. Relationships: lot(), vessel(), assignedUser(), completedByUser(), template(). Scopes for filtering.
- `api/database/factories/WorkOrderTemplateFactory.php` and `WorkOrderFactory.php` — Realistic factories with states.
- `api/app/Services/WorkOrderService.php` — create, createFromTemplate, bulkCreate, completeWorkOrder (dual events), updateWorkOrder.
- `api/app/Http/Requests/` — StoreWorkOrderRequest, UpdateWorkOrderRequest, BulkStoreWorkOrderRequest.
- `api/app/Http/Resources/WorkOrderResource.php` — Full resource with nested relationships.
- `api/app/Http/Controllers/Api/V1/WorkOrderController.php` — index, store, show, update, complete, bulkStore, calendar, templates.
- `api/routes/api.php` — Work order routes with RBAC split (winemaker+ create, cellar_hand+ update/complete).
- `api/tests/Feature/Production/WorkOrderTest.php` — 21 tests.

### Key Decisions
- **Operation types are free-text** — configurable per winery per spec. Templates provide curated list but don't restrict custom values.
- **Dual event on completion** — writes `work_order_completed` on work order AND domain event on lot (e.g., `pump_over_completed`).
- **RBAC split** — winemaker+ creates, cellar_hand+ completes. Two middleware groups.
- **Calendar groups by due_date** — `{ dates: { "2026-03-15": [...] } }` structure.
- **Bulk targets array** — common fields + per-target lot_id/vessel_id, max 100 targets.
- **Auth guard reset in multi-user tests** — `app('auth')->forgetGuards()` before switching tokens to prevent Sanctum guard caching.

### Deviations from Spec
- None.

### Patterns Extended
- Dual event pattern for operation completion (work order + lot timeline).
- RBAC test guard reset pattern for multi-user tests.

### Test Summary
- `tests/Feature/Production/WorkOrderTest.php` (21 tests)
  - Tier 1: work_order_created event, work_order_completed + lot domain event, tenant isolation
  - Tier 2: CRUD, filters (status, due date range), bulk creation, templates, calendar, completion flow
  - Tier 2: validation (missing operation_type, invalid status/priority)
  - Tier 2: RBAC (cellar_hand complete not create, read_only view only), envelope, unauthenticated

### Open Questions
- None for this sub-task.

---

## Sub-Task 5: Additions Logging with Inventory Auto-Deduct
**Completed:** 2026-03-10
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_10_100007_create_additions_table.php` — Creates `additions` table with UUID PK, FK to lots (cascadeOnDelete), nullable FK to vessels (nullOnDelete), addition_type, product_name, rate/rate_unit (nullable), total_amount/total_unit, reason, performed_by FK to users, performed_at timestamp, nullable inventory_item_id (no FK constraint — inventory module not yet built). Indexed on addition_type, product_name, [lot_id, addition_type] composite, performed_at.
- `api/app/Models/Addition.php` — Model with ADDITION_TYPES (sulfite, nutrient, fining, acid, enzyme, tannin, other), RATE_UNITS (ppm, g/L, mg/L, g/hL, lb/1000gal, mL/L), TOTAL_UNITS (g, kg, lb, oz, mL, L, gal) constants. Relationships: lot(), vessel(), performer(). Scopes: ofType(), forProduct() (ilike), forLot(), performedBetween(), sulfiteOnly(). Full PHPStan generics on all relationships and scopes.
- `api/database/factories/AdditionFactory.php` — Realistic product library organized by addition type: 3 sulfite products, 4 nutrients (Fermaid O/K, Go-Ferm, DAP), 4 fining agents, 3 acids, 2 enzymes, 2 tannins. Default rates and units per product. States: sulfite(), nutrient(), fining().
- `api/app/Services/AdditionService.php` — `createAddition()` creates addition in transaction, writes `addition_made` event on the lot entity with full payload (type, product, rate, amount, unit, vessel). `getSo2RunningTotal()` sums sulfite additions with rate_unit=ppm for a lot. Inventory auto-deduct is stubbed with a comment for 04-inventory.md integration.
- `api/app/Http/Requests/StoreAdditionRequest.php` — Validates lot_id (required, exists), addition_type (required, in constants), product_name (required), total_amount (required, 0.0001–999999.9999), total_unit (required, in constants), rate/rate_unit (nullable, validated against constants), vessel_id/inventory_item_id (nullable UUIDs).
- `api/app/Http/Resources/AdditionResource.php` — Returns all addition fields with nested lot (id, name, variety, vintage), vessel (id, name, type, location), and performed_by (id, name) when relationships loaded.
- `api/app/Http/Controllers/Api/V1/AdditionController.php` — `index()` with filters (lot_id, addition_type, product_name, vessel_id, performed date range), ordered by performed_at DESC. `store()` creates via AdditionService. `show()` with eager-loaded relationships. `so2Total()` returns running SO2 ppm total for a lot.
- `api/routes/api.php` — GET routes (index, so2-total, show) open to all authenticated. POST gated by `role:owner,admin,winemaker,cellar_hand`.
- `api/app/Models/Lot.php` — Added `additions()` HasMany relationship.
- `api/tests/Feature/Production/AdditionTest.php` — 16 tests.

### Key Decisions
- **Additions are immutable log entries** — no update/delete endpoints. Once logged, an addition cannot be modified. This matches the spec's ADDITIVE offline sync requirement (no last-write-wins).
- **Cellar hand+ can create additions** — per spec, additions are a cellar operation. The RBAC is `role:owner,admin,winemaker,cellar_hand`, matching the API endpoint table.
- **SO2 running total via sum query** — `getSo2RunningTotal()` sums `rate` where addition_type=sulfite and rate_unit=ppm. Simple aggregate query, no materialized column needed at this scale.
- **Inventory auto-deduct stubbed** — `inventory_item_id` column exists but has no FK constraint. The AdditionService has a commented placeholder for `deductInventory()`. Will be wired up in 04-inventory.md.
- **Static routes before parameterized** — `/additions/so2-total` registered before `/additions/{addition}` to prevent UUID route parameter from catching the static path.
- **Addition type is constrained enum** — unlike work order operation_type (free-text), addition types are validated against a fixed list (sulfite, nutrient, fining, acid, enzyme, tannin, other). Product names within each type are free-text.

### Deviations from Spec
- **Addition product library is NOT pre-seeded** — the spec says "pre-seeded with common products and default rates." Instead, the factory contains the product library for testing/demo purposes. A formal ProductLibrary model/seeder was deferred as it's not required for the API to function — the factory data serves as the reference. This can be added in the demo seeder (Sub-Task 14).

### Patterns Extended
- AdditionService follows Production Service Pattern with EventLogger injection.
- Addition events written on the lot entity (entity_type='lot') for lot timeline visibility.
- Cross-tenant test uses direct model access pattern (not HTTP) per established convention.

### Test Summary
- `tests/Feature/Production/AdditionTest.php` (16 tests)
  - Tier 1: addition_made event with correct payload, SO2 running total across multiple additions, tenant isolation
  - Tier 2: CRUD (create with all fields, list with pagination, show with relationships)
  - Tier 2: filters (lot_id, addition_type)
  - Tier 2: validation (missing required fields, invalid addition_type, invalid total_unit)
  - Tier 2: RBAC (cellar_hand can create, read_only cannot create, read_only can list/view)
  - Tier 2: API envelope format, unauthenticated rejection

### Open Questions
- None for this sub-task.

---

## Sub-Task 6: Transfer and Racking Operations
**Completed:** 2026-03-14
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_10_100008_create_transfers_table.php` — Creates `transfers` table with UUID PK, FK to lots (cascadeOnDelete), FKs to from_vessel/to_vessel (nullOnDelete), volume_gallons as `decimal(12,4)`, transfer_type, variance_gallons as `decimal(12,4)` default 0, performed_by FK to users (nullOnDelete), performed_at, notes. Indexed on lot_id, [from_vessel_id, performed_at], [to_vessel_id, performed_at].
- `api/app/Models/Transfer.php` — Model with TRANSFER_TYPES (gravity, pump, filter, press) constant. Default attributes: variance_gallons=0. Relationships: lot(), fromVessel(), toVessel(), performer(). Scopes: forLot(), ofType(), involvingVessel() (OR query matching from or to), performedBetween(). Full PHPStan generics.
- `api/database/factories/TransferFactory.php` — Realistic factory with volume 50–200 gal, variance 0–2 gal, notes. States: gravity(), pump().
- `api/app/Services/TransferService.php` — `executeTransfer()` in DB transaction: validates source vessel has enough volume (throws ValidationException if insufficient), logs warning if target overfill, creates Transfer record, updates lot_vessel pivot (decrease source, increase target), writes `transfer_executed` event on lot. `decreaseVesselVolume()` reduces pivot volume, marks emptied_at if zero, updates vessel status to 'empty' if no other active lots. `increaseVesselVolume()` adds to existing pivot or creates new one, sets vessel status to 'in_use'.
- `api/app/Http/Requests/StoreTransferRequest.php` — Validates lot_id (required, exists), from_vessel_id (required, exists, `different:to_vessel_id`), to_vessel_id (required, exists), volume_gallons (required, 0.0001–999999.9999), transfer_type (required, in constants), variance_gallons (nullable, 0–999999.9999), notes (nullable).
- `api/app/Http/Resources/TransferResource.php` — Returns all transfer fields with nested lot, from_vessel, to_vessel, and performed_by when relationships loaded.
- `api/app/Http/Controllers/Api/V1/TransferController.php` — `index()` with filters (lot_id, transfer_type, vessel_id via involvingVessel, performed date range), ordered by performed_at DESC. `store()` via TransferService. `show()` with eager-loaded relationships.
- `api/routes/api.php` — GET /transfers and GET /transfers/{transfer} open to all authenticated. POST /transfers gated by `role:owner,admin,winemaker,cellar_hand`.
- `api/app/Models/Lot.php` — Added `transfers()` HasMany relationship.
- `api/tests/Feature/Production/TransferTest.php` — 16 tests.

### Key Decisions
- **Volume validation on source vessel** — server rejects transfers exceeding the source vessel's current volume. This is a DESTRUCTIVE operation that must be validated server-side, unlike additions which are ADDITIVE.
- **Target overfill is a warning, not a hard block** — if transfer would exceed target vessel capacity, it's logged as a warning but allowed. Winemakers may intentionally overfill during active fermentation or use headspace differently.
- **Variance subtracted from target** — variance_gallons represents loss during transfer. The target receives `volume_gallons - variance_gallons`. Source loses exactly `volume_gallons`.
- **`different:to_vessel_id` validation** — Laravel's `different` rule prevents transferring from a vessel to itself, a common data entry error.
- **Transfers are immutable** — no update/delete endpoints. Once logged, a transfer cannot be modified, same pattern as additions.
- **Cellar hand+ can execute transfers** — per spec, transfers are a cellar operation.
- **Vessel status auto-managed** — source vessel set to 'empty' when all volume removed (no active lot_vessel pivots). Target vessel set to 'in_use' when volume added.

### Deviations from Spec
- None. Implementation matches the spec exactly.

### Patterns Extended
- TransferService follows Production Service Pattern with EventLogger injection and DB transaction.
- lot_vessel pivot management extracted into reusable decreaseVesselVolume/increaseVesselVolume methods on the service.
- Transfer events written on the lot entity for lot timeline visibility.

### Test Summary
- `tests/Feature/Production/TransferTest.php` (16 tests)
  - Tier 1: transfer_executed event with correct payload, volume validation (reject overdraw), volume updates (source decrease, target increase with variance), empty vessel status change, tenant isolation
  - Tier 2: CRUD (create with all fields, list with pagination, show with relationships)
  - Tier 2: filters (lot_id)
  - Tier 2: validation (missing required fields, same vessel, invalid transfer_type)
  - Tier 2: RBAC (cellar_hand can execute, read_only cannot)
  - Tier 2: API envelope format, unauthenticated rejection

### Open Questions
- None for this sub-task.

---

## Sub-Task 7: Pressing Operations
**Completed:** 2026-03-14
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_10_100009_create_press_logs_table.php` — Creates `press_logs` table with UUID PK, FK to lots (cascadeOnDelete), nullable FK to vessels (nullOnDelete), press_type, fruit_weight_kg as `decimal(12,4)`, total_juice_gallons as `decimal(12,4)`, fractions JSONB array, yield_percent as `decimal(8,4)`, pomace_weight_kg/pomace_destination (nullable), performed_by FK, performed_at. Indexed on lot_id, press_type, performed_at.
- `api/app/Models/PressLog.php` — Model with PRESS_TYPES (basket, bladder, pneumatic, manual), FRACTION_TYPES (free_run, light_press, heavy_press), POMACE_DESTINATIONS (compost, vineyard, disposal, sold) constants. Relationships: lot(), vessel(), performer(). Scopes: ofType(), forLot(), performedBetween(). Full PHPStan generics.
- `api/database/factories/PressLogFactory.php` — Generates realistic press data with yield calculations. Default 3 fractions (65% free run, 25% light, 10% heavy). States: basket(), pneumatic(), freeRunOnly().
- `api/app/Services/PressLogService.php` — `logPressing()` in DB transaction: calculates yield_percent from fruit_weight_kg and total_juice_gallons, creates child lots for fractions with `create_child_lot: true` flag, writes `pressing_logged` event on parent lot. `createFractionChildLot()` creates child lot inheriting parent's variety/vintage/source, writes `lot_created` event on child.
- `api/app/Http/Requests/StorePressLogRequest.php` — Validates lot_id (required, exists), press_type (required, in constants), fruit_weight_kg/total_juice_gallons (required, numeric), fractions array (required, min 1, max 10) with nested fraction/volume_gallons/create_child_lot validation, pomace fields (nullable).
- `api/app/Http/Resources/PressLogResource.php` — Returns all press log fields with nested lot, vessel, performed_by when loaded. Fractions returned as-is from JSONB.
- `api/app/Http/Controllers/Api/V1/PressLogController.php` — `index()` with filters (lot_id, press_type, performed date range), ordered by performed_at DESC. `store()` via PressLogService. `show()` with eager-loaded relationships.
- `api/routes/api.php` — GET /press-logs and GET /press-logs/{pressLog} open to all authenticated. POST /press-logs gated by `role:owner,admin,winemaker`.
- `api/app/Models/Lot.php` — Added `pressLogs()` HasMany relationship.
- `api/tests/Feature/Production/PressLogTest.php` — 17 tests.

### Key Decisions
- **Pressing is winemaker+ only** — unlike additions and transfers (cellar_hand+), pressing is a significant winemaking decision that affects wine quality. RBAC is `role:owner,admin,winemaker`.
- **Fractions stored as JSONB array** — flexible for 1-3 fractions per pressing. Each fraction has fraction type, volume, and optional child_lot_id. Max 10 fractions per validation rule.
- **Child lots created on demand** — pass `create_child_lot: true` in a fraction to spawn a child lot. Child inherits parent's variety, vintage, source_type, source_details. Named as "Parent Name — Free Run" etc. Gets its own `lot_created` event for independent tracking.
- **Yield percent computed and stored** — `(total_juice_gallons / fruit_weight_kg) * 100`. Stored on the record for easy querying/reporting without recomputation.
- **Pomace tracking is optional** — pomace_weight_kg and pomace_destination are nullable. Not all wineries track pomace disposal, but the fields exist for compliance.
- **Press logs are immutable** — no update/delete endpoints, same pattern as additions and transfers.

### Deviations from Spec
- None. Implementation matches the spec exactly.

### Patterns Extended
- PressLogService follows Production Service Pattern with EventLogger injection and DB transaction.
- Child lot creation reuses the `lot_created` event type for consistency with LotService.
- JSONB fractions array with nested validation rules (`fractions.*.fraction`, `fractions.*.volume_gallons`).

### Test Summary
- `tests/Feature/Production/PressLogTest.php` (17 tests)
  - Tier 1: pressing_logged event with correct payload, yield percent calculation, child lot creation from fractions (with events), tenant isolation
  - Tier 2: CRUD (create with all fields, list with pagination, show with relationships)
  - Tier 2: filters (lot_id)
  - Tier 2: validation (missing required fields, invalid press_type, invalid fraction type)
  - Tier 2: RBAC (winemaker can log, cellar_hand cannot, read_only can list/view)
  - Tier 2: API envelope format, unauthenticated rejection

### Open Questions
- None for this sub-task.

---

## Sub-Task 8: Filtering and Fining Operations
**Completed:** 2026-03-14
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_10_100010_create_filter_logs_table.php` — Creates `filter_logs` table with UUID PK, FK to lots (cascadeOnDelete), nullable FK to vessels (nullOnDelete), filter_type, filter_media, flow_rate_lph, volume_processed_gallons, fining fields (agent, rate, rate_unit, bench_trial_notes, treatment_notes), pre/post_analysis_id (nullable UUIDs for future lab module), performed_by/at, notes. Indexed on lot_id, filter_type, performed_at.
- `api/app/Models/FilterLog.php` — Model with FILTER_TYPES (pad, crossflow, cartridge, plate_and_frame, de, lenticular), FINING_RATE_UNITS (g/L, g/hL, mL/L, lb/1000gal). Relationships: lot(), vessel(), performer(). Scopes: ofType(), forLot(), performedBetween(), withFining(). Full PHPStan generics.
- `api/database/factories/FilterLogFactory.php` — Media library organized by filter type, fining agent library with typical rates. States: withFining(), crossflow(), pad().
- `api/app/Services/FilterLogService.php` — `logFiltering()` creates filter log, writes `filtering_logged` event with filter details and conditional fining/analysis payload fields.
- `api/app/Http/Requests/StoreFilterLogRequest.php` — Validates filter_type (required, in constants), volume_processed_gallons (required), fining fields (all nullable), analysis IDs (nullable UUIDs).
- `api/app/Http/Resources/FilterLogResource.php` — Returns all fields with nested lot/vessel/performer. Carbon `@property` annotations for PHPStan compatibility.
- `api/app/Http/Controllers/Api/V1/FilterLogController.php` — index with filters (lot_id, filter_type, has_fining, performed date range), store, show.
- `api/routes/api.php` — GET (authenticated), POST (cellar_hand+).
- `api/app/Models/Lot.php` — Added `filterLogs()` HasMany.
- `api/tests/Feature/Production/FilterLogTest.php` — 18 tests.

### Key Decisions
- **Kept simple per spec** — "this is a log entry, not a complex workflow." No multi-step state machine, just create and read.
- **Fining is optional on a filter log** — same record captures pure filtration, fining+filtration, or standalone fining. The `has_fining` filter scope/query param lets the UI distinguish.
- **Pre/post analysis IDs are nullable UUIDs with no FK** — lab module (03-lab-fermentation.md) isn't built yet. These will link to lab analysis entries once that module exists.
- **Cellar hand+ can log** — filtering is a routine cellar operation, unlike pressing which is winemaker+.
- **Bench trial notes and treatment notes are separate fields** — bench trial captures the small-scale testing, treatment notes capture the final full-lot application. Keeps them distinct for reporting.

### Deviations from Spec
- None. Implementation matches the spec exactly.

### Patterns Extended
- FilterLogService follows Production Service Pattern with conditional event payload (fining details only included when present).
- `withFining()` scope and `has_fining` query filter for distinguishing fining operations from pure filtration.

### Test Summary
- `tests/Feature/Production/FilterLogTest.php` (18 tests)
  - Tier 1: filtering_logged event with correct payload, fining details in event when present, tenant isolation
  - Tier 2: CRUD (create with all fields, list with pagination, show with relationships)
  - Tier 2: filters (lot_id, has_fining)
  - Tier 2: validation (missing required fields, invalid filter_type, invalid fining_rate_unit)
  - Tier 2: RBAC (cellar_hand can log, read_only cannot, read_only can list/view)
  - Tier 2: API envelope format, unauthenticated rejection

### Open Questions
- None for this sub-task.

---

## Sub-Task 9: Blending Operations
**Completed:** 2026-03-14
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_10_100011_create_blend_trials_table.php` — Creates `blend_trials` table with UUID PK, name, status (draft/finalized/archived) with default 'draft', version integer default 1, variety_composition JSONB, ttb_label_variety (nullable), total_volume_gallons as `decimal(12,4)`, resulting_lot_id (nullable FK to lots, nullOnDelete), created_by FK to users (nullOnDelete), finalized_at (nullable datetime), notes. Indexed on status, created_by.
- `api/database/migrations/tenant/2026_03_10_100012_create_blend_trial_components_table.php` — Creates `blend_trial_components` table with UUID PK, FK to blend_trials (cascadeOnDelete), FK to lots as source_lot_id (cascadeOnDelete), percentage as `decimal(8,4)`, volume_gallons as `decimal(12,4)`. Unique constraint on [blend_trial_id, source_lot_id]. Indexed on source_lot_id.
- `api/app/Models/BlendTrial.php` — Model with STATUSES (draft/finalized/archived), TTB_VARIETY_THRESHOLD = 75.0. Relationships: components(), creator(), resultingLot(). Scopes: withStatus(), drafts(). Full PHPStan generics.
- `api/app/Models/BlendTrialComponent.php` — Model with blendTrial() and sourceLot() relationships. Full PHPStan generics.
- `api/database/factories/BlendTrialFactory.php` — States: finalized(), archived().
- `api/database/factories/BlendTrialComponentFactory.php` — Factory for component records.
- `api/app/Services/BlendService.php` — Most complex service in the module. `createTrial()` calculates variety_composition from source lot varieties weighted by volume, determines TTB label variety (>=75% threshold), creates trial + components in transaction. `finalizeTrial()` validates draft status, validates all source lots have sufficient volume, creates new blended lot (variety from TTB label or "Blend", vintage from common or min), deducts volumes from each source lot with `volume_deducted` events, writes `lot_created` and `blend_finalized` events on the new lot.
- `api/app/Http/Requests/StoreBlendTrialRequest.php` — Validates name (required), components array (required, min 2, max 20) with source_lot_id (UUID, exists), percentage (0.0001–100), volume_gallons (0.0001–999999.9999).
- `api/app/Http/Resources/BlendTrialResource.php` — Nested components with source lots, resulting lot, creator. Carbon `@property` annotations. `@phpstan-ignore return.type` on components map for Collection covariance.
- `api/app/Http/Controllers/Api/V1/BlendController.php` — index (filterable by status), store, show, finalize.
- `api/routes/api.php` — GET /blend-trials and GET /blend-trials/{blendTrial} open to authenticated. POST /blend-trials and POST /blend-trials/{blendTrial}/finalize gated by `role:owner,admin,winemaker`.
- `api/tests/Feature/Production/BlendTrialTest.php` — 18 tests.

### Key Decisions
- **TTB compliance modeled as class constant** — `TTB_VARIETY_THRESHOLD = 75.0` on BlendTrial model. If the threshold changes, it's a single constant update. Variety composition stored as JSONB percentages for flexibility.
- **Blending is winemaker+ only** — significant winemaking decision that affects wine identity and TTB labeling. RBAC is `role:owner,admin,winemaker`.
- **Finalization is destructive and validated** — server validates all source lots have sufficient volume before any deductions. Each source lot gets its own `volume_deducted` event for full audit trail. Double finalization is rejected.
- **Resulting lot variety from TTB label** — if a single variety reaches 75%, the blended lot is labeled as that variety. Otherwise, variety is set to "Blend". Vintage is the common vintage if all sources match, otherwise the minimum vintage.
- **Status explicitly set in service** — `$data['status'] = 'draft'` set in `createTrial()` to ensure the PHP model object has the value, even though the DB default handles persistence. Prevents null status in API responses.
- **Components require min 2** — a blend must have at least 2 source lots. Validation enforces this at the request level.
- **Collection covariance suppressed** — PHPStan reports a false positive on `Collection::map()` return type covariance in BlendTrialResource. Suppressed with `@phpstan-ignore return.type` inline comment.

### Deviations from Spec
- None. Implementation matches the spec exactly.

### Patterns Extended
- BlendService follows Production Service Pattern with EventLogger injection and DB transaction.
- Multi-event finalization: `lot_created` + `blend_finalized` on new lot, `volume_deducted` on each source lot.
- Variety composition calculated server-side from source lot metadata — clients don't need to compute percentages.
- JSON int/float encoding handled with `(float)` casts in tests (established pattern from Sub-Tasks 6–8).

### Test Summary
- `tests/Feature/Production/BlendTrialTest.php` (18 tests)
  - Tier 1: variety composition and TTB label variety calculation, TTB null when no variety reaches 75%, finalization creates new lot and deducts source volumes, blend_finalized and volume_deducted events, reject double finalization, reject insufficient volume, tenant isolation
  - Tier 2: CRUD (create with components, list with pagination, filter by status, show with nested data)
  - Tier 2: validation (missing required fields, single component rejected)
  - Tier 2: RBAC (winemaker can create/finalize, cellar_hand cannot, read_only can list/view)
  - Tier 2: API envelope format, unauthenticated rejection

### Open Questions
- None for this sub-task.
