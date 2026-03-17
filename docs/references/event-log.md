# Event Log

> Append-only audit log. Immutable at DB level. All winery operations write events; CRUD tables derive from them.

---

## Core Pattern

**Write:** `app(EventLogger::class)->log(entityType, entityId, operationType, payload, performedBy, performedAt, idempotencyKey)`

**Read:** `getEntityStream()`, `getByOperationType()`, BRIN index on `performed_at` for range queries

**Immutability:** PostgreSQL trigger blocks UPDATE/DELETE. Corrections are new events (e.g., `addition_corrected`).

**Timestamps:** `performed_at` = client time (may be offline); `synced_at` = server receipt (mobile only, else null)

**Idempotency:** If key already exists, return existing event silently. Critical for offline mobile retries.

---

## Usage

**Creating an event:**
```php
$logger = app(EventLogger::class);

$event = $logger->log(
    entityType: 'lot',
    entityId: $lot->id,
    operationType: 'addition',
    payload: ['volume_gallons' => 500, 'grape_variety' => 'Cabernet Sauvignon'],
    performedBy: auth()->id(),
    performedAt: now(),
    idempotencyKey: 'mobile-sync-abc-123',  // optional
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
    isSynced: true,  // sets synced_at
);
```

**Query entity history:**
```php
$stream = $logger->getEntityStream('lot', $lotId);  // All events for this lot, ordered
```

**JSONB payload query:**
```php
$events = Event::whereRaw("payload->>'grape_variety' = ?", ['Pinot Noir'])->get();
```

---

## Gotchas

- **Events immutable** — no UPDATE, no DELETE. PostgreSQL trigger enforces.
- **Corrections are new events** — `addition_corrected` type, not edits
- **idempotency_key prevents duplicates** from offline retry — always generate client-side
- **Never use Event::create() directly** — always use EventLogger::log()
- **Null idempotency keys allowed** — server events; no unique constraint conflict
- **BRIN index on performed_at** — for time-range queries on sequential data

---

## Phase 2 Production Events

| Operation | Payload Fields | Notes |
|---|---|---|
| `lot_created` | name, variety, vintage, source, initial_volume | |
| `lot_split` | parent_lot_id, child_lots[] | |
| `addition_made` | lot_id, type, product, rate, amount, unit | |
| `transfer_executed` | lot_id, from_vessel, to_vessel, volume, variance | |
| `rack_completed` | lot_id, from_vessels, to_vessels, lees_weight | |
| `blend_finalized` | new_lot_id, sources[] | |
| `barrel_filled` | lot_id, barrel_id, volume | |
| `barrel_topped` | barrel_id, source_lot_id, volume | |
| `barrel_racked` | barrel_id, to_vessel_id, lees_weight | |
| `pressing_logged` | lot_id, press_type, fractions, yield_pct | |
| `filtering_logged` | lot_id, filter_type, media, volume | |
| `bottling_completed` | lot_id, format, bottles, waste_pct, cases, sku | |

---

## Phase 3 Lab Events

| Operation | Entity Type | Payload | Pattern |
|---|---|---|---|
| `lab_analysis_entered` | lot | lot_name, lot_variety, test_type, value, unit, method, analyst, test_date | Self-contained (includes lot name, not just ID) |
| `fermentation_round_created` | lot | lot_name, lot_variety, fermentation_type, yeast_strain, inoculation_date | |
| `fermentation_data_entered` | fermentation_round | temperature, brix_or_density, free_so2, entry_date | |
| `fermentation_completed` | fermentation_round | status, completion_date, total_entries | |
| `sensory_note_recorded` | lot | lot_name, lot_variety, taster_name, rating, has_nose_notes, has_palate_notes, has_overall_notes | Boolean flags for text presence |

---

## Phase 4 Inventory Events

**Stock Movements:**
| Operation | Payload | Notes |
|---|---|---|
| `stock_received` | sku_id, sku_name, location_id, location_name, quantity | |
| `stock_sold` | sku_id, sku_name, location_id, location_name, quantity | |
| `stock_adjusted` | sku_id, sku_name, location_id, location_name, quantity, reference_type | |
| `stock_transferred` | sku_id, from_location, to_location, quantity | |

**Counts & Orders:**
| Operation | Payload |
|---|---|
| `stock_count_started` | location_id, location_name, line_count |
| `stock_counted` | location_id, location_name, adjustments_made |
| `stock_count_cancelled` | location_id, location_name, lines_recorded |
| `purchase_order_created` | po_number, vendor_name, line_count, total_amount |
| `purchase_order_received` | po_number, vendor_name, lines_received |

**Inventory Deductions:**
| Operation | Payload | Notes |
|---|---|---|
| `raw_material_deducted` | raw_material_name, addition_id, lot_id, deducted_amount, unit_of_measure, previous_on_hand, new_on_hand | Auto-triggered by AdditionService when addition has inventory_item_id |
| `dry_goods_deducted` | dry_goods_name, bottling_run_id, component_type, deducted_quantity, unit, previous_on_hand, new_on_hand | Auto-triggered by BottlingService when component has inventory_item_id |

**Item Registry:**
| Operation | Payload |
|---|---|
| `equipment_created` | name, equipment_type, serial_number |
| `dry_goods_created` | name, item_type, unit_of_measure |
| `raw_material_created` | name, category, unit_of_measure |

---

## Event Source Partitioning

Column `event_source` auto-resolved by operation_type prefix:

| Prefix | Source |
|---|---|
| `lab_`, `fermentation_`, `sensory_` | `lab` |
| `stock_`, `purchase_`, `equipment_`, `dry_goods_`, `raw_material_` | `inventory` |
| `cost_`, `cogs_` | `accounting` |
| (default) | `production` |

Query: `Event::where('event_source', 'inventory')->get()`

---

## Seeder Pattern

Always use EventLogger, set specific dates:

```php
$this->eventLogger->log(
    entityType: 'lot',
    entityId: $lot->id,
    operationType: 'lot_created',
    payload: ['name' => $name, 'variety' => $variety],
    performedBy: $user->id,
    performedAt: Carbon::create(2024, 10, 15),
);
```

---

## Key Files

- `app/Services/EventLogger.php` — Write entry point, query helpers
- `app/Models/Event.php` — UPDATED_AT=null, casts, scopes
- `database/migrations/tenant/2026_03_10_000005_create_events_table.php` — Schema + immutability trigger
