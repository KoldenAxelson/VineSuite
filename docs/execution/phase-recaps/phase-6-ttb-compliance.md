# Phase 6 Recap — TTB & Regulatory Compliance

> Duration: 2026-03-16 → 2026-03-16
> Task files: `06-ttb-compliance.md` | INFO: `06-ttb-compliance.info.md`

---

## Delivered

- **TTB Form 5120.17 auto-generation** — complete event-sourced report engine that aggregates the immutable event log into the 5-part TTB Monthly Report of Wine Premises Operations, with separate Section A (bulk wines, 32 lines) and Section B (bottled wines, 21 lines) balance sheets, all 6 tax class columns per CBMA, whole-gallon rounding, 5 production methods, 4 receipt types, 13 removal categories, 5 loss types, and dual bottling entries
- **Wine type classification** — alcohol-based classification into 6 TTB Form 5120.17 columns (Not Over 16%, Over 16-21%, Over 21-24%, Artificially Carbonated, Sparkling, Hard Cider) using most recent lab analysis, with CBMA-correct 16% threshold and automatic review flags when lab data is missing
- **PDF export** — DomPDF-generated report matching the official TTB Form 5120.17 layout with all 6 wine type columns, winery header, and signature lines
- **Report review workflow** — Filament page for winemakers to review auto-generated reports, drill into source events per line item, add notes, and approve (draft → reviewed → filed)
- **Bond & permit tracking** — License model supporting TTB permits, state licenses, and COLAs with expiration tracking, configurable renewal reminders, and document upload
- **DTC shipping compliance** — all 50 states + DC with current rules, annual per-customer shipment tracking, and eligibility checking with case/gallon limit enforcement
- **Lot traceability** — one-step-back/one-step-forward trace from grape source through blending to bottling and sale, with full timeline view
- **Certification compliance** — advisory flagging of prohibited inputs for USDA Organic, Demeter Biodynamic, SIP Certified, CCOF, and Salmon-Safe certifications
- **Label compliance engine** — four TTB labeling rules (varietal 75%, AVA 85%, vintage 95%, California conjunctive labeling) with blend-aware composition resolution, `LabelProfile` model anchoring claims to blend trials or SKUs, immutable lock-at-bottling with compliance snapshot, and remediation suggestions calculating exact gallons needed to reach thresholds. Absorbed from `ideas/label-compliance-engine.md`.
- **Demo data** — 3 seeded TTB reports (filed, reviewed, draft) with full line items, plus 5 licenses including TTB permit and state licenses

## Architecture Decisions

- **Section A/B independent balance sheets:** TTB Form 5120.17 requires separate accounting for bulk and bottled wine. Section A tracks bulk wine from production through removal. Section B tracks bottled wine from packaging through sale. Bottling events create dual entries (decrease from A, increase to B). This is the single most important structural decision in the compliance layer.

- **CBMA 16% threshold:** Per Craft Beverage Modernization Act (effective 01/01/2018), the table/dessert wine boundary is 16% ABV, not the pre-CBMA 14%. This affects column classification for every wine lot.

- **Whole gallon rounding:** TTB practice says "there is no requirement to extend beyond whole numbers." All gallon figures use `round($x, 0)`. This deviates from the original spec which said "nearest tenth."

- **6 tax class columns:** The post-CBMA form requires 6 columns (a-f), not the original 4 wine types. Column (d) Artificially Carbonated and (f) Hard Cider are detected from event payload `wine_style` field. Column (c) Over 21-24% catches fortified wines between dessert and spirits territory.

- **Forward-compatible event types:** New calculators handle 20+ event operation types (sweetening_completed, fortification_completed, wine_transferred_bonded, breakage_reported, etc.) even though not all have corresponding production UI yet. When the production module adds these workflows, the TTB layer is ready.

- **Fixed line numbers per form row:** Multiple wine types on the same form line share the same `line_number` value, differentiated by `wine_type` column. This matches the actual form layout where each row has columns (a)-(f).

## Deviations from Spec

- **Rounding changed from tenths to whole gallons:** Spec gotcha said "nearest tenth of a gallon." TTB practice says whole numbers. Changed after legal compliance audit.
- **4 wine types expanded to 6 columns:** Spec listed `table/dessert/sparkling/special_natural`. Actual form has 6 columns per CBMA. Rewritten.
- **14% threshold corrected to 16%:** Spec said "table wine <14% alc." CBMA changed this to 16% in 2018. Corrected.
- **No dedicated lot traceability PDF export:** Spec mentioned "exportable as PDF for audit/recall documentation." The trace is viewable in Filament but PDF export was deferred. Traceability data is available via the service for future PDF integration.
- **No Filament UI for certification management:** Certifications are set on WineryProfile directly. A settings page can be added later.

## Patterns Established

- **TTB service directory:** `app/Services/TTB/` contains all compliance report generation services. Each Part calculator is a standalone class injected into TTBReportGenerator. Detailed in `06-ttb-compliance.info.md`.
- **Dual-section report structure:** Report data uses `section_a`/`section_b` top-level keys, each with `summary` and `lines` arrays. This pattern should be followed for any future TTB form variants.
- **Category-based line item routing:** TTBReportGenerator sums line items by category string (e.g., `wine_bottled`, `transferred_bonded`, `bottled_breakage`) to compute section totals. New categories can be added to calculators without changing the generator — just ensure the category string is included in the generator's sum conditions.
- **Wine type badge partial:** `views/filament/pages/partials/wine-type-badge.blade.php` renders consistent color-coded badges for all 6 TTB columns. Reusable across compliance UI pages.
- **ComplianceSeeder for demo data:** `database/seeders/ComplianceSeeder.php` creates realistic TTB reports and licenses. Uses `updateOrCreate` for idempotency. Called from DemoWinerySeeder.
- **Label compliance as a lockable profile:** `LabelProfile` stores label claims and links to blend/SKU. `LabelComplianceService` evaluates all four rules independently. `lock()` snapshots compliance state into JSONB for permanent audit record. Lot `source_ava` field captures AVA origin at grape receiving time.

## Known Debt

1. **Part III receipts won't fire until bulk wine receipt events exist** — `stock_received` events from inventory module don't carry `volume_gallons` in payload. Impact: low (most small wineries don't receive wine in bond). Fix: enrich stock_received payload or add dedicated bulk receipt event.
2. **New event types not yet emittable from production UI** — Sweetening, fortification, amelioration, transfers to bonded premises, exports, etc. have calculator support but no production workflow to emit them. Impact: medium. Fix: add to production module as features are needed.
3. **Packaging cost name-matching fragility** — Carried from Phase 5. BottlingComponent.product_name matched to DryGoodsItem.name via ilike for COGS. Impact: low.
4. **Auto-deduction from bottling not wired** — Carried from Phase 4. BottlingService doesn't auto-deduct dry goods inventory. Impact: medium.

## Diagrams Created/Updated

- `docs/audit/phase-6-ttb-compliance-architecture.mermaid` — created — full TTB compliance layer architecture (needs update for CBMA corrections, see post-phase amendments)
