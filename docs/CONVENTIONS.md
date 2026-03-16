# VineSuite — Code Conventions

Cross-cutting patterns established across Phases 1-4. Follow these when writing any code. Reference docs have details — this file is the checklist.

---

## Services

- **EventLogger is the only write path for events.** `app(EventLogger::class)->log()` — never `Event::create()` directly. Seeders follow the same rule. → `references/event-log.md`
- **InventoryService is the only write path for stock mutations.** `receive()`, `sell()`, `adjust()`, `transfer()` — each wraps in a transaction with `lockForUpdate()`. Never modify StockLevel directly. → `04-inventory.info.md` Sub-Task 3
- **Workflow-as-service for multi-step processes.** PhysicalCountService manages `startCount` → `recordCounts` → `approve`/`cancel`. Approval triggers InventoryService for variance adjustments.

## API

- **Envelope format on all routes.** `ApiResponse::success()`, `created()`, `paginated()`, `error()`. Validation errors include field-level details.
- **REST JSON at `/api/v1/`.** Bearer token auth (Sanctum), scoped per client type.
- **Token name encodes client type.** Format: `client_type|context` (e.g., `portal|My MacBook`). Rate limiter reads the prefix. → `references/auth-rbac.md`

## Event Log

- **Self-contained payloads.** Include human-readable names (lot_name, sku_name, taster_name) alongside foreign keys. Event stream must be readable without joins.
- **Boolean flags for large text.** When payload would contain text blobs (tasting notes), store `has_nose_notes: true` instead. Full text lives in source record.
- **Event source auto-resolves.** `EventLogger::resolveSource()` maps operation_type prefixes (`stock_` → `inventory`). → `references/event-source-partitioning.md`
- **Immutability enforced at DB level.** PostgreSQL triggers block UPDATE/DELETE on `events` and `activity_logs`. Migration must temporarily disable triggers for backfills.

## Filament

- **Tenancy middleware before session.** `InitializeTenancyByDomain` + `PreventAccessFromCentralDomains` before `StartSession` in AdminPanelProvider. → `guides/filament-tenancy.md`
- **`$isDiscovered = false` for page-specific widgets.** Filament auto-discovers all widgets in `app/Filament/Widgets/`. Page-specific ones must opt out.
- **Immutable models = Create + View only.** No Edit/Delete actions for append-only records (Additions, Transfers, StockMovements).
- **Sentinel formatting at view layer.** `formatStateUsing()` in Filament tables (e.g., vintage=0 → "NV"). No model accessors for display logic.
- **`Schema::hasTable()` guards on dynamic filters.** Dropdown filters querying newer tables must guard against tenants not yet migrated.
- **Bidirectional RelationManagers.** When two models bridge through an intermediate table, create RelationManagers on both resources.

## Data Model

- **UUID primary keys everywhere.** Pivot tables need `uuid('id')->primary()` — Laravel's `attach()` doesn't auto-generate; pass `'id' => (string) Str::uuid()`.
- **Signed quantities in ledgers.** Positive = inbound (received, transferred_in, adjusted up). Negative = outbound (sold, transferred_out, adjusted down). Sum of all movements = current on_hand.
- **Manual polymorphism over morphTo.** `item_type` + `item_id` columns (explicit, queryable) instead of Laravel morph relationships. Matches Event model pattern.

## Tests

- **DatabaseMigrations for tenancy tests.** RefreshDatabase causes PostgreSQL DDL deadlocks. Use `uses(DatabaseMigrations::class)` + `afterEach` to drop `tenant_%` schemas. → `references/multi-tenancy.md`
- **Test groups are mandatory.** Every test file belongs to exactly one group: `foundation`, `production`, `lab`, `inventory`, `accounting`. → `references/test-groups.md`
- **Globally unique test helper names.** Pest loads all files flat. Prefix helpers with module ID: `seedAndGetInventoryTenant()` not `createTenant()`.
- **Tier 1 required, Tier 2 expected, Tier 3 optional.** → `guides/testing-and-logging.md`
- **Redis flush on database reset.** `make fresh` runs `redis-cli FLUSHDB` — stale sessions cause auth failures after reset.

## Seeders

- **Modular sub-seeders.** `ProductionSeeder`, `LabSeeder`, `InventorySeeder` etc., called from `DemoWinerySeeder` via `$this->call()`.
- **Seeders use EventLogger.** Demo data writes events for a realistic event log. Never seed records without corresponding events.
- **Sub-seeders assume users exist.** Load users via role queries — don't create them. `DemoWinerySeeder` handles user creation.
