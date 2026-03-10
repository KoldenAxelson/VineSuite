# Grape Marketplace (Tenant-to-Tenant Fruit Trading)

## The Insight

Local wineries constantly buy and sell grapes to each other. A great harvest year means surplus fruit to offload; a tough year means scrambling to source varietals you're short on. This is an annual reality for nearly every small-to-mid winery, and right now it happens through phone calls, text threads, and word of mouth.

## The Feature

A lightweight marketplace where VineSuite tenants can list available fruit (or post "wanted" requests) visible to other participating tenants in their region.

### What It Is

- **Listings**: "We have 2.5 tons of Mourvèdre available, Adelaida District, $2,800/ton, available through October 15th"
- **Wanted posts**: "Looking for 1 ton of Viognier, Paso Robles AVA, need by September 20th"
- **Direct messaging** between interested parties (no bidding wars, no auction mechanics — this is a community board, not eBay)
- **Transaction logging**: Once both parties confirm a deal, VineSuite records the grape transfer — variety, tonnage, price, source vineyard, destination winery — feeding directly into each tenant's lot creation and compliance paperwork

### What It Isn't

- Not a full commodity exchange
- Not a payment processor (wineries settle payment between themselves as they always have)
- Not a social network — no profiles, no feeds, no likes
- Not mandatory — tenants opt in via Settings > Marketplace

## Why It Matters Strategically

### Network Effect as Retention

This is one of the few features that gets *more valuable* as more wineries join VineSuite. If 30 wineries in Paso Robles are on the platform and 25 of them participate in the marketplace, switching to a competitor means losing access to the easiest way to source and sell fruit in your region. That's not lock-in — it's genuine utility that happens to create switching cost.

### Compliance Shortcut

Grape purchases require TTB documentation. If both buyer and seller are on VineSuite, the transaction can auto-populate:
- Buyer's lot creation (new lot from purchased fruit)
- Seller's inventory reduction
- Both parties' TTB transfer records
- Harvest weight tickets (if integrated with scales)

This alone saves hours of paperwork per transaction and reduces compliance errors.

### Community Insights Synergy

Marketplace activity (anonymized) feeds into the Community Insights BI tool:
- Regional pricing trends by varietal ("Paso Cab Sauv averaging $3,200/ton this season")
- Supply/demand signals ("Mourvèdre surplus in Adelaida, shortage in Willow Creek")
- Vintage-over-vintage price movement

This data is genuinely valuable for planning — and it's only possible because the trades happen on-platform.

## Architecture Considerations

### Visibility & Privacy

- Listings are only visible to other VineSuite tenants with marketplace enabled
- Region filtering: wineries see listings within their AVA/region first, expandable to state/national
- Pricing is visible to participants but anonymized in Community Insights aggregation
- Completed deals are private between the two parties

### Data Model (Sketch)

Central schema (not tenant-scoped — this is cross-tenant by nature):

```
marketplace_listings
├── id (uuid)
├── seller_tenant_id (fk → tenants)
├── type (enum: available | wanted)
├── varietal (string)
├── appellation (string, nullable)
├── vineyard_name (string, nullable)
├── tonnage_available (decimal)
├── price_per_ton (decimal, nullable — some prefer "call for pricing")
├── available_through (date)
├── notes (text, nullable)
├── status (enum: active | pending | fulfilled | expired | cancelled)
├── created_at
├── updated_at

marketplace_messages
├── id (uuid)
├── listing_id (fk → marketplace_listings)
├── sender_tenant_id (fk → tenants)
├── recipient_tenant_id (fk → tenants)
├── body (text)
├── created_at

marketplace_transactions
├── id (uuid)
├── listing_id (fk → marketplace_listings, nullable)
├── buyer_tenant_id (fk → tenants)
├── seller_tenant_id (fk → tenants)
├── varietal (string)
├── tonnage (decimal)
├── price_per_ton (decimal)
├── total_price (decimal)
├── transfer_date (date)
├── confirmed_by_buyer (boolean, default false)
├── confirmed_by_seller (boolean, default false)
├── created_at
├── updated_at
```

