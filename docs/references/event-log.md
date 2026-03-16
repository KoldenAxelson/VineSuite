# Event Log

> Last updated: 2026-03-15
> Relevant source: `api/app/Services/EventLogger.php`, `api/app/Models/Event.php`
> Architecture doc: Section 3

---

## What This Is
The append-only event log is the foundational data pattern for the entire platform. Every winery operation (addition, transfer, blend, sale, etc.) writes an immutable event record. Materialized CRUD tables (lots, vessels, inventory) are derived from these events via handlers. This is NOT full event sourcing — no replay, no projectors, no CQRS. It's a pragmatic audit log that enables TTB reporting, offline sync safety, and AI features.

## How It Works
1. **Writing events**: All modules call `app(EventLogger::class)->log(...)` with entity info, operation type, JSONB payload, and optionally a client timestamp, device ID, and idempotency key.
2. **Idempotency**: If an `idempotency_key` is provided and already exists, the existing event is returned silently. No error, no duplicate. Critical for offline mobile sync retries.
3. **Immutability**: A PostgreSQL trigger (`events_immutability_guard`) blocks all UPDATE and DELETE operations at the database level. Corrections are new events (e.g., `addition_corrected`).
4. **Timestamps**: `performed_at` is the client-provided timestamp (may be from an offline device). `synced_at` is set on server receipt for mobile-synced events, null for locally-created events.
5. **Querying**: BRIN index on `performed_at` for efficient time-range queries. Composite index on `(entity_type, entity_id)` for entity streams.

## Key Files
- `app/Services/EventLogger.php` — Single entry point for writing events. `log()`, `getEntityStream()`, `getByOperationType()`.
- `app/Models/Event.php` — Eloquent model. `UPDATED_AT=null`. Casts: payload→array, performed_at→datetime, synced_at→datetime. Scopes: `forEntity()`, `ofType()`, `performedBetween()`.
- `database/migrations/tenant/2026_03_10_000005_create_events_table.php` — Schema with BRIN index, immutability trigger, idempotency_key unique constraint.

## Usage Patterns

**Creating an event:**
```php
$logger = app(EventLogger::class);

$event = $logger->log(
    entityType: 'lot',
    entityId: $lot->id,
    operationType: 'addition',
    payload: [
        'volume_gallons' => 500,
        'grape_variety' => 'Cabernet Sauvignon',
        'vineyard' => 'Estate Block A',
    ],
    performedBy: auth()->id(),
    performedAt: now(),
    idempotencyKey: 'mobile-sync-abc-123',  // optional, for offline safety
);
```

**Mobile sync (sets synced_at):**
```php
$event = $logger->log(
    entityType: 'lot',
    entityId: $lotId,
    operationType: 'addition',
    payload: $data,
    performedBy: $userId,
    performedAt: $clientTimestamp,
    deviceId: 'iphone-cellar-001',
    idempotencyKey: $clientGeneratedKey,
    isSynced: true,  // sets synced_at to now()
);
```

**Querying entity history:**
```php
$stream = $logger->getEntityStream('lot', $lotId);
// Returns all events for this lot, ordered by performed_at
```

**TTB reporting query:**
```php
$additions = $logger->getByOperationType('addition', $startOfMonth, $endOfMonth);
```

**JSONB payload querying:**
```php
$events = Event::whereRaw("payload->>'grape_variety' = ?", ['Pinot Noir'])->get();
```

## Gotchas
- **Events table is immutable** — no UPDATE, no DELETE, ever. PostgreSQL trigger enforces this at the DB level.
- **Corrections are new events** (e.g., `addition_corrected`), not edits to old events.
- **`idempotency_key` prevents duplicate submissions** from offline retry — always generate client-side.
- **`performed_at` is the client timestamp** (may be from an offline device); `synced_at` is server receipt time.
- **Never use `Event::create()` directly** — always go through `EventLogger::log()` for idempotency and structured logging.
- **Null idempotency keys are allowed** — server-generated events may not need deduplication. Multiple null keys don't conflict with the unique constraint.
- **BRIN index on performed_at** — designed for time-series queries. Much smaller than B-tree for sequential data.
- For volume-sensitive operations, the server validates physical possibility on receipt (to be implemented in event handlers).

