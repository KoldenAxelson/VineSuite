# Pricing Model & Plan Tier Architecture

> **🟢 DELIVERED (Phase 2)** — Tier enum, plan helpers, and Stripe Cashier integration are in code. Absorbed into Phase 2 via triage. Remaining unbuilt items below.
> Created: 2026-03-10
> Context: Competitive analysis, modular adoption strategy, freemium vs individual module pricing
>
> **Still needed:** `PlanFeatureService` (feature gating + volume limits), annual billing toggle, Community Insights BI pipeline, downgrade grace-period enforcement.

---

## Decision: Freemium Over Individual Module Pricing

Individual module pricing ($30/month per app) was considered and rejected for three reasons:
1. **Support complexity** — permutations of enabled/disabled modules create edge cases that cost more in support time than they generate in revenue
2. **Fragmented analytics** — can't measure product-market fit when every customer has a different product
3. **Undercuts the value proposition** — selling modules individually competes head-to-head with InnoVint (production) and Commerce7 (DTC) on their turf instead of selling the integrated story

Freemium with generous limits gets the same foot-in-the-door benefit while keeping the product whole. The winery experiences the integrated platform from day one. Upgrades feel like unlocking what they already have, not buying a different product.

## Tier Structure

### Free — "Try the whole thing"
The entire platform, with volume limits. Every feature visible, every module accessible. The winery hits natural ceilings as they grow.

**Limits:**
- 1 staff account (owner only, no team invites)
- 25 active lots
- 10 vessels
- 100 club members
- 500 orders/month (POS + eCommerce combined)
- 1 POS terminal
- Basic TTB reporting (data entry, no auto-generation)
- Community support only (Tier 0 FAQ + Tier 1 slim LLM)
- VineSuite branding on widgets and hosted store
- No public API access

**What this gives the winery:** Enough to run a very small operation (under 1,000 cases) completely on VineSuite. Enough to evaluate every feature with real data before committing money. Enough to get hooked.

**What this costs you:** Nearly nothing. Schema-per-tenant means free tier tenants are isolated. Empty tables cost zero. The volume limits prevent abuse. The 1-user limit prevents "enterprise on the free tier."

### Basic — $99/month — "The working winery"
Production + compliance + cellar app + basic DTC. Replaces InnoVint.

**Unlocks over Free:**
- 5 staff accounts
- Unlimited lots and vessels
- Unlimited cellar app devices
- Auto-generated TTB reports (5120.17)
- COGS tracking and reporting
- Lab/fermentation tracking
- CSV export of all data
- Email support (Tier 2 LLM + Tier 3 email escalation)
- Remove VineSuite branding from widgets

### Pro — $179/month — "The tasting room"
Everything in Basic + full DTC suite. Replaces InnoVint + Commerce7.

**Unlocks over Basic:**
- 15 staff accounts
- Unlimited POS terminals
- Wine club management (unlimited members)
- Hosted eCommerce store
- Embeddable widgets
- Reservation & event management
- CRM + email campaigns
- QuickBooks/Xero integration
- Priority email support + visible phone number

### Max — $299/month — "The enterprise"
Everything in Pro + advanced features for larger operations.

**Unlocks over Pro:**
- Unlimited staff accounts
- Public API access (webhook subscriptions, scoped tokens)
- AI features (weekly digest, demand forecasting, fermentation prediction)
- Multi-brand / multi-location support
- Wholesale management + distributor portal
- Custom reporting + BI dashboard
- Dedicated support channel
- VineBook enhanced listing

## Architecture (Implemented)

### Plan enum and helpers
The Tenant model has `plan` as an enum: `free`, `basic`, `pro`, `max`. Default is `free`.

Helper methods on the Tenant model:
- `isFreePlan()` — true if on free tier
- `hasActiveAccess()` — true if free OR has active Stripe subscription
- `hasActiveSubscription()` — true only if has active Stripe subscription (false for free)
- `planRank()` — numeric rank (0=free, 1=basic, 2=pro, 3=max)
- `isDowngradeTo($plan)` — true if target plan is lower than current
- `hasPlanAtLeast($plan)` — true if current plan meets or exceeds the given level

