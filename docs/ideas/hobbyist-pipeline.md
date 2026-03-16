# Hobbyist-to-Commercial Pipeline

> Status: Idea — not in current pipeline
> Created: 2026-03-15
> Source: Market research gap analysis
> Priority: High — this is the growth engine

---

## The Opportunity

There are an estimated 250,000–500,000 home winemakers in the US. They use paper notebooks, Sharpie-on-cork systems, and duct-taped Excel spreadsheets. The few digital tools available (VinWorks, CellarTracker, BrewTrax) leave significant gaps. Forum users consistently request: persistent searchable digital records, automatic ABV calculation, mobile data entry, batch-to-batch search, cost tracking, and simple label design.

Federal law permits any adult to produce 100–200 gallons per year for personal use without excise tax. The moment they intend to sell, they need a TTB permit, state license, COLA approval, and full compliance. This regulatory boundary creates the natural upgrade trigger into VineSuite's commercial tiers.

Capturing 5% of the hobbyist pool as free users (12,500–25,000), with 1–2% converting to commercial production annually, generates 125–500 new paying customers per year — all with years of production data already in the system, creating switching costs that make the platform sticky.

## How This Differs From the Current Free Tier

The current free tier (in `pricing-and-plan-tiers.md`) is designed for small commercial wineries trying the full platform with volume limits (25 lots, 10 vessels, 100 club members). A hobbyist doesn't need lots, vessels, TTB compliance, club management, or POS. They need a fundamentally different experience:

| Current Free Tier | Hobbyist Experience |
|---|---|
| 25 lots, 10 vessels | 5 active batches, 200-gallon cap |
| Full Filament portal | Simplified mobile-first UI |
| TTB reporting (manual) | No compliance (personal use) |
| Club, POS, eCommerce (limited) | None of this |
| Schema-per-tenant | Could be lighter-weight |

## Proposed Hobbyist Feature Set

### Core (Free Forever)

- **Up to 5 active batches** with full logging — dates, SG/Brix readings, additions, racking, tasting notes, photos
- **Built-in winemaking calculators** — ABV from SG, SO2 addition (molecular/free/total), acid adjustments, chaptalization, dilution, blending
- **Cost tracking per batch** — ingredients, supplies, equipment amortization. "This batch of Cab cost me $4.20/bottle."
- **Basic cellar inventory** — what's aging, what's bottled, what's been consumed, what you gave away
- **200-gallon total volume cap** — aligns with TTB personal use maximum for two-adult household
- **Mobile-first with offline capability** — garage/basement cellars have poor connectivity
- **Batch timeline view** — visual timeline of everything that happened to a batch, from crush to consumption

### Growth (Hobbyist Paid — $5–10/month)

- Unlimited batches
- Historical batch comparison (side-by-side Brix curves, cost comparison)
- Simple label design/printing (for gifts and personal bottles)
- Advanced analytics (ABV trends, cost trends, varietal comparisons)
- Data export (CSV, PDF batch reports)

### Community (Network Effects)

- **Recipe sharing** — publish a batch as a "recipe" (variety, process steps, additions, timeline) for other hobbyists to follow
- **Varietal guides** — community-contributed best practices per variety
- **Forum integration** — link to WineMakingTalk, HomeBrewTalk wine subforum

## The Conversion Funnel

```
Hobbyist Free (250k addressable)
  ↓ 5% capture = 12,500 users
  ↓
Hobbyist Paid ($5-10/mo)
  ↓ Natural ceiling: 5+ batches, wants advanced tools
  ↓
"Going Commercial" Trigger
  ↓ Gets TTB permit, needs compliance
  ↓ All production history is already in VineSuite
  ↓
Commercial Basic ($99/mo)
  ↓ 1-2% of hobbyist base annually = 125-500 new customers
  ↓ Zero CAC, years of data lock-in
  ↓
Growth tiers as the winery scales
```

## Architecture Considerations

### Could Hobbyists Use the Existing Tenant Model?

Option A: **Yes, schema-per-tenant for everyone.** Simple, consistent, and the research shows free schemas cost nearly nothing. The hobbyist just sees a simplified UI with most navigation hidden.

Option B: **Shared schema for hobbyists, migrate to dedicated schema on commercial conversion.** Cheaper at extreme scale (50,000 hobbyists × empty schemas could strain PostgreSQL), but adds migration complexity.

Recommendation: **Start with Option A.** The pricing doc already established that schema-per-tenant costs are negligible. Revisit only if hobbyist adoption exceeds 10,000 users and DB overhead becomes measurable. The advantage is zero migration friction when a hobbyist goes commercial — their data is already in the right place.

### Mobile App vs. Web

The cellar app (Task 08) is a KMP native app designed for commercial cellar workflows (work orders, additions, transfers, barrel scanning). The hobbyist needs something simpler — probably a PWA or a "hobbyist mode" within the same app that shows a stripped-down interface.

Recommendation: **Hobbyist mode within the cellar app.** Same KMP codebase, different navigation/UI shell based on tenant plan. Reduces development surface area.

## Timing

This is awkward to sequence. The pipeline is laser-focused on getting to a shippable commercial product (Phase 5 "sell it here" gate). Building hobbyist features before that gate means writing code that doesn't generate revenue for the initial launch.

But the research's growth model depends on having a hobbyist base that's been accumulating for 12–18 months before the commercial product is mature enough for Growth-tier features. If hobbyist capture starts after the Growth tier ships (Phase 7), the conversion pipeline doesn't produce meaningful numbers until 2028+.

### Possible Compromise

- Build a minimal hobbyist web app (batch logging, calculators, mobile-friendly) as a side project during Phase 4–5 development. It shares the API and tenant model but has its own lightweight frontend.
- Don't let it delay the commercial launch.
- Market it on home winemaking forums (WineMakingTalk, Reddit r/winemaking) as a free tool.
- Let the hobbyist base grow organically while commercial sales ramp.

## Open Questions

- Is the hobbyist market large enough to justify dedicated development time before commercial PMF is proven?
- Could a simple landing page + waitlist capture hobbyist interest without building the product yet?
- Should hobbyist features be a separate product/brand or clearly part of VineSuite?
- What's the actual infrastructure cost of 10,000+ free schemas?
