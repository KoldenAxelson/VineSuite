# VineSuite — Business Context

> Load this when working on pricing, marketing, onboarding, or competitive positioning.
> **Not needed for coding tasks** — this is business context only.

---

## Pricing Tiers

| Tier | Monthly Price | Target Customer |
|---|---|---|
| **Starter** | $99/mo | Small boutique winery (under 2,000 cases/year), currently on spreadsheets |
| **Growth** | $199/mo | Established winery with a tasting room, wine club, and online store |
| **Pro** | $349/mo | Multi-label operation, custom crush facility, or winery wanting AI insights and API access |

**Payment processing:**
- Default (Managed Stripe): Platform fee on top of standard Stripe rates. Target 0.4-0.6% to stay below Commerce7's 1.5%.
- BYO Processor (Growth+): Flat SaaS fee only, zero transaction fee. Winery brings their own Stripe/Square account.

---

## Revenue Model

Blended average across typical customer mix (mostly Growth, mixed managed/BYO): ~$300-400 MRR per winery.

| Milestone | Wineries | MRR | ARR |
|---|---|---|---|
| Ramen profitable (solo founder) | 25 | ~$8,750 | ~$105,000 |
| Comfortable (hire 1) | 75 | ~$26,250 | ~$315,000 |
| Small team (3-4) | 200 | ~$70,000 | ~$840,000 |
| Serious SaaS | 500 | ~$175,000 | ~$2,100,000 |

25 customers in Paso Robles alone is achievable — ~250 bonded wineries in the Paso Robles AVA. 10% of one region = ramen profitable.

---

## Competitive Landscape

| Competitor | Price | Weakness |
|---|---|---|
| InnoVint | ~$300-600/mo (opaque) | iOS only, production-only (no DTC) |
| vintrace | From $95/mo (limited) | Steep learning curve, poor onboarding |
| Ekos | From $279/mo | Craft beverage generalist, not wine-specific |
| Commerce7 | $299/mo + 1.5% transactions | WineDirect acquisition backlash, transaction fee resentment |
| VinesOS | ~$300-500/mo | DTC-only, no production management |

**The pitch:** Everything InnoVint + Commerce7 do, in one product, for less than either alone, with Android support and transparent pricing.

---

## Target Customer

**Primary:** Small-to-mid-size winery, 500-15,000 cases/year, DTC-focused, 2-20 employees.

Pain points: fragmented tools, manual club processing, guessed COGS, 2-4 hour monthly TTB reporting, website store disconnected from inventory, Commerce7 pricing changes.

**Secondary:** Custom crush facilities (multi-brand Pro feature built for them).

**Not the target (yet):** Large commercial wineries (100k+ cases), negociants, importers, distributors.

---

## Competitive Moats

1. **Local network effect** — Starting in Paso Robles. Winemakers talk. One happy customer = 10 cold emails.
2. **Android + offline POS** — InnoVint is iOS-only. Native offline POS: "keeps taking cards when wifi drops."
3. **Transparent pricing** — Every competitor hides behind "contact sales."
4. **True all-in-one** — No competitor does both production and DTC well in one product.
5. **VineBook flywheel** — SEO-powered directory generates inbound leads. Compounds over time.
6. **Commerce7/WineDirect migration window** — ~1,800 displaced wineries, many still evaluating alternatives.

---

## Key Risks

1. **Mobile app complexity** — KMP shared core (sync engine, local DB) is the hardest engineering. Must be right from the start.
2. **Compliance surface area** — TTB errors have legal consequences. Safety-critical code.
3. **Payment processing liability** — Stripe Connect platform requirements (KYC, disputes).
4. **Churn from switching cost** — Data import tool is a sales blocker, not optional.
5. **Harvest support load** — Aug-Oct is when wineries depend most on software. No major changes in July.

---

## Feature Inventory

See `feature-inventory.md` in this directory for the comprehensive module-by-module feature list with pricing tier tags.
