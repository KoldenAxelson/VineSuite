# Production Core (Cellar Management)

## Phase
Phase 2

## Dependencies
- `01-foundation.md` — requires tenant schema, event log, auth, Filament shell

## Goal
Build the complete cellar production management system — the daily workhorse of the platform. This module tracks wine from grape reception through fermentation, aging, blending, and bottling. Every operation writes to the event log, making TTB reporting (Phase 3) a downstream aggregation query rather than manual data entry. This is the core value proposition for the Starter tier.

## Data Models

- **Lot** — `id` (UUID), `name`, `variety`, `vintage` (year), `source_type` (estate/purchased), `source_details` (JSON — vineyard, block, grower), `volume_gallons` (decimal), `status` (in_progress/aging/finished/bottled/sold/archived), `parent_lot_id` (nullable, for splits/blends), `created_at`, `updated_at`
  - Relationships: hasMany Events, hasMany LabAnalyses, hasMany FermentationEntries, belongsTo ParentLot, hasMany ChildLots, belongsToMany Vessels (via lot_vessel pivot)

- **Vessel** — `id` (UUID), `name`, `type` (tank/barrel/flexitank/tote/demijohn/concrete_egg/amphora), `capacity_gallons` (decimal), `material`, `location`, `status` (in_use/empty/cleaning/out_of_service), `purchase_date`, `notes`, `created_at`, `updated_at`
  - Relationships: belongsToMany Lots (via lot_vessel pivot), hasMany Events

- **Barrel** — `id` (UUID), `vessel_id` (FK), `cooperage`, `toast_level` (light/medium/medium_plus/heavy), `oak_type` (french/american/hungarian/other), `forest_origin`, `volume_gallons` (decimal), `years_used` (integer), `qr_code`, `created_at`, `updated_at`
  - Relationships: belongsTo Vessel

- **LotVessel** (pivot) — `id`, `lot_id`, `vessel_id`, `volume_gallons`, `filled_at`, `emptied_at`

- **WorkOrder** — `id` (UUID), `operation_type`, `lot_id` (nullable), `vessel_id` (nullable), `assigned_to` (FK users), `due_date`, `status` (pending/in_progress/completed/skipped), `priority` (low/normal/high), `notes`, `completed_at`, `completed_by`, `completion_notes`, `template_id` (nullable), `created_at`, `updated_at`
  - Relationships: belongsTo Lot, belongsTo Vessel, belongsTo AssignedUser, belongsTo CompletedByUser

- **WorkOrderTemplate** — `id`, `name`, `operation_type`, `default_notes`, `is_active`, `created_at`, `updated_at`

- **Addition** — `id` (UUID), `lot_id`, `vessel_id` (nullable), `addition_type`, `product_name`, `rate`, `rate_unit`, `total_amount`, `total_unit`, `reason`, `performed_by`, `performed_at`, `inventory_item_id` (nullable — for auto-deduct), `created_at`
  - Relationships: belongsTo Lot, belongsTo Vessel, belongsTo InventoryItem

- **Transfer** — `id` (UUID), `lot_id`, `from_vessel_id`, `to_vessel_id`, `volume_gallons`, `transfer_type` (gravity/pump/filter/press), `variance_gallons` (loss), `performed_by`, `performed_at`, `notes`, `created_at`

- **BottlingRun** — `id` (UUID), `lot_id`, `date`, `format` (750ml/375ml/1.5L/etc), `bottles_filled`, `waste_percent`, `breakage_count`, `fill_level_notes`, `dissolved_oxygen`, `final_so2`, `notes`, `created_at`
  - Relationships: belongsTo Lot, hasMany BottlingComponents

- **BottlingComponent** — `id`, `bottling_run_id`, `component_type` (bottle/cork/screw_cap/capsule/label_front/label_back/label_neck/carton), `inventory_item_id`, `quantity_used`

- **BlendTrial** — `id` (UUID), `name`, `status` (draft/finalized), `tasting_notes`, `created_at`, `updated_at`
  - Relationships: hasMany BlendTrialComponents

- **BlendTrialComponent** — `id`, `blend_trial_id`, `source_lot_id`, `percentage`, `volume_gallons`

## Sub-Tasks

