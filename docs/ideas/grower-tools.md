# Grower Management Tools

> Status: Idea — not in current pipeline as a distinct user segment
> Created: 2026-03-15
> Source: Market research gap analysis
> Priority: Medium — additional market segment with referral network effects

---

## The Opportunity

IGGPRA (Independent Grape Growers of the Paso Robles Area) has 130+ grower members. Virtually no dedicated software exists for managing their operations. AgCode targets large farming operations at $5,000–$20,000+/year. Croptracker starts at $5/month but isn't wine-specific. A staggering 46% of wineries use spreadsheets for vineyard management and 41% use nothing at all — the numbers for standalone grape growers are likely worse.

A grower managing 50 acres across 3 buyer wineries needs: block tracking, contract management, harvest scheduling, pricing history, quality documentation, and buyer communication. None of this exists in the pipeline today.

## How Growers Differ From Winery Vineyard Management

Task 17 (Vineyard Management) is winery-facing — it tracks a winery's own vineyard blocks and purchased fruit sources. The grower is a data record, not a user.

A grower-facing tool flips the perspective:

| Winery View (Task 17) | Grower View (this idea) |
|---|---|
| "I bought 10 tons of Syrah from Smith Vineyard" | "I sold 10 tons of Syrah to Paso Cellars" |
| Grower is a vendor record | Grower is the primary user |
| One winery, multiple grape sources | One grower, multiple buyer wineries |
| Harvest event creates a lot | Harvest event creates an invoice |

## Proposed Feature Set

### Grower Free Tier

For growers selling fewer than 20 tons (aligns with small grower reality):

- **Block management** — same model as Task 17 VineyardBlock (variety, clone, rootstock, acreage, year planted)
- **Seasonal activity logging** — pruning, canopy management, irrigation, spray applications
- **Buyer contract tracking** — price per ton, volume commitments, Brix adjustments, payment terms, contract documents
- **Harvest scheduling** — projected vs. actual tonnage per block per buyer
- **Quality documentation** — sampling data (Brix, pH, TA, YAN) with buyer-shareable reports
- **Pricing history** — what you sold, to whom, at what price, over multiple vintages
- **Simple invoicing** — generate an invoice from harvest data (tons × price/ton ± Brix adjustments)

### The Network Effect

Here's where it gets interesting: if a grower is tracking their blocks in VineSuite and they sell fruit to a VineSuite winery, the harvest data can flow directly into the winery's lot creation — zero re-entry. The grower logs "harvested 10 tons from Block 7 for Paso Cellars" and the winery sees an incoming lot with variety, block, tonnage, Brix, and sampling data pre-populated. The grower confirms, the winery confirms, and the lot enters production with full provenance.

This is the data gravity the research describes: every new participant enriches every other participant's data.

## Architecture

### Tenant Model

Growers could be standard tenants with a `tenant_type` of `grower` (vs. `winery`). Their schema has vineyard blocks, activities, samples, sprays, and contracts — but not lots, vessels, TTB compliance, or sales channels.

Alternatively, growers could share the winery schema but with a grower-specific navigation shell in Filament that hides irrelevant modules. This is simpler but less clean.

Recommendation: **Separate tenant type.** The grower data model is different enough (contracts, invoicing, buyer relationships) that it warrants its own schema layout. The shared models (VineyardBlock, VineyardActivity, SprayApplication) can be the same migration files — just not all tenant migrations run for grower tenants.

### Cross-Tenant Data Flow

The grower-to-winery harvest data flow requires a cross-tenant mechanism. Options:

1. **API-based:** Grower's system calls the winery's API to create a pending lot. Winery approves. Clean but requires both to be VineSuite users.
2. **Central matchmaking:** A central-schema table matches grower harvests to winery purchase orders. Both parties confirm.
3. **Manual fallback:** Grower exports a harvest report PDF/CSV. Winery imports it. Works even if only one party uses VineSuite.

Recommendation: Start with option 3 (manual export/import), add option 2 when the network is large enough to justify it.

## Timing

Phase 7+ at the earliest. Requires Task 17 (Vineyard Management) to exist first since grower tools share the vineyard data models. Could be a Phase 8 addition or a parallel workstream after commercial launch.

The grape-marketplace idea (already in `grape-marketplace.md`) is related but distinct — that's a trading board, this is operational tools. They could share infrastructure.

## Revenue Model

- **Free:** Up to 20 tons sold, 5 buyer contracts, basic reporting
- **Paid ($29/month):** Unlimited tonnage, unlimited contracts, invoicing, cross-tenant data flow, advanced reporting

Even at $29/month, 130 IGGPRA members represents ~$45,000 ARR — modest but the referral value to winery sign-ups is the real payoff.

## Open Questions

- Do growers actually want software, or is the relationship too informal (handshake deals, phone calls)?
- Would IGGPRA endorse or promote a grower tool to their membership?
- Is invoicing enough, or do growers need full AP/AR (which becomes an accounting product)?
- How does this interact with the grape marketplace idea?
