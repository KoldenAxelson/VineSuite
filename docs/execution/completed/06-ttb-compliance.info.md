# TTB & Regulatory Compliance — Completion Record

> Task spec: `docs/execution/tasks/06-ttb-compliance.md`
> Phase: 6

---

## Sub-Task 1: TTB Report Data Aggregation Engine
**Completed:** 2026-03-16
**Status:** Done

### Key Decisions
- **Event source mapping**: Added `'compliance' => ['ttb_', 'license_', 'compliance_']` to `config/event-sources.php`. Chose `compliance` over `ttb` as the source name since it covers licenses and DTC rules too — broader than just TTB.
- **Wine type classification**: Uses most recent LabAnalysis `alcohol` record for the lot. Defaults to `table` wine when no lab data exists, with `needs_review: true` flag. Sparkling and special natural detected from event payload `wine_style` field.
- **Alcohol threshold**: ≤14% = table, >14% and ≤24% = dessert, per TTB rules. Exactly 14.0% counts as table wine.
- **Part II wine produced**: Aggregates `lot_created` events (initial volume = crush/reception) and `blend_finalized` events. These are the TTB-reportable production events.
- **Part IV removals**: Uses `bottling_completed` events (not stock movements) per handoff doc carry-over debt note. Also includes `stock_sold` events with `volume_gallons` payload field.
- **Part V losses**: Calculated from transfer variance (negative only), racking lees, bottling waste (volume × waste_pct / 100), and filtering losses. Positive transfer variance (gains) excluded — they are not losses.
- **Part I balance**: Purely derived from Parts II-V totals + opening inventory. `closing = opening + produced + received - removed - losses`. Balance check uses 0.1 gallon tolerance for floating-point rounding.
- **Opening inventory**: Must be manually provided for the first report. Subsequent months will use previous report's closing inventory via `getPreviousClosingInventory()` (wired to TTBReport model in Sub-Task 2).
- **Rounding**: All gallon figures rounded to nearest tenth (one decimal place), per TTB Form 5120.17 requirements.

### Deviations from Spec
- PartThreeCalculator (wine received in bond) looks for `stock_received` events with a `volume_gallons` payload field. Current stock_received events don't carry volume_gallons — they track case quantities. This means Part III will return empty results until either (a) bulk wine receipt events are added, or (b) stock_received payload is enriched with gallonage. Not a blocker — most small wineries don't receive wine in bond frequently.
- Lees loss calculation from racking: uses `lees_gallons` payload field if present, falls back to weight-to-gallon conversion (lees_weight / 8.34) with a review flag. The existing `rack_completed` events store `lees_weight` — conversion is approximate.

### Patterns Established
- **TTB service directory**: `app/Services/TTB/` houses all TTB report generation services. Each Part calculator is a standalone class injected into TTBReportGenerator.
- **Line item structure**: All part calculators return arrays with consistent shape: `{line_number, category, wine_type, description, gallons, source_event_ids, needs_review}`. This standardizes rendering and storage.
- **WineTypeClassifier as shared dependency**: Injected into Parts II-V. Single source of truth for lot → wine type mapping. Classifier result includes `needs_review` flag and `source` field for audit transparency.
- **Review flags**: Lines where wine type couldn't be determined from lab data are flagged `needs_review: true`. These propagate to the full report's `review_flags` array for the winemaker review UI.
- **Test group**: All Phase 6 tests use `->group('compliance')`.

### Test Summary
- `tests/Feature/TTB/TTBReportGeneratorTest.php` — 17 tests covering:
  - Wine type classification (table, dessert, sparkling, boundary, no-lab-data, most-recent-analysis)
  - Part I balance equation (correct closing inventory, zero-activity, line items)
  - Part II wine produced (aggregation, date filtering, event ID linking)
  - Part IV wine removed (bottling events)
  - Part V losses (bottling waste, transfer variance, positive variance exclusion)
  - Full report generation (complete 5-part report, empty month, table/dessert separation, review flags, rounding)
  - Event source registration (ttb_ and license_ prefixes → compliance)
- Known gaps: Part III (wine received) not tested with real bulk-wine events — deferred until bulk receipt events exist.

---

## Sub-Task 2: TTB Report Model & Generation Flow
**Completed:** 2026-03-16
**Status:** Done

