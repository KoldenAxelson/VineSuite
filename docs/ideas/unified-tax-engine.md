# Unified Tax Engine

> Status: Idea — currently fragmented across multiple tasks
> Created: 2026-03-15
> Source: Market research gap analysis
> Priority: Low-Medium — cross-cutting concern, important but not urgent until DTC sales go live

---

## The Problem

Wine sales involve three overlapping tax layers, and no current winery software handles all of them in one engine:

1. **Federal excise tax** — $1.07/gallon for still wine under 16% ABV (with CBMA small producer credits reducing this to as low as $0.535/gallon for the first 30,000 gallons). Different rates for sparkling, dessert, and hard cider.
2. **State excise tax** — varies by state, ranging from $0.20/gallon (California) to $3.52/gallon (Alaska). Some states have additional local excise taxes.
3. **Sales tax** — varies by state, county, and city. Some states exempt wine from sales tax. Some states tax wine at a different rate than other goods.

Currently in the VineSuite pipeline, these are handled in different places:
- TTB compliance (Task 06) tracks federal excise obligations as part of the 5120.17 report
- DTC compliance rules (Task 06, sub-task 7) track state shipping laws including some tax rules
- POS (Task 09) presumably handles sales tax at point of sale
- eCommerce (Task 11) needs tax calculation at checkout
- Wine club (Task 10) needs tax calculation for batch processing
- Wholesale (Task 22) needs excise tax handling for distributor invoicing

No single service owns tax calculation, and none of the task specs describe how CBMA credits are applied or tracked.

## What a Unified Engine Looks Like

A single `TaxCalculationService` that every sales channel calls:

```
Input: SKU, quantity, destination state/county/city, sales channel, customer type
Output:
  - Federal excise: $X.XX (with CBMA credit applied: $Y.YY)
  - State excise: $X.XX
  - Sales tax: $X.XX (rate: Z.ZZ%)
  - Total tax: $X.XX
  - Tax-exempt: true/false (wholesale, certain customer types)
```

### Key Behaviors

- **CBMA (Craft Beverage Modernization Act) credit tracking** — small producers get reduced federal excise rates. The credit has annual limits. The engine needs to track year-to-date usage across all removal events (POS, eCommerce, club, wholesale) and apply the correct rate.
- **State excise by destination** — DTC shipments are taxed at the destination state's rate, not the origin state
- **Sales tax integration** — either built-in rate tables (high maintenance) or integrate with a tax API (TaxJar, Avalara, Sovos). Recommendation: API integration — sales tax is too complex and changes too frequently to maintain in-house.
- **Channel-aware** — wholesale is often excise-exempt (distributor pays). DTC always includes excise. Tasting room sales in California are sales-tax-exempt for wine consumed on-premises (yes, really).
- **Audit trail** — every tax calculation stored with the order for compliance reporting

## CBMA Credit Tracking

This deserves special attention because it's a significant cost savings that many small wineries don't fully capture:

- First 30,000 wine gallons removed per year: $0.535/gallon credit (total rate $1.07 - $0.535 = $0.535)
- Next 100,000 gallons: $0.17/gallon credit
- Over 130,000 gallons: no credit

The engine needs a running year-to-date total of all taxable removals (across all sales channels) and automatically applies the correct credit rate. This connects directly to the TTB 5120.17 Part IV (removals from bond) data.

## Where This Lives

This is a cross-cutting service, not a standalone task. It should be built as part of the first sales channel that goes live (likely Task 09 POS or Task 11 eCommerce) and then consumed by every subsequent channel.

Recommendation: Add a sub-task to Task 09 (POS) or create a shared service during Phase 6 that all subsequent sales tasks depend on.

## Dependencies

- Task 06 (TTB Compliance) — federal excise rates, CBMA credit tiers
- Task 09 (POS) — first consumer of sales tax calculation
- Task 11 (eCommerce) — DTC tax calculation at checkout
- Task 10 (Wine Club) — batch processing tax calculation
- Task 22 (Wholesale) — excise tax handling

## Open Questions

- Build sales tax calculation in-house or integrate with TaxJar/Avalara? (Strong recommendation: integrate — sales tax nexus rules are a nightmare)
- How do bonded winery vs. taxpaid warehouse removals affect the calculation? (Some wineries maintain a tax-paid storage area)
- Should the engine handle tip tax calculations for tasting room POS? (Tips are not taxable in most states but the UI needs to separate them from the sale price)
