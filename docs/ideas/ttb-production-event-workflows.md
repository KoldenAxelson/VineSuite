# TTB Production Event Workflows

> Status: Idea — technical debt from Phase 6 TTB compliance
> Created: 2026-03-17
> Origin: Phase 6 Known Debt Item #2

---

## The Problem

The Phase 6 TTB compliance layer expanded all Part calculators to handle the full TTB Form 5120.17 line structure. This includes event types for operations that don't yet have production UI or service methods to emit them. The calculators are ready; the emission paths are not.

## Missing Event Types

**Part III — Wine Received (3 events):**
- `wine_received_customs` — Wine imported into bond from customs
- `wine_returned_to_bulk` — Bottled wine returned to bulk storage (de-bottled)
- `wine_received_other` — Wine received from miscellaneous sources

**Note:** `stock_received` (bonded premises receipt) exists but needs `volume_gallons` enrichment — tracked separately in `bulk-wine-receipt-events.md`.

**Part II — Production Methods (3 events):**
- `sweetening_completed` — Addition of sweetening agent to wine
- `fortification_completed` — Addition of wine spirits to raise alcohol
- `amelioration_completed` — Addition of water/sugar to must/wine

**Part IV — Removals (6 events):**
- `taxpaid_bulk_removal` — Taxpaid removal of bulk wine
- `wine_transferred_bonded` — Transfer to another bonded premises
- `wine_exported` — Export of wine
- `used_as_distilling_material` — Wine sent for distillation
- `used_as_vinegar` — Wine converted to vinegar
- `other_bulk_removal` — Miscellaneous bulk removal

**Part IV — Section B (5 events):**
- `bottled_received_bonded` — Bottled wine received from bonded premises
- `bottled_received_customs` — Bottled wine received from customs
- `bottled_returned_to_bond` — Bottled wine returned to bond
- `bottled_transferred_bonded` — Bottled wine transferred to bonded premises
- `bottled_exported` — Bottled wine exported

**Part V — Losses (1 event):**
- `evaporation_measured` — Measured evaporation loss (angel's share)

## What Needs to Happen

For each event type: controller endpoint (or extend existing controller), service method with business logic, request validation, event emission via EventLogger, and tests. Some of these are simple record-and-log operations; others (like fortification) have domain complexity (alcohol calculation, volume changes).

## Prioritization Suggestion

Not all of these are equally important. Suggested tiers:

**Tier 1 — Common operations most wineries will use:**
- `sweetening_completed`, `fortification_completed` (dessert wine production)
- `wine_transferred_bonded` (inter-winery transfers)
- `evaporation_measured` (barrel aging loss)

**Tier 2 — Less common but needed for complete TTB reporting:**
- `amelioration_completed`, `taxpaid_bulk_removal`, `wine_exported`
- `bottled_transferred_bonded`, `bottled_exported`

**Tier 3 — Rare operations:**
- `wine_received_customs`, `wine_returned_to_bulk`, `wine_received_other`
- `used_as_distilling_material`, `used_as_vinegar`, `other_bulk_removal`
- `bottled_received_bonded`, `bottled_received_customs`, `bottled_returned_to_bond`

## Scope Estimate

Large (4-6 days for Tier 1, 3-4 additional days for Tier 2). Tier 3 can be deferred indefinitely — these are edge cases that even most mid-size wineries won't encounter.