### Cross-Tenant Architecture Note

This is one of the few features that lives in the **central schema**, not tenant schemas. The marketplace tables reference `tenant_id` as a foreign key rather than relying on schema isolation. This is intentional — the whole point is cross-tenant visibility.

The messaging system should be minimal (think Craigslist reply, not Slack). No read receipts, no typing indicators, no threads. Just simple back-and-forth to negotiate a deal.

### Plan Tier Gating

- **Free**: Can browse and post listings (up to 3 active). Can message. No transaction logging. This is intentional — free users posting listings is what populates the marketplace and makes it valuable for everyone. Restricting free users to read-only would starve the network.
- **Basic**: Up to 10 active listings. Can message. Basic transaction logging (manual confirmation, no compliance auto-fill).
- **Pro**: Unlimited listings. Full transaction logging with auto-populated compliance records.
- **Max**: Everything in Pro + marketplace analytics dashboard (your buy/sell history, price trends for your varietals, regional supply signals).

This makes the marketplace a gentle upsell lever — free users can see the value, basic users get a taste, and pro/max users get the full compliance integration that saves real time.

## Monetization Decision: No Transaction Fees

**Decision: The marketplace takes zero cut from trades. This is honey, not a revenue line.**

### Why No Fee

A transaction fee — even 1-2% — changes the psychology. Users start comparing the platform to free alternatives (texting their neighbor), and it creates an incentive to negotiate on-platform but settle off-platform to dodge the fee. Grape trades are big, infrequent, and low-volume (2-5 purchases per winery per year). Even at 2% on a $7,000 transaction, that's ~$140/winery/year — not meaningful revenue, but meaningful annoyance.

### What the Marketplace Generates Instead

The marketplace is a customer acquisition and retention engine, not a profit center:

1. **Free tier population**: Every hobbyist listing surplus grapes makes the marketplace more useful for paying wineries. Free users aren't a cost center — they're inventory. A hobbyist with surplus Zinfandel who lists it for sale is making the platform more valuable for the pro winery who needs to source fruit in a pinch.

2. **Organic upgrade pressure**: A free user lists grapes, gets a buyer, and hits the "confirm trade" button — which would auto-create compliance records if they were on Pro. They're not being upsold with a popup. They're experiencing the friction of *not* having the feature they now want. Fundamentally different dynamic.

3. **Cold start solution**: You don't need to convince 50 wineries to join a marketplace. You need to convince 50 wineries to sign up for free winery software — a much easier pitch. The marketplace populates itself as a side effect.

4. **Retention moat**: The marketplace is a network. The more wineries participate, the harder it is to leave. No transaction fee means no friction discouraging participation.

### Future Exception

If VineSuite eventually builds payment processing into the platform (escrow, invoicing, net-30 terms), a small processing fee is expected and justified — that's a financial service, not a bulletin board tax. But that's Phase 6+ territory and a different product surface entirely.

### Pipeline Consideration

This model benefits from a lightweight onboarding pipeline specifically targeting hobbyists and small growers who may not need full winery software but do have grapes to sell. These users fill the marketplace for free while costing nearly nothing to host (see `pricing-and-plan-tiers.md` — ~$0.03-0.05/month per free tenant). As they grow or start needing compliance features, the upgrade path is already in front of them.

## Implementation Timing

**Not before Phase 4.** This requires:
- Stable tenant model with plan gating (Phase 2)
- Lot management and compliance records in place (Phase 3)
- Cross-tenant infrastructure patterns established

But the central-schema data model should be *designed* during Phase 2 so the migration doesn't conflict with anything built in Phase 3.

## Competitive Advantage

No winery software currently does this. InnoVint, Commerce7, vintrace — none of them have any concept of inter-winery features. This is a blue ocean feature that:
1. Creates genuine network effects (rare in vertical SaaS)
2. Solves a real operational pain point (grape sourcing is chaotic)
3. Generates unique data for Community Insights
4. Provides natural upsell pressure from free → paid tiers
5. Makes VineSuite the "place where deals happen" in a wine region — that's a moat