### 1. Lot model, migration, and basic CRUD
**Description:** Create the Lot model with migration, factory, and basic CRUD operations. Every lot creation writes a `lot_created` event to the event log.
**Files to create:**
- `api/app/Models/Lot.php`
- `api/database/migrations/xxxx_create_lots_table.php`
- `api/database/factories/LotFactory.php`
- `api/app/Http/Controllers/Api/V1/LotController.php`
- `api/app/Http/Requests/StoreLotRequest.php`
- `api/app/Http/Resources/LotResource.php`
- `api/app/Services/LotService.php` — business logic, calls EventLogger on create
**Acceptance criteria:**
- Lots can be created, read, updated (status only), listed with filters (variety, vintage, status)
- Every lot creation writes a `lot_created` event with full payload to event log
- Lot volumes are tracked in the tenant's preferred unit (gallons or liters, stored as gallons internally)
- Lot search works by name, variety, vintage
- Factory generates realistic lot data for testing
**Gotchas:** Volume is always stored internally as gallons. Display conversion happens at the API response level based on winery preference. Lot names follow winery-specific conventions — keep the name field free-text.

### 2. Vessel model, migration, and CRUD
**Description:** Create the Vessel model with support for all vessel types (tank, barrel, flexitank, etc). Include current contents tracking via the lot_vessel pivot.
**Files to create:**
- `api/app/Models/Vessel.php`
- `api/database/migrations/xxxx_create_vessels_table.php`
- `api/database/migrations/xxxx_create_lot_vessel_table.php`
- `api/database/factories/VesselFactory.php`
- `api/app/Http/Controllers/Api/V1/VesselController.php`
- `api/app/Http/Resources/VesselResource.php`
**Acceptance criteria:**
- Vessels can be created with type, capacity, material, location
- Current contents (which lot, how many gallons, fill %) queryable per vessel
- Vessel status changes are logged
- Fill percentage calculated from current volume / capacity
- Vessel list filterable by type, status, location
**Gotchas:** A vessel can contain wine from only one lot at a time (simplification for v1). The lot_vessel pivot tracks historical fills too (via filled_at/emptied_at).

### 3. Barrel model and barrel-specific tracking
**Description:** Extend the vessel model with barrel-specific fields — cooperage, toast, oak type, forest origin, years used. Barrels are vessels with extra metadata.
**Files to create:**
- `api/app/Models/Barrel.php`
- `api/database/migrations/xxxx_create_barrels_table.php`
- `api/app/Http/Controllers/Api/V1/BarrelController.php`
- `api/app/Http/Resources/BarrelResource.php`
**Acceptance criteria:**
- Barrels are created as a vessel + barrel record (1:1 relationship)
- QR code field supports barcode/QR label for scanning
- Years used increments on vintage year rollover
- Barrel can be retired/disposed (status change + event)
- Barrel list filterable by cooperage, oak type, toast, years used
**Gotchas:** Time-in-oak tracking per lot per barrel is derived from the lot_vessel pivot timestamps, not a separate field.

### 4. Work order system
**Description:** Build the work order system — the daily cellar workflow. Winemaker creates work orders, assigns to cellar hands, cellar hands complete them (eventually via mobile app, but portal-first for now).
**Files to create:**
- `api/app/Models/WorkOrder.php`
- `api/app/Models/WorkOrderTemplate.php`
- `api/database/migrations/xxxx_create_work_orders_table.php`
- `api/database/migrations/xxxx_create_work_order_templates_table.php`
- `api/app/Http/Controllers/Api/V1/WorkOrderController.php`
- `api/app/Services/WorkOrderService.php` — handles completion logic, event logging
**Acceptance criteria:**
- Work orders can be created with operation type, lot, vessel, assignee, due date
- Templates allow one-click creation of common operations
- Completing a work order writes the appropriate event to the event log
- Bulk creation works (same operation across multiple lots/vessels)
- Work orders are listable by status, due date, assignee
- Calendar view data available (grouped by date)
**Gotchas:** Operation types are configurable per winery (not hardcoded). Seed common types: Pump Over, Punch Down, Rack, Add SO2, Fine, Filter, Transfer, Top, Sample, Barrel Down, Press, Inoculate. Work order completion is the trigger for most event log writes.