### Still needed (Phase 2)
- `PlanFeatureService` — resolves plan → feature set with per-tenant overrides
- Feature gate middleware — `Route::middleware(['feature:wine_club'])`
- Filament visibility conditions per resource
- Limit enforcement at the service layer

## The Upgrade Experience

When a free-tier winery hits a limit, the experience should be:
1. Clear message: "You've reached the 25-lot limit on the Free plan"
2. Immediate context: "Basic plan includes unlimited lots for $99/month"
3. One-click upgrade path (Stripe Checkout, pre-filled with their existing tenant info)
4. Instant access — no waiting, no migration, no downtime. The feature gate reads the updated plan and all limits lift immediately.

This is why the data model is always complete regardless of tier. Upgrading doesn't create data — it removes ceilings on data that already exists.

## The Downgrade Experience

Downgrades happen in two scenarios: voluntary plan change (Max → Pro) or subscription cancellation (any paid → Free). Both follow the same rules.

### Downgrade Rules

**Data is never deleted.** A winery that downgrades from Pro to Basic still has all their club members, orders, and eCommerce data. They just can't create new ones through gated features.

**Features become read-only, not invisible.** If a Pro winery downgrades to Basic:
- Club members list is still visible but the "New Member" and "Process Batch" buttons are disabled
- eCommerce store shows "Store paused — upgrade to Pro to re-enable"
- Existing widgets stay rendered for 30 days (grace period), then show a "Powered by VineSuite" placeholder
- Reservation calendar is viewable but new reservations can't be created
- CRM customer list is visible but email campaigns can't be sent

**Volume limits re-engage gradually.** If a Basic winery downgrades to Free:
- They keep all existing lots/vessels/staff (even if over the free limit)
- They cannot create NEW lots beyond the 25 limit, NEW vessels beyond 10, or invite NEW staff beyond 1
- Existing data stays functional — a winery with 40 lots can still log additions to all 40
- The message: "You have 40 lots on a plan that includes 25. Your existing lots are unaffected, but new lots require an upgrade."

**Subscriptions use end-of-billing-cycle downgrades.** When a winery downgrades from Max to Pro:
- Stripe's `swap()` handles proration
- The plan column updates immediately
- Feature access adjusts at end of current billing cycle (grace period)
- This prevents "downgrade, use Max features for the remaining 28 days, get refunded"

**Cancellation to Free is different.** When a winery cancels entirely:
- Stripe subscription enters grace period (cancelled but active until period ends)
- `isInGracePeriod()` returns true — full feature access continues
- When grace period expires, plan flips to `free`
- All data preserved, volume limits re-engage, gated features become read-only

### Implementation

The `PlanFeatureService` (to be built in Phase 2) handles all of this:
```php
// In a controller or service
$features = PlanFeatureService::resolve($tenant);

// Feature gate — can they USE this feature?
if (!$features->can('wine_club.create')) {
    return ApiResponse::error('Wine club management requires the Pro plan.', 403, meta: [
        'upgrade_url' => route('billing.checkout', ['plan' => 'pro']),
        'current_plan' => $tenant->plan,
        'required_plan' => 'pro',
    ]);
}

// Volume limit — can they create MORE of this resource?
if (!$features->withinLimit('active_lots', $currentCount)) {
    return ApiResponse::error('You\'ve reached the lot limit on your current plan.', 403, meta: [
        'current_count' => $currentCount,
        'plan_limit' => $features->limit('active_lots'),
        'upgrade_url' => route('billing.checkout', ['plan' => 'basic']),
    ]);
}

// Read-only check — can they VIEW but not MODIFY?
if ($features->isReadOnly('wine_club')) {
    // Show data but disable mutation buttons in Filament
}
```

The Tenant model already has `hasPlanAtLeast()` for simple checks:
```php
// Quick check without the full feature service
if ($tenant->hasPlanAtLeast('pro')) {
    // Enable DTC features
}
```

## Competitive Pricing Context

