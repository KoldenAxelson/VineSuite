# Bulk Wine Receipt Events

> Status: Idea — technical debt from Phase 6 TTB compliance
> Created: 2026-03-17
> Origin: Phase 6 Known Debt Item #1

---

## The Problem

TTB Form 5120.17 Part III tracks wine received in bond — bulk wine transferred from other bonded premises, received from customs, or returned to bulk from bottled stock. The `PartThreeCalculator` is fully built and handles all receipt categories, but there's no way to emit the events it listens for.

The existing `stock_received` event tracks case goods inventory (bottle quantities via CaseGoodsSku), not bulk wine in gallons. The payload includes `quantity` (cases) but not `volume_gallons`. These are fundamentally different inventory domains.

## What Needs to Happen

Two options (design decision required):

**Option A: New event type `bulk_wine_received`**
- Dedicated controller endpoint for receiving bulk wine into bond
- Captures: source (bonded premises, customs, other), volume in gallons, wine type, lot assignment
- Cleaner separation between case goods and bulk wine inventory
- Requires new API endpoint, service method, validation, and tests

**Option B: Enrich `stock_received` with optional `volume_gallons`**
- Extend the existing InventoryService.receive() to support bulk wine
- Add `volume_gallons` to the event payload when present
- Simpler change but muddies the stock_received event's semantics

**Recommendation:** Option A. Bulk wine receipts are a distinct business operation from case goods receiving. They need different validation (gallons vs cases, bond transfer documentation), different event payloads, and feed into a different reporting pipeline (TTB vs inventory management).

## Impact

Low for most small wineries — few receive wine in bond. Higher for custom crush facilities and larger operations that transfer wine between bonded premises. The TTB calculator layer is ready; this is purely about adding the emission path.

## Scope Estimate

Medium (2-3 days). New controller, service method, request validation, event emission, and tests. The receiving end (PartThreeCalculator) already works.