### 5. Additions logging with inventory auto-deduct
**Description:** Build the additions tracking system. When a cellar hand adds SO2, nutrients, fining agents, etc. to a lot, it's logged as an event and auto-deducts from the raw materials inventory.
**Files to create:**
- `api/app/Models/Addition.php`
- `api/database/migrations/xxxx_create_additions_table.php`
- `api/app/Http/Controllers/Api/V1/AdditionController.php`
- `api/app/Services/AdditionService.php` — event log + inventory deduction
**Acceptance criteria:**
- Additions record: lot, type, product, rate, total amount, reason
- Each addition writes an `addition_made` event to the event log
- If linked to an inventory item, auto-deducts the quantity used
- SO2 additions maintain a running total of free SO2 per lot
- Addition product library is pre-seeded with common products and default rates
**Gotchas:** Auto-deduct from inventory depends on inventory module (task 04-inventory.md). Build the addition log first with optional inventory linkage — make the auto-deduct work once inventory exists. Addition events are ADDITIVE for offline sync — if two cellar hands both add SO2 offline, both additions apply (no last-write-wins).

### 6. Transfer and racking operations
**Description:** Implement wine transfers between vessels. Transfers move wine from one vessel to another, with volume tracking and loss/variance recording.
**Files to create:**
- `api/app/Models/Transfer.php`
- `api/database/migrations/xxxx_create_transfers_table.php`
- `api/app/Http/Controllers/Api/V1/TransferController.php`
- `api/app/Services/TransferService.php` — volume validation, event log, vessel state update
**Acceptance criteria:**
- Transfer records: from vessel, to vessel, volume, type, variance/loss
- Writes `transfer_executed` event to event log
- Source vessel volume decreases, target vessel volume increases
- Variance (loss) is recorded separately for COGS and TTB purposes
- Cannot transfer more gallons than the source vessel contains (server validation)
- Cannot exceed target vessel capacity (warning, not hard block — overfilling is physically possible)
**Gotchas:** Transfers are DESTRUCTIVE operations for offline sync — the server must validate volume on receipt. If two offline devices try to transfer more than a vessel contains, the second one gets a conflict error for manual resolution.

### 7. Pressing operations
**Description:** Log pressing operations — converting grape must to juice. Records press fractions (free run, light press, heavy press), yield calculations, and pomace disposal.
**Files to create:**
- `api/app/Models/PressLog.php`
- `api/database/migrations/xxxx_create_press_logs_table.php`
- `api/app/Http/Controllers/Api/V1/PressLogController.php`
**Acceptance criteria:**
- Press log records: lot, press type, press fractions with volumes
- Yield calculation (juice yield % from fruit weight) computed and stored
- Pomace disposal record (weight, destination)
- Writes `pressing_logged` event
- Creates child lots for different press fractions if requested
**Gotchas:** Pressing may create multiple child lots from one parent lot (free run vs. press fraction). Each child lot gets its own event stream from that point.

### 8. Filtering and fining operations
**Description:** Log filtering and fining operations with pre/post analysis comparison support.
**Files to create:**
- `api/app/Models/FilterLog.php`
- `api/database/migrations/xxxx_create_filter_logs_table.php`
- `api/app/Http/Controllers/Api/V1/FilterLogController.php`
**Acceptance criteria:**
- Filter log: date, lot, filter type, filter media, flow rate, volume processed
- Pre/post filter analysis comparison viewable
- Fining trial notes (bench trial → final treatment) recorded
- Writes `filtering_logged` event
**Gotchas:** Keep it simple — this is a log entry, not a complex workflow. The analysis comparison references lab analysis entries (from 03-lab-fermentation.md).

### 9. Blending operations
**Description:** Build the blend trial and finalization system. Winemaker creates trial blends, compares them, finalizes one — which creates a new blended lot and deducts volumes from source lots.
**Files to create:**
- `api/app/Models/BlendTrial.php`
- `api/app/Models/BlendTrialComponent.php`
- `api/database/migrations/xxxx_create_blend_trials_table.php`
- `api/database/migrations/xxxx_create_blend_trial_components_table.php`
- `api/app/Http/Controllers/Api/V1/BlendController.php`
- `api/app/Services/BlendService.php`
**Acceptance criteria:**
- Create blend trials with multiple source lots and percentages
- Save and compare multiple trial versions
- Finalize: creates new lot, deducts proportional volumes from each source lot
- Calculates variety composition (% Cabernet, % Merlot, etc.)
- Checks TTB labeling compliance on blend composition (>75% of a variety to label as that variety)
- Writes `blend_finalized` event with full composition details
**Gotchas:** Cost rolls through blends proportionally (critical for COGS). Volume deductions from source lots must write their own events (one deduction event per source lot). Blend composition affects TTB labeling rules — store the exact percentages.

