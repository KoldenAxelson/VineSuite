# Event Source Partitioning

> Column `event_source` labels origin of each event. Zero-cost wiring for future scalability — no partitioning infrastructure built yet.

---

## Why This Exists

Event table grows linearly. Phase 4 adds high-frequency stock movements (potentially dozens/day/winery vs. a handful for cellar ops). Rather than build partitioning we may never need, we wire a single column now. Enables future: per-source partitioning, archival, routing, retention policies.

---

## The Column

```sql
event_source VARCHAR(30) NOT NULL DEFAULT 'production'
CREATE INDEX idx_events_source ON events (event_source);
```

---

## Source Values

| Source | Operations | Phase |
|---|---|---|
| `production` | lot_*, addition, transfer, rack, blend, barrel, pressing, filtering, bottling | 2 |
| `lab` | lab_analysis, fermentation_*, sensory_note | 3 |
| `inventory` | stock_*, purchase_*, equipment_*, dry_goods_*, raw_material_* | 4 |
| `accounting` | cost_*, cogs_* | 5 |
| `compliance` | ttb_*, license_*, compliance_* | 6 |
| `sales` | orders, fulfillment, club shipments | Future |
| `iot` | sensor_*, device_*, alert_* | Future (Phase 8) |
| `system` | scheduled jobs, automated alerts, corrections | Future |

---

## Auto-Resolution in EventLogger

Derived from `operationType` prefix — callers never set explicitly:

```php
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

**Result:** No changes to existing log() call sites. New operation types get correct source if they follow the naming convention.

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

## Future Upgrades (Not Yet Planned)

These are all possible — the column makes them tractable:

1. **Table partitioning** — Declarative PostgreSQL partitioning by source
2. **Separate tables** — Move high-volume sources to their own table with different retention
3. **Sharding** — Distribute sources across DB instances (extreme scale, unlikely needed)
4. **Tiered retention** — Keep production forever (TTB), archive inventory after 2 years
5. **Source-specific indexes** — Partial indexes for high-volume paths

---

## Rules

1. `event_source` **never set by callers** — derived from operationType
2. New operation types must follow prefix convention
3. If new module doesn't fit any prefix, add explicit match arm
4. Column NOT nullable — every event has a source
5. Don't build partitioning/routing/archival until volume data proves the need

---

*Implemented in Phase 4. Pre-wired, not activated.*
