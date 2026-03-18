# Multi-Vertical Expansion — CellarSuite Rebrand

**Created:** 2026-03-17
**Status:** ⏳ Deferred → Phase 9+ (low priority, high strategic value)
**Priority:** Low urgency, high optionality — the architecture already supports this
**Estimated Effort:** 3-4 weeks for brewery tenant type once wine platform is feature-complete

---

## Core Idea

Instead of forking VineSuite into separate products per beverage vertical (BrewSuite, DistillSuite, etc.), keep a single codebase with a `business_type` field on the tenant model that drives domain-specific vocabulary, compliance report generators, lab presets, and production UI. One platform, multiple craft beverage verticals.

**Proposed rebrand:** CellarSuite. "Cellar" is universal across wine (barrel caves), beer (lagering, barrel-aged programs), spirits (rickhouses), and cider (barrel aging). Positions the product as craft production management without boxing into a single vertical.

**Trademark note:** VineSuite has a potential collision with vinSuite (existing entity). CellarSuite avoids this.

---

## What Changes Per Tenant Type

| Layer | Wine (current) | Brewery | Distillery |
|-------|---------------|---------|------------|
| Production vocabulary | Lots, varietals, AVA, vintage | Batches, styles, IBU, OG/FG | Runs, mash bills, proof, age statements |
| Vessels | Tanks, barrels, bins | Fermenters, brite tanks, barrels | Stills, fermenters, barrels, rickhouse |
| TTB report | Form 5120.17 (wine) | Form 5130.9 (brewer) | Forms 5110.11/5110.40 (distiller) |
| Lab presets | VA, TA, pH, SO2, Brix, RS | pH, DO, IBU, gravity, color (SRM) | Proof, pH, congeners, methanol |
| DTC shipping | Wine-specific state rules | Beer-specific state rules (fewer states) | Spirits-specific (most restrictive) |
| Fermentation | Weeks-months, Brix curves | Days-weeks, gravity curves | Days (wash), then distillation |
| Certifications | Organic, Biodynamic, SIP | Organic, Craft Brewers Assoc. | Organic, craft spirits certifications |

## What Stays Identical

Everything else: tenant isolation, event log, event source partitioning, sync engine, KMP mobile, POS, inventory, cost accounting, COGS, wine club (becomes "membership club"), ecommerce, CRM, reservations, reporting, IoT sensor layer, billing, auth/RBAC, CI/CD.

---

## TAM Expansion

| Vertical | US Count | Avg Software Spend | Notes |
|----------|----------|-------------------|-------|
| Wineries | ~11,000 | $300-600/mo | Current target |
| Craft breweries | ~9,500 | $200-500/mo | More tech-forward, similar pain points |
| Craft distilleries | ~2,800 | $300-700/mo | Strictest TTB compliance, highest willingness to pay |
| Cideries | ~1,200 | $200-400/mo | Smallest but fastest-growing segment |
| **Combined** | **~24,500** | | Single platform serving entire craft beverage market |

---

## Implementation Approach

1. Add `business_type` enum to tenant model: `wine`, `beer`, `spirits`, `cider`
2. Production module loads vocabulary and validation rules from a config keyed by business type
3. Compliance module dispatches to the correct TTB report generator based on business type
4. Lab module loads default thresholds and metric sets per business type
5. Filament resources use business-type-aware labels and navigation
6. Seeders create demo data per business type
7. Marketing site shows vertical-specific landing pages

---

## Sequencing

This is a Phase 9+ item. Prerequisites: VineSuite must be revenue-generating with wine customers first. The brewery expansion is the first adjacent vertical because the production model overlap is highest and the compliance report (5130.9) is structurally similar to 5120.17.

**Do not attempt this before Phase 10 (Growth Features) is complete.** The wine vertical needs to be fully validated with paying customers before spreading into adjacent markets. The architecture supports it — that's all that matters right now.

---

## Cross-References

- `pricing-and-plan-tiers.md` — Pricing may differ by vertical (distillery compliance is harder, justifies higher tier)
- `iot-sensor-integration.md` — IoT layer is vertical-agnostic (temperature, CO2, humidity apply everywhere)
- `custom-crush-ap-portal.md` — Contract brewing/distilling is the equivalent pattern
- Task 20 (AI Features) — Training data improves with multi-vertical sensor and production data