| Competitor | What They Cover | Price |
|---|---|---|
| InnoVint | Production only | ~$149/month |
| Commerce7 Lite | DTC only (under $100K revenue) | $59/month |
| Commerce7 Full | DTC only | ~$150-200/month |
| VineSuite Free | Everything (limited) | $0 |
| VineSuite Basic | Production + compliance | $99/month |
| VineSuite Pro | Production + compliance + DTC | $179/month |
| VineSuite Max | Everything + AI + API + wholesale | $299/month |

The Free tier is the wedge. No competitor offers a free tier. A winery can run their entire small operation on VineSuite Free and only pay when they outgrow it. That's a fundamentally different sales conversation than "pay us $149/month and hope it works."

## Annual Billing Discount

All paid tiers offer a 2-months-free discount on annual billing. This is a standard SaaS practice that improves cash flow predictability and reduces churn.

### Annual Prices

| Tier | Monthly | Annual (per month) | Annual (total) | Savings |
|------|---------|-------------------|----------------|---------|
| Basic | $99/month | $82.50/month | $990/year | $198 saved |
| Pro | $179/month | $149.17/month | $1,790/year | $358 saved |
| Max | $299/month | $249.17/month | $2,990/year | $598 saved |

### Why This Matters for Wineries

Winery cash flow is seasonal. Revenue peaks after harvest (October-December) and during spring/summer tasting room season. January-February is when wineries have cash from holiday sales and are planning the year's budget. Annual billing lets them lock in during a flush period rather than facing a monthly charge during lean months (April-August) when they might consider canceling.

### Implementation

Stripe handles this natively through billing intervals on the same Price object. The Cashier integration in Task 01 already supports monthly vs. yearly intervals. Implementation is:

1. Create annual Price objects in Stripe for each tier (alongside existing monthly prices)
2. Add billing interval toggle to the checkout flow (Stripe Checkout supports this out of the box)
3. `PlanFeatureService` doesn't care about billing interval — it only checks `plan` enum
4. Stripe webhook for `customer.subscription.updated` already handles plan changes

No new models, no new migrations, no new middleware. Just Stripe configuration and a UI toggle.

### Upgrade/Downgrade with Mixed Intervals

- Monthly → Annual: Stripe prorates the remaining monthly period, then starts the annual subscription
- Annual → Monthly: Takes effect at end of the annual period (no mid-year refund)
- Annual tier change (e.g., Basic Annual → Pro Annual): Stripe prorates the difference for the remaining annual period

All of this is handled by Stripe's `swap()` method in Cashier. No custom logic needed.

---

## Pricing Strategy & Acquisition Context

### Current Prices Are the Floor

These prices are intentionally below market value. The strategy is market capture, not margin optimization. At full scope, VineSuite replaces a $2,000-$15,000+/year software stack for $1,188-$3,588/year. That's a 60-75% cost reduction for the customer.

**Why undercut deliberately:**

1. **Lower the trust barrier.** New software from a new vendor is risky. A price that feels almost too good removes one objection from the sales conversation.
2. **Maximize adoption speed.** In a market of ~11,000 US wineries, speed of capture matters more than per-customer revenue. Every winery on VineSuite is one a competitor can't reclaim without a painful data migration.
3. **Build the data moat.** Community Insights, grower-winery data flow, and AP portal network effects all compound with customer count. The 500th customer makes the platform meaningfully better for the first 499.
4. **Make acquisition math compelling.** An acquirer doesn't just buy current ARR — they buy a customer base with massive pricing headroom. If VineSuite has 500 wineries at current prices, an acquirer can reasonably 2-3x prices and still leave customers saving money vs. alternatives. That makes VineSuite's value to an acquirer a multiple of its current revenue, not just a multiple of ARR.

### Max Tier Will Increase

Max pricing is partly tied to AI token costs (Anthropic API for weekly digests, demand forecasting, churn scoring, fermentation prediction). As usage scales and AI features expand, Max will increase. Basic and Pro are less cost-sensitive — their marginal infrastructure cost per tenant is negligible.