### Key Decisions
- **UUID primary keys**: Both `ttb_reports` and `ttb_report_lines` use UUID PKs, consistent with all VineSuite models.
- **Status workflow**: `draft → reviewed → filed → amended`. Only drafts can be regenerated or reviewed.
- **JSONB data column**: TTBReport stores the full generated report data in a JSONB `data` column for fast retrieval without joining line items. Line items table exists for granular querying and drill-down.
- **Unique constraint**: `(report_period_year, report_period_month)` ensures one report per period. Regeneration deletes-and-replaces the existing draft.
- **Idempotent job**: `GenerateMonthlyTTBReportJob` replaces existing drafts but skips reviewed/filed reports to prevent accidental data loss.
- **Cascade delete**: `ttb_report_lines` cascade-deletes when the parent report is deleted.

### Files Created
- `database/migrations/tenant/2026_03_17_400001_create_ttb_reports_table.php`
- `database/migrations/tenant/2026_03_17_400002_create_ttb_report_lines_table.php`
- `app/Models/TTBReport.php`
- `app/Models/TTBReportLine.php`
- `app/Jobs/GenerateMonthlyTTBReportJob.php`

### Test Summary
- `tests/Feature/TTB/TTBReportModelTest.php` — Model CRUD, unique constraint, relationships, cascade delete, generation job idempotency, skip-reviewed behavior.

---

## Sub-Task 3: TTB Report Review UI
**Completed:** 2026-03-16
**Status:** Done