## Production Event Types (Phase 2)

The following event types are written by the production module. All use `entityType: 'lot'` with the lot's UUID as `entityId`.

| Operation Type | When Logged | Key Payload Fields |
|---|---|---|
| `lot_created` | New lot created | name, variety, vintage, source, initial_volume |
| `lot_split` | Lot split into children | parent_lot_id, child_lots [{id, volume}] |
| `addition_made` | Chemical addition logged | lot_id, type, product, rate, rate_unit, amount, unit |
| `transfer_executed` | Wine moved between vessels | lot_id, from_vessel, to_vessel, volume, variance, transfer_type |
| `rack_completed` | Racking operation done | lot_id, from_vessels, to_vessels, lees_weight |
| `blend_finalized` | Blend trial finalized | new_lot_id, sources [{lot_id, pct, volume}] |
| `barrel_filled` | Barrel filled with wine | lot_id, barrel_id, volume |
| `barrel_topped` | Barrel topped off | barrel_id, source_lot_id, volume |
| `barrel_racked` | Barrel racked to vessel | barrel_id, to_vessel_id, lees_weight |
| `pressing_logged` | Press operation recorded | lot_id, press_type, fractions, yield_pct |
| `filtering_logged` | Filter operation recorded | lot_id, filter_type, media, volume |
| `bottling_completed` | Bottling run finished | lot_id, format, bottles, waste_pct, cases, sku |

## Seeder Event Logging

Demo seeders should always log events through `EventLogger::log()` rather than inserting Event records directly. This ensures idempotency key handling, payload structure, and timestamp consistency. The `ProductionSeeder` established this pattern — see `database/seeders/ProductionSeeder.php`.

```php
// In a seeder — always use EventLogger, set specific dates
$this->eventLogger->log(
    entityType: 'lot',
    entityId: $lot->id,
    operationType: 'lot_created',
    payload: ['name' => $name, 'variety' => $variety, ...],
    performedBy: $user->id,
    performedAt: Carbon::create(2024, 10, 15),  // historical date
);
```

## Lab & Fermentation Event Types (Phase 3)

The following event types are written by the lab and fermentation modules.

| Operation Type | Entity Type | When Logged | Key Payload Fields |
|---|---|---|---|
| `lab_analysis_entered` | lot | Lab analysis recorded (manual or import) | lot_name, lot_variety, test_type, value, unit, method, analyst, test_date. Import batches include `import_batch: true`. |
| `fermentation_round_created` | lot | Fermentation round started | lot_name, lot_variety, fermentation_type, yeast_strain (or ml_bacteria), inoculation_date |
| `fermentation_data_entered` | fermentation_round | Daily entry logged | temperature, brix_or_density, measurement_type, free_so2, entry_date |
| `fermentation_completed` | fermentation_round | Round marked complete | status, completion_date, total_entries |
| `sensory_note_recorded` | lot | Tasting note created | lot_name, lot_variety, taster_name, date, rating, rating_scale, has_nose_notes, has_palate_notes, has_overall_notes |

### Self-Contained Payload Pattern

Phase 3 established the pattern of including human-readable context in event payloads. Every lab/fermentation event includes `lot_name` and `lot_variety` alongside `lot_id`, and sensory notes include `taster_name` alongside `taster_id`. This makes the event stream readable without joins — critical for data portability and TTB audit trails.

### Boolean Flags for Text Presence

Sensory note events use boolean flags (`has_nose_notes`, `has_palate_notes`, `has_overall_notes`) instead of the full note text. This keeps event payloads lightweight while indicating evaluation completeness.

## Inventory Event Types (Phase 4)

