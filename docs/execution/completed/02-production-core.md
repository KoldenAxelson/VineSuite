# Production Core (Cellar Management) — COMPLETED

> **Status: COMPLETED** — This phase is historical. Agents should use phase recaps instead.

## Quick Reference

**Phase:** 2
**Dependencies:** Foundation (event log, auth, Filament).
**Core accomplishments:** Lot/vessel/barrel tracking, work orders, additions, transfers, pressing, blending, bottling, full cellar operation lifecycle.

---

## Sub-Tasks (Completed)

1. **Lot model + CRUD** — UUID, variety, vintage, source, volume (stored as gallons internally). Create writes `lot_created` event. Searchable by name/variety/vintage.

2. **Vessel model + CRUD** — Type (tank/barrel/flexitank/tote/demijohn/concrete_egg/amphora), capacity, material, location, status. Current contents queryable via lot_vessel pivot.

3. **Barrel model** — Extends vessel with cooperage, toast_level, oak_type, forest_origin, years_used, QR code. Time-in-oak derived from pivot timestamps.

4. **Work order system** — Templates for common operations (pump over, punch down, rack, SO2, fine, filter, etc.). Completion writes appropriate event. Calendar view. Bulk creation.

5. **Additions logging** — Records addition (SO2, nutrients, fining agents) to lot. Writes `addition_made` event. Optional inventory linkage for auto-deduct (implemented in 04-inventory).

6. **Transfers + racking** — From vessel → to vessel with variance/loss. Writes `transfer_executed` event. Validates source volume, warns on target overflow.

7. **Pressing** — Records press fractions (free run, light press, heavy press), yield %, pomace disposal. Can create child lots per fraction.

8. **Filtering + fining** — Simple log entries with pre/post analysis comparison. Writes `filtering_logged` event.

9. **Blending operations** — Trial blends with percentages, finalize to create new blended lot. Deducts proportional volumes from sources. Checks TTB labeling (>75% variety rule).

10. **Lot splitting** — Divide parent into N child lots. Child volumes proportional, COGS split by ratio. Writes `lot_split` event.

11. **Bottling runs** — Lot → format → bottles filled + waste/breakage. Consumes packaging (bottles, corks, capsules, labels). Creates case goods SKU. Auto-deducts dry goods. Writes `bottling_completed` event.

12. **Barrel operations** — Fill, top, rack, sample. Bulk operations efficient. Topping uses source vessel (decrements volume). Writes `barrel_filled`, `barrel_topped`, `barrel_racked` events.

13. **Filament resources** — LotResource, VesselResource, BarrelResource, WorkOrderResource, AdditionResource, TransferResource, BottlingRunResource, BlendTrialResource. Lot resource shows event timeline.

14. **Production demo seeder** — 40+ lots, 24 tanks, 43 barrels, pending/completed work orders, additions, fermentation entries. Use EventLogger (not direct inserts) for consistency.

---

## Remaining Gotchas

- **Volume conversions:** Store internally as gallons. API responses convert to winery's preferred unit.
- **Transfers are destructive:** Server validates volume on receipt. Offline conflicts require manual resolution.
- **Barrel operations high volume:** Bulk efficiency critical (topping 200 barrels in one session).
- **Blending cost rollthrough:** Critical for COGS accuracy. Split proportionally.
- **TTB labeling rules:** >75% of one variety to label as that variety. Enforce on finalization.

---

## Critical Tests

- Volume reconciliation: sum of vessel contents = sum of lot volumes - recorded losses.
- Event log consistency: materialized state matches event replay.
- Offline sync: duplicate idempotency keys handled correctly.
