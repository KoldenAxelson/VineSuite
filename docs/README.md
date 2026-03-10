# WinerySaaS — Project README
> Start here. This document explains the what, why, and who before you touch any code.
> Read this alongside `architecture.md` and `Task-Generation-Overview-Planning.md`.

---

## What This Is

A **multi-surface SaaS platform** built specifically for small-to-mid-size wineries. It replaces the 3-4 fragmented tools most wineries currently juggle (a production tracker, a DTC platform, a POS, a compliance tool) with a single integrated suite where every piece of data lives once and flows everywhere.

The business is headquartered in Paso Robles, CA — one of the densest winery regions in the United States — giving the founder direct access to the target customer for early sales, feedback, and iteration.

---

## The Problem Being Solved

Most wineries today run something like this:

- **InnoVint or vintrace** for cellar production (~$300-600/mo)
- **Commerce7 or VinesOS** for DTC sales, wine club, and POS (~$299-400/mo + up to 1.5% transaction fee)
- **Sovos ShipCompliant** for DTC shipping compliance (~$100-200/mo)
- **QuickBooks** for accounting (separate)
- **Mailchimp** for email (separate)
- Spreadsheets filling the gaps between all of the above

A mid-size winery spends **$700-1,200/month** on software that doesn't talk to itself cleanly. Data is duplicated, COGS calculations require manual reconciliation, and every club processing run requires exporting from one system and importing into another.

The incumbent market leader in production software (InnoVint) is iOS-only. The DTC market went through a disruptive acquisition (Commerce7 bought WineDirect in early 2025) that displaced ~1,800 wineries from a platform they chose and forced them onto new pricing that includes up to 1.5% transaction fees on top of existing merchant fees. Many of those wineries have resettled, but dissatisfaction with Commerce7's pricing persists — wineries that delayed migration or are now feeling the transaction fee reality remain reachable.

**The window is narrowing but still open.**

---

## The Solution

One platform. Four apps. One API. Everything talks to everything.

| App | Purpose |
|---|---|
| **Management Portal** | Web-based back-office: production, inventory, compliance, reporting, club management, CRM |
| **Cellar App** | Offline-first native mobile app (KMP) for cellar floor operations |
| **POS App** | Offline-first native tablet app (KMP) for tasting room sales, club signups, reservation check-ins |
| **VineBook** | Public winery directory (Astro) that doubles as an SEO-powered acquisition funnel |
| **Embeddable Widgets** | Drop-in JS widgets (store, reservations, club signup, member portal) for winery's existing website |

All surfaces talk to a single **Laravel API** (the brain). Events from the cellar floor sync to the management portal in real-time. A POS sale immediately deducts from the same inventory the winemaker is looking at. A club member updating their CC in the member portal widget on the winery's Squarespace site updates the same record the office manager sees.

---

## Repository Structure (Planned)

```
/
├── api/                    # Laravel — the platform brain
│   ├── app/
│   │   ├── Http/           # API controllers, middleware
│   │   ├── Models/         # Eloquent models
│   │   ├── Events/         # Domain events
│   │   ├── Jobs/           # Queued jobs (club processing, AI, TTB, sync)
│   │   ├── Services/       # Business logic (PaymentProcessor, TTBReporter, etc.)
│   │   └── Filament/       # Management portal resources
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/
│   └── tests/
│
├── shared/                 # KMP shared core (Kotlin) — used by both native apps
│   ├── src/commonMain/
│   │   ├── database/       # SQLDelight schema + queries
│   │   ├── sync/           # Offline event queue + sync engine
│   │   ├── api/            # Ktor API client
│   │   └── models/         # Shared domain models
│   └── build.gradle.kts
│
├── cellar-app/             # Native offline-first mobile app
│   ├── android/            # Jetpack Compose UI (phone layout)
│   ├── ios/                # SwiftUI (phone layout)
│   └── (depends on shared/)
│
├── pos-app/                # Native offline-first tablet POS
│   ├── android/            # Jetpack Compose UI (tablet layout)
│   ├── ios/                # SwiftUI (tablet layout)
│   └── (depends on shared/)
│
├── vinebook/               # Astro — public directory site
│   ├── src/
│   │   ├── pages/          # File-based routing
│   │   │   ├── wineries/   # [slug].astro
│   │   │   ├── regions/    # [region].astro
│   │   │   └── varieties/  # [variety].astro
│   │   ├── components/     # Astro islands (ShopWidget, BookingWidget, etc.)
│   │   └── layouts/
│   └── astro.config.mjs
│
├── widgets/                # Embeddable JS Web Components
│   ├── src/
│   │   ├── store/
│   │   ├── reservations/
│   │   ├── club-signup/
│   │   └── member-portal/
│   └── dist/               # Built widget.js (deployed to Cloudflare R2/CDN)
│
├── architecture.md         # Full technical architecture document
├── Task-Generation-Overview-Planning.md  # Complete feature list by module
└── README.md               # This file
```