### Key Decisions
- **Filament resource**: `TTBReportResource` in "Compliance" navigation group (sort 1). Index/view/review routes.
- **Review page as custom Livewire page**: `ReviewTTBReport` is a full-page Livewire component rather than a standard Filament edit page. This gives full control over the drill-down UX.
- **Drill-down by line**: Clicking a line item loads its source events from the `events` table by ID. Winemakers can verify the exact operations backing each TTB figure.
- **Line notes**: Reviewers can add notes to individual lines (stored in line's `notes` column) before approving.
- **Approve action**: Changes status to `reviewed`, sets `reviewed_by` and `reviewed_at`, logs `ttb_report_reviewed` event.
- **Generate action**: Available from the list page header. Form collects month, year, and opening inventory, then dispatches `GenerateMonthlyTTBReportJob` synchronously.

### Files Created
- `app/Filament/Resources/TTBReportResource.php`
- `app/Filament/Resources/TTBReportResource/Pages/ListTTBReports.php`
- `app/Filament/Resources/TTBReportResource/Pages/ViewTTBReport.php`
- `app/Filament/Resources/TTBReportResource/Pages/ReviewTTBReport.php`
- `resources/views/filament/pages/ttb-report-review.blade.php`

---

## Sub-Task 4: TTB Report PDF Export
**Completed:** 2026-03-16
**Status:** Done

### Key Decisions
- **DomPDF via barryvdh/laravel-dompdf**: Added `"barryvdh/laravel-dompdf": "^3.0"` to composer.json. Lightweight, no external binary dependencies.
- **Template matches official form**: Blade template `pdf.ttb-5120-17` renders all 5 parts in the official TTB Form 5120.17 layout with winery header, balance check, signature lines, and footer.
- **Storage path**: PDFs saved to `storage/app/ttb-reports/ttb-5120-17-YYYY-MM.pdf`. Path stored on the report's `pdf_path` column.
- **Letter paper**: US Letter size portrait, matching the physical TTB form.

### Files Created
- `app/Services/TTB/TTBReportPdfGenerator.php`
- `resources/views/pdf/ttb-5120-17.blade.php`

---

## Sub-Task 5: TTB Verification Test Suite
**Completed:** 2026-03-16
**Status:** Done

### Key Decisions
- **Fixture-driven testing**: Three JSON fixture files define complete scenarios with lots, lab analyses, events, and expected results. Tests seed data from fixtures and verify output matches.
- **Scenario coverage**: Small estate (3 lots, basic ops), mixed wine types (table + dessert), high volume (multiple lots, blends, bottlings, diverse losses).
- **Balance equation verification**: Every scenario verifies `closing = opening + produced + received - removed - losses` within 0.1 gallon tolerance.
- **Wine type separation**: Mixed-type scenario verifies table and dessert wines are reported on separate lines.

### Files Created
- `tests/Fixtures/ttb/scenario_small_estate.json`
- `tests/Fixtures/ttb/scenario_mixed_wine_types.json`
- `tests/Fixtures/ttb/scenario_high_volume.json`
- `tests/Feature/TTB/TTBVerificationTest.php`

---

## Sub-Task 6: Bond & Permit Tracking
**Completed:** 2026-03-16
**Status:** Done

### Key Decisions
- **License model**: Generic enough for all regulatory permits — TTB basic permit, state licenses, county permits, DTC shipper permits. `license_type` field uses a select with common types.
- **Expiration tracking**: `expires_at` date with configurable `renewal_lead_days` (default 90). Helpers: `isExpired()`, `needsRenewalReminder()`, `daysUntilExpiration()`.
- **Reminder job**: `LicenseExpirationReminderJob` runs daily per tenant. Logs warnings for licenses needing renewal. Designed for future email/notification integration.
- **Document storage**: `document_path` field for uploaded license/permit scans via Filament file upload.
- **Filament resource**: Full CRUD in "Compliance" nav group (sort 2). Color-coded expiration badges (red for expired, yellow for expiring soon, green for current).

### Files Created
- `database/migrations/tenant/2026_03_17_400003_create_licenses_table.php`
- `app/Models/License.php`
- `app/Jobs/LicenseExpirationReminderJob.php`
- `app/Filament/Resources/LicenseResource.php`
- `app/Filament/Resources/LicenseResource/Pages/ListLicenses.php`
- `app/Filament/Resources/LicenseResource/Pages/CreateLicense.php`
- `app/Filament/Resources/LicenseResource/Pages/ViewLicense.php`
- `app/Filament/Resources/LicenseResource/Pages/EditLicense.php`

---

## Sub-Task 7: DTC Shipping Compliance Rules
**Completed:** 2026-03-16
**Status:** Done

### Key Decisions
- **State-level rules**: `dtc_compliance_rules` table stores per-state: allows_dtc_shipping, annual_case_limit, annual_gallon_limit, license_required, license_type_required, last_verified_at.
- **Shipment tracking**: `customer_dtc_shipments` table records individual shipments with customer_id, state, cases, gallons, shipped_at. Used for annual limit checks.
- **Eligibility check**: `DTCComplianceService::checkEligibility()` validates state allows DTC, then checks annual case/gallon limits against recorded shipments. Returns structured `{allowed, reason, annual_cases, annual_gallons, case_limit, gallon_limit}`.
- **Annual summary**: `getCustomerAnnualSummary()` groups current-year shipments by state with totals — useful for customer-facing compliance dashboards.
- **Seeder with all 50 states + DC**: `DTCComplianceRulesSeeder` uses `updateOrCreate` for idempotency. Prohibited states: AL, AR, DE, KY, MS, UT. Limited states have realistic annual case limits (12-36). Integrated into `DemoWinerySeeder`.

### Files Created
- `database/migrations/tenant/2026_03_17_400004_create_dtc_compliance_rules_table.php`
- `database/migrations/tenant/2026_03_17_400005_create_customer_dtc_shipments_table.php`
- `app/Models/DTCComplianceRule.php`
- `app/Models/CustomerDTCShipment.php`
- `app/Services/DTCComplianceService.php`
- `database/seeders/DTCComplianceRulesSeeder.php`

### Test Summary
- `tests/Feature/TTB/DTCComplianceTest.php` — 6 tests: allow permitted state, block prohibited state, enforce annual case limit (over + under), unknown state code, shipment recording, annual summary computation.

---

## Sub-Task 8: Lot Traceability Report
**Completed:** 2026-03-16
**Status:** Done

### Key Decisions
- **One-step-back / one-step-forward**: Follows TTB traceability requirements. Backward trace: lot creation source, blend source lots. Forward trace: blend destination lots, bottling runs, sale events.
- **Service-based**: `LotTraceabilityService::trace()` returns `{lot, backward[], forward[], timeline[]}`. Each trace step includes type, description, event reference, and timestamp.
- **Timeline**: Merges backward + forward + all lot events into a single chronological view. Deduplicated by event ID.
- **Standalone Filament page**: `LotTraceability` page (not a resource) in Compliance nav group sort 4. Lot selector dropdown triggers `runTrace()`.
- **Visual layout**: Two-column backward/forward display with color coding (blue for source, green for destination), plus full timeline with vertical connector line.

### Files Created
- `app/Services/TTB/LotTraceabilityService.php`
- `app/Filament/Pages/LotTraceability.php`
- `resources/views/filament/pages/lot-traceability.blade.php`

---

## Sub-Task 9: Organic/Sustainable Certification Support
**Completed:** 2026-03-16
**Status:** Done

### Key Decisions
- **Advisory only**: Certification compliance is informational — it flags violations but doesn't block operations. Winemakers make the final call.
- **Certification types**: USDA Organic, Demeter Biodynamic, SIP Certified, CCOF Organic, Salmon-Safe. Stored as JSONB array on `winery_profiles.certification_types`.
- **Prohibited inputs lists**: Hardcoded per certification in `CertificationComplianceService::PROHIBITED_INPUTS`. Includes common winery additions like mega_purple, synthetic_yeast, velcorin, etc. USDA Organic has the most restrictive list.
- **Check on addition**: `checkAddition($productName)` checks against all active certifications. Returns `{compliant, violations[]}` where each violation includes certification name, input name, and reason.
- **Lot audit trail**: `getLotAuditTrail($lotId)` retrospectively checks all `addition_created` events for a lot against current certifications. Returns compliance status per addition.
- **Active certifications**: `getActiveCertifications()` returns labeled list of winery's certifications for UI display.

### Deviations from Spec
- No Filament UI for certification management in this phase — certifications are set on the WineryProfile model directly. A dedicated management UI can be added in a future phase.

### Files Created
- `database/migrations/tenant/2026_03_17_400006_add_certification_types_to_winery_profiles.php`
- `app/Services/CertificationComplianceService.php`

### Files Modified
- `app/Models/WineryProfile.php` — Added `certification_types` to fillable and casts (as array)

### Test Summary
- `tests/Feature/TTB/CertificationComplianceTest.php` — 6 tests: no-certs passes, USDA Organic flags prohibited input, approved input passes, multiple certifications flag independently, lot audit trail with mixed compliance, active certifications list.

---

## Post-Phase Legal Compliance Audit & Structural Rework
**Completed:** 2026-03-16
**Status:** Done

### Critical Corrections Applied

The initial implementation had several legal compliance errors that were identified and corrected across two follow-up sessions:

1. **CBMA alcohol threshold (HIGH RISK):** Original code used 14% as the table/dessert boundary. Per the Craft Beverage Modernization Act (effective 01/01/2018), the threshold is **16%**. All `WineTypeClassifier` constants corrected.

2. **Wine type columns (HIGH RISK):** Original code used 4 wine types (`table`, `dessert`, `sparkling`, `special_natural`). TTB Form 5120.17sm (01/2018) requires **6 columns**: (a) Not Over 16%, (b) Over 16-21%, (c) Over 21-24%, (d) Artificially Carbonated, (e) Sparkling, (f) Hard Cider. `WineTypeClassifier` rewritten with `COL_A` through `COL_F` constants.

3. **Whole gallon rounding (MEDIUM RISK):** Original code rounded to nearest tenth (`round($x, 1)`). Per TTB practice, "there is no requirement to extend beyond whole numbers." All rounding changed to `round($x, 0)`.

4. **Section A/B separation (HIGH RISK):** Original code had a single inventory balance. TTB Form 5120.17 requires **separate balance sheets** for bulk wine (Section A, 32 lines) and bottled wine (Section B, 21 lines). `PartOneCalculator` rewritten with `calculateSectionA()` and `calculateSectionB()`. Bottling events now create dual entries (Section A decrease + Section B increase).

5. **Report data structure:** Changed from `part_one`..`part_five` flat structure to `section_a`/`section_b` with independent summaries and line arrays.

6. **PDF export:** `TTBReportPdfGenerator` and `pdf/ttb-5120-17.blade.php` rewritten to use new section structure and render all 6 wine type columns.

7. **Review UI:** Separated `getSummary()` into `getSectionASummary()` / `getSectionBSummary()`. Rewrote review blade template with correct data keys.

8. **View page blank fix:** `ViewTTBReport` page had no infolist — now redirects to ReviewTTBReport.

### Expanded TTB Form Line Items

The initial implementation only covered 2 of 5 production methods and 3 of 12+ removal categories. All calculators were expanded to cover the full TTB Form 5120.17 line structure:

**PartTwoCalculator (Section A production, Lines 2-6):**
- Line 2: Fermentation (`lot_created`) — existed
- Line 3: Sweetening (`sweetening_completed`) — **added**
- Line 4: Wine spirits/fortification (`fortification_completed`) — **added**
- Line 5: Blending (`blend_finalized`) — existed
- Line 6: Amelioration (`amelioration_completed`) — **added**

**PartThreeCalculator (Section A receipts, Lines 7-10):**
- Line 7: Received from bonded premises (`stock_received`) — existed
- Line 8: Received from customs (`wine_received_customs`) — **added**
- Line 9: Returned to bond from bottled (`wine_returned_to_bulk`) — **added**
- Line 10: Other received (`wine_received_other`) — **added**

**PartFourCalculator (Section A removals, Lines 13-24):**
- Line 13: Bottled/packaged (`bottling_completed`) — existed
- Line 14: Taxpaid bulk removal (`taxpaid_bulk_removal`) — **added**
- Line 15: Transferred to bonded premises (`wine_transferred_bonded`) — **added**
- Line 17: Exported (`wine_exported`) — **added**
- Line 18: Distilling material (`used_as_distilling_material`) — **added**
- Line 19: Vinegar (`used_as_vinegar`) — **added**
- Line 23: Breakage/spillage (`breakage_reported`) — **added**
- Line 24: Other (`other_bulk_removal`) — **added**

**PartFourCalculator (Section B receipts, Lines 3-5):**
- Line 2: Bottled from bulk (`bottling_completed`) — existed
- Line 3: Received from bonded (`bottled_received_bonded`) — **added**
- Line 4: Received from customs (`bottled_received_customs`) — **added**
- Line 5: Returned to bond (`bottled_returned_to_bond`) — **added**

**PartFourCalculator (Section B removals, Lines 8-17):**
- Line 8: Sales/taxpaid (`stock_sold`) — existed
- Line 9: Transferred bonded (`bottled_transferred_bonded`) — **added**
- Line 11: Exported (`bottled_exported`) — **added**
- Line 12: Returned to bulk (`bottled_returned_to_bulk`) — **added**
- Line 13: Breakage (`bottled_breakage`) — **added**
- Line 17: Other (`bottled_other_removal`) — **added**

**PartFiveCalculator (Section A losses, Lines 29-30):**
- Line 29: Transfer variance, bottling waste, filtering — existed
- Line 29: Evaporation (`evaporation_measured`) — **added**
- Line 30: Racking lees — existed

**TTBReportGenerator:** Replaced all hardcoded `0.0` totals with computed values from line item categories.

**Line number fix:** All calculators now use fixed line numbers per TTB form row (multiple wine types share the same line number, differentiated by column).

### Files Modified
- `app/Services/TTB/WineTypeClassifier.php` — 6 column constants, CBMA thresholds, hard cider/artificially carbonated detection
- `app/Services/TTB/PartOneCalculator.php` — Section A/B separate balance sheets
- `app/Services/TTB/PartTwoCalculator.php` — 3 new production methods, fixed line numbers
- `app/Services/TTB/PartThreeCalculator.php` — 3 new receipt types
- `app/Services/TTB/PartFourCalculator.php` — 13 new removal/receipt categories, fixed line numbers
- `app/Services/TTB/PartFiveCalculator.php` — evaporation loss
- `app/Services/TTB/TTBReportGenerator.php` — section_a/section_b structure, computed totals
- `app/Services/TTB/TTBReportPdfGenerator.php` — section-based rendering
- `app/Jobs/GenerateMonthlyTTBReportJob.php` — dual opening inventory
- `app/Models/TTBReportLine.php` — updated docblock wine types
- `resources/views/pdf/ttb-5120-17.blade.php` — 6-column rendering
- `resources/views/filament/pages/ttb-report-review.blade.php` — separate section summaries
- `app/Filament/Resources/TTBReportResource/Pages/ReviewTTBReport.php` — section-specific summary methods
- `app/Filament/Resources/TTBReportResource/Pages/ViewTTBReport.php` — redirect to review
- All test files updated, 3 fixture files updated
- `database/seeders/ComplianceSeeder.php` — **created** (demo TTB reports + licenses)

### Files Created
- `resources/views/filament/pages/partials/wine-type-badge.blade.php` — shared wine type badge partial
- `tests/Feature/TTB/TTBExpandedLineItemsTest.php` — 12 tests for new line items

### Test Summary
- All existing 50 compliance tests updated and passing
- 12 new expanded line item tests passing
- ~841 total tests passing, PHPStan level 6 (zero errors), Pint (zero style issues)
