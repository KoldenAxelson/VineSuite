# VineSuite — Business Context

> Load this for pricing, marketing, onboarding, or competitive positioning.
> **Not needed for coding tasks.**

---

## Pricing Tiers

| Tier | Price | Who It's For |
|---|---|---|
| **Starter** | $99/mo | Boutique winery (<2k cases/yr), currently on spreadsheets |
| **Growth** | $199/mo | Tasting room + wine club + online store |
| **Pro** | $349/mo | Multi-label / custom crush / wants AI + API access |

**Payment processing:** Managed Stripe (platform fee, target 0.4-0.6%) or BYO Processor (Growth+, flat SaaS fee, zero transaction fee).

---

## Revenue Model

Blended MRR: ~$300-400 per winery.

| Milestone | Wineries | MRR | ARR |
|---|---|---|---|
| Ramen profitable (solo founder) | 25 | ~$8,750 | ~$105k |
| Comfortable (hire 1) | 75 | ~$26,250 | ~$315k |
| Small team (3-4) | 200 | ~$70,000 | ~$840k |
| Serious SaaS | 500 | ~$175,000 | ~$2.1M |

25 wineries in Paso Robles = ramen profitable. ~250 bonded wineries in the AVA. 10% of one region does it.

---

## Competitive Landscape

| Competitor | Price | Weakness |
|---|---|---|
| InnoVint | ~$300-600/mo (opaque) | iOS only, production-only |
| vintrace | From $95/mo (limited) | Steep learning curve |
| Ekos | From $279/mo | Generalist, not wine-specific |
| Commerce7 | $299/mo + 1.5% txn fee | WineDirect acquisition backlash |
| VinesOS | ~$300-500/mo | DTC-only, no production |

**The pitch:** Everything InnoVint + Commerce7 do, one product, less than either alone, with Android + transparent pricing.

---

## Target Customer

**Primary:** Small-to-mid winery, 500-15k cases/yr, DTC-focused, 2-20 employees.

Pain points: fragmented tools, manual club processing, guessed COGS, 2-4hr monthly TTB, disconnected inventory, Commerce7 pricing resentment.

**Secondary:** Custom crush facilities (multi-brand Pro feature).

**Not yet:** Large commercial (100k+ cases), negociants, importers, distributors.

---

## Moats

1. **Local network effect** — Starting in Paso Robles. Winemakers talk. One happy customer = 10 cold emails.
2. **Android + offline POS** — InnoVint is iOS-only. "Keeps taking cards when wifi drops."
3. **Transparent pricing** — Every competitor hides behind "contact sales."
4. **True all-in-one** — Nobody does production + DTC well in one product.
5. **VineBook flywheel** — SEO directory generates inbound leads. Compounds over time.
6. **Commerce7 migration window** — ~1,800 displaced wineries, many still evaluating.

---

## Risks

1. **Mobile complexity** — KMP shared core is the hardest engineering. Must be right from start.
2. **Compliance** — TTB errors have legal consequences. Safety-critical code.
3. **Payment liability** — Stripe Connect requirements (KYC, disputes).
4. **Switching cost** — Data import tool is a sales blocker, not optional.
5. **Harvest load** — Aug-Oct = max dependency on software. No major changes in July.

---

## Feature Inventory

See `feature-inventory.md` for the full module-by-module list with tier tags.