The following event types are written by the inventory module. The `event_source` column is auto-resolved to `'inventory'` for all `stock_`, `purchase_`, `equipment_`, `dry_goods_`, and `raw_material_` prefixed operation types via `EventLogger::resolveSource()`.

### Stock Movement Events

| Operation Type | Entity Type | When Logged | Key Payload Fields |
|---|---|---|---|
| `stock_received` | stock_movement | Case goods received into inventory | sku_id, sku_name, location_id, location_name, quantity, movement_type |
| `stock_sold` | stock_movement | Case goods sold (POS or online) | sku_id, sku_name, location_id, location_name, quantity, movement_type |
| `stock_adjusted` | stock_movement | Manual inventory adjustment or physical count variance | sku_id, sku_name, location_id, location_name, quantity, reference_type, reference_id |
| `stock_transferred` | stock_movement | Case goods moved between locations | sku_id, sku_name, from_location_id, from_location_name, to_location_id, to_location_name, quantity |

### Physical Count Events

| Operation Type | Entity Type | When Logged | Key Payload Fields |
|---|---|---|---|
| `stock_count_started` | physical_count | Count session opened for a location | location_id, location_name, line_count |
| `stock_counted` | physical_count | Count approved, variances applied | location_id, location_name, total_lines, adjustments_made |
| `stock_count_cancelled` | physical_count | Count session cancelled | location_id, location_name, lines_recorded |

### Purchase Order Events

| Operation Type | Entity Type | When Logged | Key Payload Fields |
|---|---|---|---|
| `purchase_order_created` | purchase_order | New PO submitted | po_number, vendor_name, line_count, total_amount |
| `purchase_order_updated` | purchase_order | PO modified | po_number, vendor_name, changes |
| `purchase_order_received` | purchase_order | PO items received (full or partial) | po_number, vendor_name, lines_received, new_status |

### Item Registry Events

| Operation Type | Entity Type | When Logged | Key Payload Fields |
|---|---|---|---|
| `equipment_created` | equipment | Equipment registered | name, equipment_type, serial_number |
| `equipment_updated` | equipment | Equipment record modified | name, changes |
| `dry_goods_created` | dry_goods_item | Dry goods item registered | name, item_type, unit_of_measure |
| `dry_goods_updated` | dry_goods_item | Dry goods item modified | name, changes |
| `raw_material_created` | raw_material | Raw material registered | name, category, unit_of_measure |
| `raw_material_updated` | raw_material | Raw material modified | name, changes |

### Event Source Partitioning

Phase 4 introduced the `event_source` column on the events table (migration `2026_03_15_200001`). The column is auto-populated by `EventLogger::resolveSource()` based on `operation_type` prefix:

| Prefix | Source |
|---|---|
| `lab_`, `fermentation_`, `sensory_` | `lab` |
| `stock_`, `purchase_`, `equipment_`, `dry_goods_`, `raw_material_` | `inventory` |
| `cost_`, `cogs_` | `accounting` |
| (default) | `production` |

This enables module-level event filtering: `Event::where('event_source', 'inventory')->get()`.

## History
- 2026-03-10: Sub-Task 6 complete. Events table, Event model, EventLogger service. 13 tests passing. Immutability enforced via PostgreSQL trigger.
- 2026-03-10: Sub-Task 7 complete. Batch sync endpoint `POST /api/v1/events/sync`. Per-event transactions, 100 max batch, 30-day window. 12 tests.
- 2026-03-10: Sub-Task 13 — Sync endpoint responses wrapped in API envelope format.
- 2026-03-15: Phase 2 complete. 12 production event types documented. Seeder event logging pattern established.
- 2026-03-15: Phase 3 complete. 5 lab/fermentation event types added. Self-contained payload pattern and boolean flags pattern established.
- 2026-03-15: Phase 4 complete. 16 inventory event types added. Event source partitioning introduced (`event_source` column + auto-resolution).