---

## Companion Documents

### `architecture.md`
The full technical blueprint. Covers stack decisions, the event log data pattern (read this section carefully — it's the foundation everything else is built on), multi-tenancy strategy, offline sync architecture, AI feature implementation, infrastructure, deployment pipeline, and a phased build sequence. **Read this before writing any code.**

### `Task-Generation-Overview-Planning.md`
A comprehensive feature inventory organized by module, with pricing tier tags ([STARTER] / [GROWTH] / [PRO]) on every feature. Designed to be fed into a large language model to generate granular, atomic task files per module. Also contains a suggested build order (Section 20) and cross-module dependency notes (Section 21) that should inform task sequencing.

---

## Pricing Tiers

| Tier | Monthly Price | Target Customer |
|---|---|---|
| **Starter** | $99/mo | Small boutique winery (under 2,000 cases/year), currently on spreadsheets |
| **Growth** | $199/mo | Established winery with a tasting room, wine club, and online store |
| **Pro** | $349/mo | Multi-label operation, custom crush facility, or winery wanting AI insights and API access |

**Payment processing:**
- Default (Managed Stripe): Platform fee on top of standard Stripe rates. Exact % TBD — target 0.4-0.6% to stay well below Commerce7's 1.5%.
- BYO Processor (Growth+): Flat SaaS fee only, zero transaction fee. Winery brings their own Stripe/Square account.

---

## Revenue Model & Unit Economics

### Per-Winery Revenue (Monthly)

| Scenario | MRR per winery |
|---|---|
| Starter, managed payments, $20k/mo DTC volume | $99 + ~$100 platform fee = **~$199/mo** |
| Growth, managed payments, $50k/mo DTC volume | $199 + ~$250 platform fee = **~$449/mo** |
| Growth, BYO processor | **$199/mo flat** |
| Pro, managed payments, $100k/mo DTC volume | $349 + ~$500 platform fee = **~$849/mo** |
| Pro, BYO processor | **$349/mo flat** |

Blended average across a typical customer mix (mostly Growth, mix of managed/BYO): **~$300-400 MRR per winery.**

### Revenue Milestones

| Milestone | Wineries Needed | MRR | ARR |
|---|---|---|---|
| Ramen profitable (solo founder) | 25 | ~$8,750 | ~$105,000 |
| Comfortable (hire 1) | 75 | ~$26,250 | ~$315,000 |
| Small team (3-4 people) | 200 | ~$70,000 | ~$840,000 |
| Serious SaaS business | 500 | ~$175,000 | ~$2,100,000 |

25 customers in Paso Robles alone is a very achievable early milestone. There are ~250 bonded wineries in the Paso Robles AVA. Capturing 10% of one wine region gets you to ramen profitable.

### Comparable Market Pricing
| Competitor | Price | Weakness |
|---|---|---|
| InnoVint | ~$300-600/mo (opaque) | iOS only, production-only (no DTC) |
| vintrace | From $95/mo (limited) | Steep learning curve, poor onboarding |
| Ekos | From $279/mo | Craft beverage generalist, not wine-specific |
| Commerce7 | $299/mo + 1.5% transactions | Just acquired WineDirect, displacing customers, transaction fee backlash |
| VinesOS | ~$300-500/mo | DTC-only, no production management |
| Running both InnoVint + Commerce7 | ~$600-1,000/mo | Two bills, two logins, manual reconciliation |

**The pitch is simple:** Everything InnoVint + Commerce7 do, in one product, for less than either one alone, with Android support and transparent pricing.

---

## Target Customer Profile

**Primary:** Small-to-mid-size winery, 500–15,000 cases/year, direct-to-consumer focused, 2–20 employees.

**Their day-to-day pain:**
- Winemaker tracking fermentations in a notebook or spreadsheet
- Club processing is a half-day manual ordeal every quarter
- COGS is a guess or a quarterly reconciliation with the accountant
- TTB reporting takes 2-4 hours every month
- "We lose sales because our website can't take orders" OR "our website store doesn't talk to our inventory"
- Just got a letter from Commerce7 saying their WineDirect pricing is changing

**Secondary:** Custom crush facilities (they manage wine for multiple client wineries — the multi-brand Pro feature is specifically built for them).

**Not the target (yet):** Large commercial wineries (100k+ cases), negociants, importers, distributors. These have enterprise ERP needs that are a different product category.

---

## Competitive Moats

**1. Local network effect (Paso Robles)**
Starting in a single dense wine region means word-of-mouth travels fast. Winemakers talk to each other. One happy customer in a wine region is worth 10 cold outbound emails.

**2. Android support + offline POS**
InnoVint is iOS-only. This is a real, documented pain point in user reviews. Android is not a secondary platform — in working cellars, plenty of staff use Android. Shipping day one on both platforms with truly native apps (KMP shared core, Compose + SwiftUI) is a meaningful differentiator. The native offline POS adds a second differentiator: "keeps taking cards when your wifi drops" — a pitch that pays for the subscription in prevented lost sales alone.

**3. Transparent pricing**
Every major competitor hides pricing behind a "contact sales" wall. Putting prices on a webpage is itself a marketing advantage in this market.

**4. True all-in-one (production + DTC)**
No competitor does both production management and DTC well in a single product. The ones that do production (InnoVint, vintrace) don't do DTC. The ones that do DTC (Commerce7, VinesOS) don't do production. This is the core product bet.

**5. VineBook flywheel**
The directory creates a consumer-facing presence that generates inbound leads from winery owners seeing their competitors' shoppable profiles. SEO compounds over time and requires no ongoing spend.

**6. The Commerce7/WineDirect migration window**
~1,800 wineries were pushed off WineDirect onto Commerce7 starting in early 2025, with new pricing that includes transaction fees they didn't sign up for. Many have already resettled, but a significant portion remain unhappy with Commerce7's pricing and are still evaluating alternatives — particularly wineries that delayed their migration or are now experiencing the reality of 1.5% transaction fees on top of merchant processing. The window is narrowing but not closed.

---

## Key Risks

**1. Mobile app complexity**
The KMP shared core (sync engine, local database, API client) is the most technically complex piece of the suite. It powers both the Cellar App and POS App — getting it right means both apps work reliably offline; getting it wrong breaks both. The offline sync architecture must be designed correctly from the start — retrofitting it is painful. See `architecture.md` Section 3 and Section 5 for the prescribed approach.

**2. Compliance surface area**
Alcohol is a regulated industry. TTB reporting errors have legal consequences for the winery. The compliance module must be treated as safety-critical code — well-tested, conservatively built, with clear disclaimers that the software assists with compliance but the winery owner is responsible for filing accuracy.

**3. Payment processing liability**
Operating as a payment facilitator (Stripe Connect platform) involves real money. Stripe's platform agreement has requirements around know-your-customer (KYC) for connected accounts, prohibited business types, and dispute handling. Read it thoroughly before launch.

**4. Churn from switching cost**
Wineries switching from InnoVint have years of production history they don't want to lose. A high-quality data import tool is not optional — it's a sales blocker. "We'll migrate your data" needs to be a real offer, not a placeholder.

**5. Support load during harvest**
Harvest (August–October in California) is when wineries are most dependent on production software and most stressed. Any major bugs or downtime during harvest will result in churned customers and vocal negative reviews. Do not ship major features in July.

---

## Glossary of Wine Industry Terms

For developers unfamiliar with winery operations:

| Term | Meaning |
|---|---|
| **TTB** | Alcohol and Tobacco Tax and Trade Bureau — the federal agency that regulates alcohol production |
| **Form 5120.17** | TTB's monthly Report of Wine Premises Operations — required for every bonded winery |
| **DTC** | Direct-to-Consumer — selling wine directly to the end customer (vs. through a distributor) |
| **AVA** | American Viticultural Area — an officially designated wine grape-growing region (e.g., Paso Robles AVA) |
| **Lot** | A batch of wine tracked from a single source through the production process |
| **Vessel** | Any container holding wine — tank, barrel, flexitank, etc. |
| **Brix** | A measure of sugar content in grape juice — used to determine ripeness and track fermentation |
| **TA** | Titratable Acidity — total acid content of the wine |
| **VA** | Volatile Acidity — primarily acetic acid (vinegar character); legal limits apply |
| **SO2** | Sulfur Dioxide — a preservative added at various stages; free SO2 levels are tracked closely |
| **Rack / Racking** | Moving wine from one vessel to another, leaving sediment (lees) behind |
| **MLF / ML** | Malolactic Fermentation — secondary fermentation that converts malic acid to lactic acid |
| **Crush** | Harvest season — when grapes are received and initial processing begins |
| **Custom Crush** | A facility that makes wine for multiple client wineries using shared equipment |
| **COGS** | Cost of Goods Sold — total cost to produce a bottle, including fruit, materials, labor, overhead |
| **DTC Revenue** | Revenue from direct winery-to-consumer sales (wine club, tasting room, online store) |
| **Wine Club** | A subscription program where members receive regular wine shipments |
| **Allocation** | A limited quantity of wine reserved for specific customers or members |
| **Futures** | Pre-selling wine before it is bottled — customers pay now, receive later |
| **COLA** | Certificate of Label Approval — required by TTB before a wine can be sold commercially |
| **Bonded Winery** | A federally licensed wine production facility operating under TTB bond |
| **Custom Crush Facility** | A winery that produces wine on behalf of other brands using shared production space |
| **Grower Contract** | An agreement to purchase grapes from a vineyard owner at an agreed price per ton |
| **YAN** | Yeast Assimilable Nitrogen — a measure of nutrition available to yeast during fermentation |

---

## Development Philosophy

**Ship the compliance module correctly or don't ship it at all.**
TTB reporting is why wineries will pay for this product. If it generates wrong numbers, wineries stop using it and tell their winemaker friends. Test it against known-good reports from real wineries before launch.

**Offline is a first-class feature, not a fallback.**
The cellar app must work without internet. Period. Not "mostly works" or "works for read operations." If a cellar hand can't complete a work order during harvest because the wifi is down, you have failed the core use case.

**The event log is the source of truth.**
Never mutate historical event records. Never derive the state of a lot by reading a single `lots` table row without considering whether that row is current. The materialized state tables are caches of the event stream — treat them accordingly.

**Pricing is a marketing page, not a sales call.**
Put real prices on the website. Every "contact us for pricing" in this market is an opportunity to be the company that just tells you what it costs.

**Don't ship in July.**
California harvest starts in August. Winemakers are heads-down from August through October. Do not introduce major changes or new features during this window. This is your production freeze period every year.