### International Expansion as Growth Multiplier

The US has ~11,000 wineries. Globally there are 60,000+ wineries across the EU, Australia, South America, and South Africa. The TTB compliance module is US-specific, but production management, inventory, DTC, wine club, and cost accounting are universal. Localization of compliance modules (EU wine regulations, Australian WET, etc.) opens a market 5-6x larger than the US alone. This is a significant valuation multiplier for any acquirer evaluating growth potential beyond the current customer base.

---

## Community Insights (BI Feature)

Aggregated, anonymized data across the VineSuite customer base becomes a valuable product in itself — average COGS by variety, regional pricing trends, club retention benchmarks, seasonal production patterns. Individual wineries can't generate this data alone.

### How It Works

**Contributing is the default.** Every tenant's operational data (production volumes, COGS, order patterns, club metrics) feeds into an anonymized aggregation pipeline. No winery names, no specific identifiers — just data points in a pool.

**Opt-out via Settings > Privacy.** A single toggle: "Contribute anonymized data to Community Insights." On by default. Turning it off is immediate — the pipeline stops ingesting that tenant's data. Previously contributed data remains in the aggregate (it's already anonymized and can't be extracted per-tenant).

**Access requires contribution.** If you opt out of contributing, you lose access to the BI dashboards. The rule is simple and fair: you can't read the benchmarks if you're not helping create them. The UI makes this explicit — the toggle shows: "Community Insights are powered by anonymized data from participating wineries. Opting out will disable your access to industry benchmarks."

### What Gets Aggregated (Examples)
- Average COGS per bottle by variety, region, and vintage
- Club member retention rates by tier structure and pricing
- Tasting room conversion rates (visitors → club signups)
- Seasonal production volume patterns
- Addition usage patterns (SO2 rates, fining agent preferences)
- Bottling waste percentages by format
- Work order completion rates and average times

### What Never Gets Aggregated
- Winery names, staff names, customer PII
- Specific pricing or revenue figures attributable to a single winery
- Customer lists, email addresses, order details
- TTB permit numbers or compliance data

### Tier Access
- **Free:** Contributes data. Sees basic benchmarks (2–3 metrics, e.g., "Your COGS is 15% above average for Pinot Noir").
- **Basic:** Contributes data. Sees production benchmarks (COGS, yield, addition patterns).
- **Pro:** Contributes data. Sees production + DTC benchmarks (club retention, conversion rates, pricing trends).
- **Max:** Contributes data. Full BI dashboard with custom queries, trend analysis, exportable reports.

### Architecture Notes
The aggregation pipeline reads from tenant event logs (read-only) and writes to a central analytics schema in the public database. This is a scheduled job (nightly or weekly) that:
1. Iterates over participating tenants
2. Queries their event logs for the relevant metrics
3. Writes anonymized, aggregated records to `public.community_insights`
4. Never stores per-tenant breakdowns — only aggregate statistics

The `WineryProfile` model (or tenant settings) gets a `community_insights_enabled` boolean, defaulting to `true`. The `PlanFeatureService` checks both the plan tier (for access level) and this flag (for contribution status) before rendering BI dashboards.

### Privacy and Legal
- Terms of service must disclose the default opt-in during signup
- Aggregation must be genuinely anonymized (k-anonymity: no metric published unless at least 10 wineries contribute to it)
- GDPR compliance: opt-out is a data processing preference, not a data deletion request (already-aggregated data is non-personal)
- Consider a brief, plain-language explainer in the onboarding wizard: "VineSuite uses anonymized data from all participating wineries to generate industry benchmarks. You can opt out anytime in Settings."

## Open Questions
- Should the Free tier have a time limit (e.g., 12 months) or just volume limits? Volume limits feel fairer and create natural upgrade triggers.
- Should the Free tier include the cellar app? (Leaning yes — it's the stickiest feature and the best demo of the integrated experience.)
- At what point does a "free tier winery" cost more in infrastructure than it's worth? (Probably never, given schema-per-tenant costs are negligible, but worth monitoring.)