### 10. Lot splitting
**Description:** Implement lot splitting — dividing one lot into two or more child lots. Used when a winemaker wants to treat portions of a lot differently.
**Files to create:**
- `api/app/Services/LotSplitService.php`
- `api/app/Http/Controllers/Api/V1/LotSplitController.php`
**Acceptance criteria:**
- Split a lot into N child lots with specified volumes
- Parent lot volume decreases, child lots created with volumes
- Child lots inherit parent's variety, vintage, source details
- Each child lot gets its own event stream going forward
- Writes `lot_split` event on parent with child lot references
**Gotchas:** COGS accumulated on the parent lot must be split proportionally to the child lots based on volume ratio.

### 11. Bottling operations
**Description:** Build the bottling run system. Bottling converts bulk wine (gallons) into case goods (bottles/cases), consumes packaging materials, and creates SKU inventory entries.
**Files to create:**
- `api/app/Models/BottlingRun.php`
- `api/app/Models/BottlingComponent.php`
- `api/database/migrations/xxxx_create_bottling_runs_table.php`
- `api/database/migrations/xxxx_create_bottling_components_table.php`
- `api/app/Http/Controllers/Api/V1/BottlingRunController.php`
- `api/app/Services/BottlingService.php`
**Acceptance criteria:**
- Bottling run: lot(s), date, format, bottles filled, waste %, breakage
- Components consumed: bottles, corks, capsules, labels (quantity tracked per component type)
- Lot volume deducted on completion
- Case goods inventory created on completion (auto-creates SKU with cases/bottles)
- Packaging materials auto-deducted from dry goods inventory
- Lot can be sealed/archived after bottling
- Writes `bottling_completed` event with full details
**Gotchas:** Bottling is a critical junction — it bridges the production module and the inventory/sales modules. The case goods created here are what get sold via POS and eCommerce. Must auto-deduct from dry goods inventory (depends on 04-inventory.md). Build bottling logic first, wire up inventory deduction when ready.

### 12. Barrel operations (fill, top, rack, sample)
**Description:** Implement barrel-specific operations that use the barrel model and create detailed event records.
**Files to create:**
- `api/app/Services/BarrelOperationService.php`
- `api/app/Http/Controllers/Api/V1/BarrelOperationController.php`
**Acceptance criteria:**
- Fill barrels from lot: select lot → select barrels → record gallons per barrel
- Topping log: date, source wine, volume added per barrel
- Racking: move wine from barrels → tank or new barrels, log lees weight
- Barrel sample entry
- Barrel grouping/sets for batch operations
- Each operation writes appropriate event (`barrel_filled`, `barrel_topped`, `barrel_racked`)
**Gotchas:** Barrel operations generate high event volume — a winery might top 200 barrels in one session. Bulk operations must be efficient. Topping uses wine from a source vessel, so source vessel volume must decrease.

### 13. Filament resources for all production models
**Description:** Create Filament admin resources for every production model — lots, vessels, barrels, work orders, additions, transfers, bottling runs, blends. This is the management portal UI for the production module.
**Files to create:**
- `api/app/Filament/Resources/LotResource.php` (with full table, form, filters, actions)
- `api/app/Filament/Resources/VesselResource.php`
- `api/app/Filament/Resources/BarrelResource.php`
- `api/app/Filament/Resources/WorkOrderResource.php`
- `api/app/Filament/Resources/AdditionResource.php`
- `api/app/Filament/Resources/TransferResource.php`
- `api/app/Filament/Resources/BottlingRunResource.php`
- `api/app/Filament/Resources/BlendTrialResource.php`
**Acceptance criteria:**
- Each resource has: list view with relevant filters, create/edit forms, bulk actions where appropriate
- Lot resource shows full timeline of events (chronological history)
- Vessel resource shows current contents with fill %
- Work order resource has calendar view (custom Livewire component)
- All Filament resources are properly gated by role permissions
**Gotchas:** Build custom Livewire components for: lot event timeline, vessel contents display, work order calendar. Standard Filament tables/forms handle everything else.

