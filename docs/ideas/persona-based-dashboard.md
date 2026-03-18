# Persona-Based Dashboard — Role-Aware Tabbed Overview

> Status: 🟡 Triaged → Pre-Task 7
> Created: 2026-03-18
> Source: Internal planning session
> Priority: High — the dashboard is the first thing every user sees and it's currently a placeholder
> Estimated Effort: 1–2 days (stock Filament widgets, no custom UI)

---

## The Problem

VineSuite's dashboard at `/portal` is a placeholder showing "Welcome to {winery_name}" with no data. Every user — winemaker, cellar hand, owner, accountant — lands on the same empty page and has to navigate the sidebar to find what matters to them. This creates two problems:

1. **New users see no value on first login.** The most important screen in the app communicates nothing.
2. **Different roles care about different things.** A winemaker checking active fermentations and lab alerts has zero interest in the margin report. An accountant checking COGS doesn't need vessel utilization. Showing everything to everyone creates noise; showing nothing to everyone creates abandonment.

## Proposed Solution

### Tabbed Dashboard with Role-Based Visibility

Use Filament's native `BaseDashboard::getTabs()` to create persona-specific tabs. Each tab renders a curated set of stock Filament widgets (StatsOverview, Table, Chart). Super-admins (owner/admin) see all tabs and can switch between personas. Other roles see only their relevant tab(s).

**Critical constraint:** Stay 100% stock Filament. No custom Tailwind, no custom Blade components. All widgets use `StatsOverviewWidget`, `TableWidget`, or `ApexChartWidget` from the existing `leandrocfe/filament-apex-charts` plugin.

### Tab Definitions

**Winemaker Tab** — Production pulse for the winemaker persona.
- ActiveFermentationsTableWidget — lots currently fermenting with latest brix/temp/days since start
- LabAlertsTableWidget — analyses that breached thresholds (sorted by severity)
- BulkWineStatsWidget (existing) — vessel volume, book volume, variance, active lots/vessels
- FermentationCurveChart (existing, adapted) — show the most recent active fermentation

**Cellar Tab** — Daily operations for cellar hands.
- OpenWorkOrdersStatsWidget — open/overdue/completed-today counts
- VesselUtilizationStatsWidget — total capacity, current fill %, vessels at capacity, empty vessels
- RecentActivityTableWidget — last 15 events from the event log (transfers, additions, bottling runs)

**Business Tab** — Financial overview for owners and accountants.
- CostReportsStatsWidget (existing) — COGS summaries, avg $/bottle, total cost tracked
- CostByVintageTableWidget (existing) — cost breakdown by vintage
- MarginReportTableWidget (existing) — selling price vs. COGS by SKU

**Compliance Tab** — Regulatory status for owners and accountants.
- ComplianceStatusStatsWidget — next TTB report due, draft/unfiled reports count, expiring licenses within 90 days
- (Future: TTB section stats widgets could be surfaced here when viewing a specific period)

**Inventory Tab** — Stock status across roles.
- BulkWineStatsWidget (existing) — vessel/book volume, variance
- LowStockSkusTableWidget — case goods SKUs below configurable threshold
- PendingPurchaseOrdersStatsWidget — open PO count and total value

**Overview Tab** — Simplified read-only summary for tasting room staff and read-only users.
- High-level stats only: total active lots, total SKUs, recent bottling runs
- No actionable data, no drill-down

### Role → Tab Visibility Matrix

| Tab | owner | admin | winemaker | cellar_hand | accountant | tasting_room_staff | read_only |
|-----|-------|-------|-----------|-------------|------------|--------------------|-----------|
| Winemaker | ✓ | ✓ | ✓ (default) | | | | |
| Cellar | ✓ | ✓ | | ✓ (default) | | | |
| Business | ✓ (default) | ✓ (default) | | | ✓ (default) | | |
| Compliance | ✓ | ✓ | | | ✓ | | |
| Inventory | ✓ | ✓ | ✓ | ✓ | ✓ | | |
| Overview | ✓ | ✓ | | | | ✓ (default) | ✓ (default) |

