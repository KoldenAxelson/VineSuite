# Gradual Migration Path (Module-at-a-Time Adoption)

> Status: Idea — may require architectural consideration in Phase 2
> Created: 2026-03-10
> Competitor lesson: All competitors (wineries won't rip-and-replace)

---

## The Problem
No winery will switch from InnoVint + Commerce7 to VineSuite overnight. The switching cost isn't just money — it's retraining staff, migrating club member data, risking DTC revenue during transition, and the psychological burden of changing tools during a busy season. Every competitor that tried an all-or-nothing approach lost potential customers who would have tried one module.

The current task plan builds VineSuite as a complete platform but doesn't explicitly address partial adoption — a winery using VineSuite for production while keeping Commerce7 for DTC, or using VineSuite's POS while keeping InnoVint for cellar tracking.

## Proposed Approach

**Module independence:** Each major module (production, POS, wine club, eCommerce) should be usable standalone without requiring the others. A winery should be able to sign up for "just production + compliance" and never touch the DTC features.

**Parallel operation period:** During migration, a winery runs both systems. VineSuite needs to not care that it's not the only system in use. This means:
- Don't require that all inventory be managed in VineSuite to use the POS
- Don't require that all customers be in VineSuite CRM to run a wine club
- Allow partial data (production lots tracked in VineSuite, but order history still in Commerce7)

**Migration triggers, not migration pressure:** When a winery using VineSuite for production completes their first bottling run, that's a natural moment to suggest: "These bottles are ready to sell. Want to set up your tasting room POS?" The product should surface these moments organically without nagging.

**Incoming webhooks / CSV import for coexistence:** If a winery keeps Commerce7 for DTC but uses VineSuite for production, they might want order data flowing into VineSuite for reporting. A simple webhook receiver or periodic CSV import for external sales data lets VineSuite be useful even when it's not the system of record for everything.

## Architecture Compatibility
The event-sourced architecture supports this naturally — modules are consumers of events, not tightly coupled systems. The production module writes events, and the compliance module reads them. If the DTC modules aren't active, the events still exist and compliance still works.

The potential issue is **cross-module references.** For example:
- Bottling creates case goods SKUs (production → inventory link)
- POS sells SKUs (inventory → POS link)
- If a winery uses production but not POS, the SKUs exist but aren't sold through VineSuite

This needs to be handled gracefully — SKUs can exist without a sales channel, inventory can track stock without a POS consuming it. The data model should support "headless" modules that produce data even if the consuming module isn't active.

## When to Address
This is a design principle, not a feature to build. The key decision: when building Phase 2 (production) Filament resources, ensure they work without assuming any Phase 7 (DTC) modules exist. Don't create hard dependencies between module groups.

The incoming webhook / CSV import for external sales data could be a small addition to Task 4 (Inventory) — an "external sales import" endpoint that lets third-party POS data feed into inventory tracking.

## Open Questions
- How much effort should go into coexistence features vs. just building a great all-in-one and letting the product speak for itself?
- Is there a risk that making partial adoption too easy removes the incentive to fully switch?
- Should pricing reflect partial adoption (pay for modules you use) or be flat-rate (all-in-one price regardless)?
