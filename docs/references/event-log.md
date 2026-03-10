# Event Log

> Last updated: 2026-03-10
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

## History
- 2026-03-10: Sub-Task 6 complete. Events table, Event model, EventLogger service. 13 tests passing. Immutability enforced via PostgreSQL trigger.