Super-admins (owner/admin) see all 6 tabs. Each other role sees 1–3 tabs. The "(default)" marker indicates which tab is active on page load for that role.

### New Widgets Needed

All use stock Filament widget base classes and query existing models — no new migrations, no new API endpoints.

1. **ActiveFermentationsTableWidget** — `TableWidget` querying `FermentationRound` where `status = in_progress`, joined with `Lot` for name/variety and latest `FermentationEntry` for current brix/temp.
2. **LabAlertsTableWidget** — `TableWidget` querying `LabAnalysis` joined against `LabThreshold` where value exceeds min/max. Show lot name, parameter, value, threshold, and how far out of range.
3. **OpenWorkOrdersStatsWidget** — `StatsOverviewWidget` with stats: Open, Overdue (due_date < today), Completed Today, Total Hours This Week.
4. **VesselUtilizationStatsWidget** — `StatsOverviewWidget` with stats: Total Capacity, Current Fill %, Vessels at Capacity, Empty Vessels.
5. **ComplianceStatusStatsWidget** — `StatsOverviewWidget` with stats: Next TTB Due (date), Draft Reports (count), Expiring Licenses (within 90 days count), Filed This Year (count).
6. **RecentActivityTableWidget** — `TableWidget` querying `Event` ordered by `performed_at desc`, limit 15. Show timestamp, entity type, operation, performed by.
7. **LowStockSkusTableWidget** — `TableWidget` querying `CaseGoodsSku` joined with `StockLevel` where total quantity is below a threshold (e.g., 12 cases). Show SKU name, vintage, current stock, locations.
8. **PendingPurchaseOrdersStatsWidget** — `StatsOverviewWidget` with stats: Open POs (count), Total Value, Oldest Open PO (days), Expected Deliveries This Week.

### Existing Widgets Reused

- `BulkWineStatsWidget` — Winemaker tab, Inventory tab
- `CostReportsStatsWidget` — Business tab
- `CostByVintageTableWidget` — Business tab
- `MarginReportTableWidget` — Business tab
- `FermentationCurveChart` — Winemaker tab (needs adaptation to auto-select most recent active round)

### Widgets NOT Surfaced on Dashboard

- `TTBSectionAStatsWidget`, `TTBSectionBStatsWidget`, `TTBSectionALinesWidget`, `TTBSectionBLinesWidget` — These are report-specific (require a report ID) and belong on the TTB Report view page, not the dashboard. The ComplianceStatusStatsWidget provides the dashboard-level compliance summary instead.
- `PhysicalCountStatsWidget` — Requires a specific count ID. Stays on the Physical Count page.

## Implementation Notes

- All widgets keep `$isDiscovered = false` and are explicitly registered in `Dashboard::getWidgets()` filtered by active tab.
- `getTabs()` reads `auth()->user()->role` to determine which tabs to render.
- Each tab returns a `Tab::make()` with an icon and badge (e.g., lab alerts count as a badge on the Winemaker tab).
- The FermentationCurveChart needs a small adaptation: instead of requiring `$roundId` as a public property set by a parent page, the dashboard version should auto-select the most recent `in_progress` fermentation round. Consider a dashboard-specific subclass or a default behavior when `$roundId` is null.

## When to Build

**Pre-Task 7.** The dashboard is infrastructure — it surfaces data that already exists. Every model and relationship it queries is already built and tested (Phases 2–6). Building it now means every user sees immediate value before the codebase shifts focus to KMP mobile infrastructure where there's no portal-visible output for weeks.

## Tier Placement

All tiers. The dashboard is core UX, not a premium feature. Widget *content* may vary by tier (e.g., margin reports require cost data which is a Pro feature), but the dashboard structure itself is available to everyone.

## Cross-References

- Task 07 (KMP Shared Core): No dependency. Dashboard is portal-only.
- `progressive-onboarding.md`: Dashboard is the primary onboarding surface — new users should see populated widgets immediately (seeded demo data helps here).
- `pricing-and-plan-tiers.md`: Some widgets show data from gated features (COGS, margins). Widgets should gracefully handle missing data with empty states rather than hiding entirely, so users see what they'd get on a higher tier.
