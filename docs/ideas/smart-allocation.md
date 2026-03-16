# Smart Inventory Allocation Engine

> Status: Idea — not in current pipeline
> Created: 2026-03-15
> Source: Market research gap analysis
> Priority: Medium-High — key integrated-suite differentiator

---

## The Problem

Winery owners make one decision that affects everything: how to distribute limited production across channels. A winery with 800 cases of 2024 Cabernet must decide how many go to wine club, tasting room floor, eCommerce, wholesale distributors, library hold, and events. Get it wrong and you're either leaving money on the table (too much to low-margin wholesale) or breaking commitments (not enough for club members who expect their allocation).

Most wineries make this decision in a spreadsheet, or in the owner's head, or not at all — they sell until they run out and deal with the consequences.

## What an Integrated Suite Enables

Only a system with production data, inventory levels, club member counts, channel-specific sales history, margin data, and demand forecasts in one database can optimize this decision. Every data point lives in VineSuite already (or will by Phase 7):

- **Current inventory** (Task 04) — what's available
- **Upcoming bottling** (Task 02) — what's coming
- **Club member counts by tier** (Task 10) — committed allocation
- **Channel margins** (Task 05) — DTC at 70%+ vs. wholesale at 40–50%
- **Historical sell-through** (Task 19) — how fast each SKU moves per channel
- **Demand forecasts** (Task 20) — AI-predicted sell-through

## Proposed Feature

### Allocation Recommendation Engine

Given a SKU and available quantity, recommend optimal allocation across channels:

```
2024 Estate Cabernet Sauvignon — 800 cases available

Recommended Allocation:
  Wine Club (Q2 run)     240 cases  (30%)  — 312 members × avg 0.77 btls
  Tasting Room           200 cases  (25%)  — 90-day supply at current velocity
  eCommerce              120 cases  (15%)  — matches demand forecast
  Wholesale              160 cases  (20%)  — Acme Distributing contract min
  Library Hold            50 cases  (6%)   — 5-year hold for library program
  Events/Reserves         30 cases  (4%)   — spring event allocation

Revenue Projection: $62,400
  vs. equal split: $54,200 (+15.1%)
  vs. current pattern: $57,800 (+8.0%)

⚠ Warning: Wholesale allocation is at contract minimum.
   Increasing to 200 cases would fulfill Acme's preferred qty
   but reduces projected revenue by $2,800.
```

### Key Behaviors

1. **Considers channel-specific constraints** — wholesale contracts with minimums, club tier commitments, tasting room par levels
2. **Optimizes for revenue or margin** (owner's choice) — DTC always wins on margin, but some wholesale is often contractually required
3. **Respects compliance** — DTC volume limits per state, club allocation rules
4. **Learns from history** — sell-through rates by channel, seasonal patterns, event performance
5. **Handles scarcity gracefully** — when there isn't enough for everyone, prioritize by configurable rules (club first, then DTC, then wholesale)
6. **What-if scenarios** — "What if I hold back 100 cases for library? What if I increase wholesale by 50?"

## When to Build

Phase 7–8. Requires inventory (Task 04), wine club (Task 10), eCommerce (Task 11), wholesale (Task 22), and ideally demand forecasting (Task 20) to all exist before the engine has enough data to be useful.

Could start as a simpler "allocation worksheet" in Task 04 that lets the owner manually set allocations per channel, then add the optimization engine as an AI feature in Task 20.

## Tier Placement

- **Basic/Pro:** Manual allocation worksheet (set quantities per channel, system tracks and warns on over-allocation)
- **Max:** AI-powered optimization recommendations, what-if scenarios, revenue projections
