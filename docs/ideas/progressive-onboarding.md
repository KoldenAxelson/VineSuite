# Progressive Onboarding & Complexity Management

> Status: Idea — should influence UI decisions starting in Phase 2 (Filament resources)
> Created: 2026-03-10
> Competitor lesson: vinCreative, vintrace

---

## The Problem
Every all-in-one winery platform that came before failed at onboarding. vinCreative's reviews cite it as "daunting at first glance" with "too many options." vintrace requires "trial and error to set up systems to work the way that is best for your company." The breadth that makes the product valuable is the same breadth that overwhelms new users on day one.

VineSuite will have 25 modules when fully built. Showing all of them to a 2,000-case winery with 3 employees on their first login would replicate exactly the same failure.

## Proposed Approach
Progressive disclosure — the portal grows with the winery's usage instead of exposing everything at once.

**Tier-based navigation:** Starter customers see production, compliance, and cellar management in their sidebar. Growth features (club, eCommerce, POS) appear only when they upgrade or enable them. Pro features (AI, wholesale, public API) are hidden until the Pro tier.

**Onboarding wizard:** First login walks through a focused setup: winery name, vessel list, first lot. Not a 45-minute configuration marathon. Get them to a working state in under 10 minutes.

**Feature activation, not feature walls:** Modules like wine club or eCommerce shouldn't appear as grayed-out upsells. They should be absent until relevant. When the winery reaches a natural trigger (e.g., first bottling run completed), surface a suggestion: "Ready to start selling? Enable your online store."

**Role-based defaults:** A cellar hand logging into the portal sees work orders and vessel status. Not COGS reports. Not club member management. The UI adapts to who's using it without requiring manual configuration.

## Architecture Compatibility
Filament supports dynamic navigation and conditional resource registration. The sidebar can be built programmatically based on tenant plan tier and user role. No architectural changes needed — this is a UI/UX pattern applied during Phase 2 Sub-Task 13 (Filament resources) and carried forward.

## When to Address
This should be a design principle from Phase 2 onward, not a retrofit. Every Filament resource created should include a visibility condition based on plan tier and feature activation state.

## Open Questions
- Should feature activation be per-winery (owner enables modules) or automatic (module appears when prerequisites are met)?
- How much onboarding customization is needed per winery size? A 500-case startup vs a 20,000-case established operation have very different first-day needs.
