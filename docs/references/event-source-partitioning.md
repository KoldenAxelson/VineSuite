# Event Source Partitioning

> Last updated: 2026-03-15
> Relevant source: `api/app/Services/EventLogger.php`, `api/app/Models/Event.php`, events migration
> Status: **Pre-wired, not activated.** The column exists and is populated, but no routing, partitioning, or sharding logic is built.

---

## Why This Exists

The event log is append-only and grows linearly with winery operations. As of Phase 3, there are 17 operation types across 3 modules (production, lab, inventory incoming). Phase 4 (Inventory) will add high-frequency stock movement events — potentially dozens per day per winery vs. a handful for cellar operations.

Rather than build partitioning infrastructure we may never need, we're adding a single `event_source` column that labels where each event originates. This is zero-cost wiring that gives us massive optionality later:

- Partition by source if one module dominates volume
- Archive old events from high-volume sources independently
- Route specific sources to a separate table or database
- Build source-specific dashboards and analytics
- Apply different retention policies per source

All of these become a `WHERE event_source = ?` query away, rather than regex-over-payloads or operation-type-prefix parsing.

---

## The Column

```sql
event_source VARCHAR(30) NOT NULL DEFAULT 'production'
```

Indexed for efficient filtering:

```sql
CREATE INDEX idx_events_source ON events (event_source);
```

---

## Source Values

| Source | Operations | Phase |
|---|---|---|
| `production` | lot_created, lot_split, addition_made, transfer_executed, rack_completed, blend_finalized, barrel_filled, barrel_topped, barrel_racked, pressing_logged, filtering_logged, bottling_completed | 2 |
| `lab` | lab_analysis_entered, fermentation_round_created, fermentation_data_entered, fermentation_completed, sensory_note_recorded | 3 |
| `inventory` | TBD — stock movements, adjustments, purchase orders, equipment maintenance | 4 |
| `accounting` | TBD — cost allocations, COGS calculations | 5 |
| `sales` | TBD — orders, fulfillment, club shipments | Future |
| `system` | TBD — scheduled jobs, automated alerts, data corrections | Future |

**Convention:** Source names are lowercase, singular, one-word. They correspond to navigation groups in the Filament portal.

---

## EventLogger Integration

The `event_source` is derived automatically from `operationType` inside `EventLogger::log()`. Callers don't pass it explicitly — this keeps the API surface unchanged and prevents inconsistency.

```php
// EventLogger::log() internally maps operation type → source
private function resolveSource(string $operationType): string
{
    return match (true) {
        str_starts_with($operationType, 'lab_'),
        str_starts_with($operationType, 'fermentation_'),
        str_starts_with($operationType, 'sensory_') => 'lab',

        str_starts_with($operationType, 'stock_'),
        str_starts_with($operationType, 'purchase_'),
        str_starts_with($operationType, 'equipment_') => 'inventory',

        str_starts_with($operationType, 'cost_'),
        str_starts_with($operationType, 'cogs_') => 'accounting',

        default => 'production',
    };
}
```

This means:
- No changes to any existing `EventLogger::log()` call site
- No changes to seeders, services, or controllers
- New operation types automatically get the right source if they follow the naming convention
- The `default => 'production'` fallback means all existing events get the correct source without migration

---

## Querying by Source

```php
// All lab events for a tenant in a date range
Event::where('event_source', 'lab')
    ->performedBetween($from, $to)
    ->get();

// Count events by source (volume monitoring)
Event::selectRaw('event_source, count(*) as total')
    ->groupBy('event_source')
    ->get();
```

---

## What We're NOT Doing (Yet)

These are all possible future upgrades that the `event_source` column enables. None are planned or needed now.

1. **Table partitioning** — PostgreSQL declarative partitioning by `event_source`. Would split the physical table into per-source partitions while keeping the same query interface.

2. **Separate tables** — Moving high-volume sources (e.g., `inventory`) to their own table with a different retention policy. The EventLogger would route writes, and a union view would provide backward compatibility.

3. **Sharding** — Distributing event sources across different database instances. Extreme scale measure, unlikely needed below 500 tenants.

4. **Tiered retention** — Keeping `production` events forever (TTB compliance) while archiving `inventory` events after 2 years.

5. **Source-specific indexes** — Partial indexes like `CREATE INDEX ... WHERE event_source = 'inventory'` for high-volume query paths.

---

## Migration

The migration adds the column with a default value so existing rows are backfilled automatically:

```php
Schema::table('events', function (Blueprint $table) {
    $table->string('event_source', 30)->default('production')->after('operation_type');
    $table->index('event_source', 'idx_events_source');
});
```

Existing events get `'production'` as their source. A one-time backfill command updates Phase 3 events to `'lab'`:

```sql
UPDATE events SET event_source = 'lab'
WHERE operation_type IN (
    'lab_analysis_entered',
    'fermentation_round_created',
    'fermentation_data_entered',
    'fermentation_completed',
    'sensory_note_recorded'
);
```

Note: This UPDATE requires temporarily disabling the immutability trigger for the backfill migration, then re-enabling it. The migration handles this in a transaction.

---

## Rules

1. `event_source` is **never set by callers** — it's derived from `operationType` inside EventLogger
2. New operation types must follow the prefix convention so `resolveSource()` maps them correctly
3. If a new module doesn't fit any prefix, add an explicit match arm — don't rely on the default
4. The column is NOT nullable — every event has a source
5. Don't build partitioning, routing, or archival infrastructure until volume data proves the need

---

## History
- 2026-03-15: Reference doc created during Phase 3 retrospective. Column and auto-resolution to be implemented in Phase 4 Sub-Task 1 (migration setup).