### 14. Production demo seeder
**Description:** Extend the demo seeder to populate realistic production data — lots, vessels, barrels, work orders, additions, and events.
**Files to create/modify:**
- `api/database/seeders/ProductionSeeder.php`
**Acceptance criteria:**
- Demo winery has: 40+ lots across multiple vintages and varieties, 24 tanks, 43 barrels, pending work orders, completed work orders with events, additions history, a few in-progress fermentations
- Event log contains realistic operation history for demo lots
- Data is convincing enough to demo to a winemaker
**Gotchas:** Must call EventLogger for all demo operations (not just insert into lots table directly) — the event log must be consistent with the materialized state.

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/lots` | List lots (filterable) | Authenticated |
| POST | `/api/v1/lots` | Create lot | winemaker+ |
| GET | `/api/v1/lots/{lot}` | Get lot detail with timeline | Authenticated |
| PUT | `/api/v1/lots/{lot}` | Update lot status/metadata | winemaker+ |
| POST | `/api/v1/lots/{lot}/split` | Split lot | winemaker+ |
| GET | `/api/v1/vessels` | List vessels | Authenticated |
| POST | `/api/v1/vessels` | Create vessel | winemaker+ |
| GET | `/api/v1/vessels/{vessel}` | Get vessel with contents | Authenticated |
| PUT | `/api/v1/vessels/{vessel}` | Update vessel | winemaker+ |
| GET | `/api/v1/barrels` | List barrels | Authenticated |
| POST | `/api/v1/barrels` | Create barrel | winemaker+ |
| GET | `/api/v1/work-orders` | List work orders | Authenticated |
| POST | `/api/v1/work-orders` | Create work order | winemaker+ |
| PUT | `/api/v1/work-orders/{wo}` | Update/complete work order | cellar_hand+ |
| POST | `/api/v1/work-orders/bulk` | Bulk create work orders | winemaker+ |
| POST | `/api/v1/additions` | Log addition | cellar_hand+ |
| POST | `/api/v1/transfers` | Log transfer | cellar_hand+ |
| POST | `/api/v1/press-logs` | Log pressing | winemaker+ |
| POST | `/api/v1/filter-logs` | Log filtering | winemaker+ |
| POST | `/api/v1/blends` | Create blend trial | winemaker+ |
| PUT | `/api/v1/blends/{blend}` | Update/finalize blend | winemaker+ |
| POST | `/api/v1/bottling-runs` | Create bottling run | winemaker+ |
| POST | `/api/v1/barrel-ops/fill` | Fill barrels | cellar_hand+ |
| POST | `/api/v1/barrel-ops/top` | Top barrels | cellar_hand+ |
| POST | `/api/v1/barrel-ops/rack` | Rack barrels | cellar_hand+ |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `lot_created` | name, variety, vintage, source, initial_volume | lots table |
| `lot_split` | parent_lot_id, child_lots [{id, volume}] | lots table (parent + children) |
| `addition_made` | lot_id, type, product, rate, amount, unit | additions table, lot SO2 running total |
| `transfer_executed` | lot_id, from_vessel, to_vessel, volume, variance | lot_vessel pivot, vessel contents |
| `rack_completed` | lot_id, from_vessels, to_vessels, lees_weight | lot_vessel pivot |
| `blend_finalized` | new_lot_id, sources [{lot_id, pct, volume}] | lots (new + sources), blend_trials |
| `barrel_filled` | lot_id, barrel_id, volume | lot_vessel pivot |
| `barrel_topped` | barrel_id, source_lot_id, volume | lot_vessel pivot |
| `barrel_racked` | barrel_id, to_vessel_id, lees_weight | lot_vessel pivot |
| `pressing_logged` | lot_id, press_type, fractions, yield_pct | press_logs, child lots |
| `filtering_logged` | lot_id, filter_type, media, volume | filter_logs |
| `bottling_completed` | lot_id, format, bottles, waste_pct, components | lots (volume deduct), case goods inventory |

## Testing Notes
- **Unit tests:** Volume calculations (transfers, splits, blends), TTB composition rules for blends, event payload validation, auto-deduct calculations
- **Integration tests:** Full lot lifecycle (create → add → transfer → blend → bottle), vessel volume tracking through multiple operations, event log consistency check (materialized state matches event replay)
- **Critical:** Volume reconciliation — the sum of all gallons across all vessels must equal the sum of all lot volumes (minus recorded losses). Write a reconciliation test that verifies this after a complex series of operations.
