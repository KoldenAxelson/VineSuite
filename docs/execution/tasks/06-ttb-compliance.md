# TTB & Regulatory Compliance

## Phase
Phase 3

## Dependencies
- `01-foundation.md` — event log (TTB reports aggregate from events), auth, Filament
- `02-production-core.md` — all production operations that generate TTB-reportable events (lot creation, transfers, bottling, blending, additions, losses)
- `04-inventory.md` — case goods (removals from bond on sale)

## Goal
Auto-generate TTB Form 5120.17 (Report of Wine Premises Operations) from the event log data. This is the single most important compliance feature — it's why wineries pay for the software. The report aggregates production operations into the five required parts of the form. Getting this right means a winery saves 2-4 hours per month of manual reporting. Getting it wrong has legal consequences. Treat this as safety-critical code.

## Data Models

- **TTBReport** — `id` (UUID), `report_period_month` (integer 1-12), `report_period_year`, `status` (draft/reviewed/filed/amended), `generated_at`, `reviewed_by` (FK users), `reviewed_at`, `filed_at`, `pdf_path`, `data` (JSONB — full report payload for historical reference), `notes`, `created_at`, `updated_at`
  - Relationships: belongsTo ReviewedByUser

- **TTBReportLine** — `id`, `ttb_report_id`, `part` (I/II/III/IV/V), `line_number`, `category`, `wine_type` (table/dessert/sparkling/special_natural), `description`, `gallons` (decimal), `source_event_ids` (JSON array — traceability to individual events), `created_at`

- **License** — `id` (UUID), `license_type` (ttb_permit/state_license/cola), `jurisdiction` (federal/state name), `license_number`, `issued_date`, `expiration_date`, `renewal_lead_days` (integer, for reminders), `document_path`, `notes`, `created_at`, `updated_at`

- **DTCComplianceRule** — `id`, `state_code`, `state_name`, `allows_dtc_shipping` (boolean), `annual_case_limit` (nullable), `annual_gallon_limit` (nullable), `license_required` (boolean), `license_type_required`, `notes`, `last_verified_at`, `created_at`, `updated_at`

- **CustomerDTCShipment** — `id`, `customer_id`, `state_code`, `order_id`, `cases_shipped`, `gallons_shipped`, `shipped_at`, `created_at`

- **LotTraceability** — Derived view (not a table) — queries the event log to build full chain from grape intake → lot → blend → bottle → order per lot.

## Sub-Tasks

### 1. TTB report data aggregation engine
**Description:** Build the service that queries the event log for a given month and aggregates operations into the five parts of TTB Form 5120.17. This is the core logic — everything else in this module depends on it.
**Files to create:**
- `api/app/Services/TTB/TTBReportGenerator.php` — main generator, orchestrates all parts
- `api/app/Services/TTB/PartOneCalculator.php` — Summary of operations
- `api/app/Services/TTB/PartTwoCalculator.php` — Wine produced (crush, fermentation completed)
- `api/app/Services/TTB/PartThreeCalculator.php` — Wine received in bond
- `api/app/Services/TTB/PartFourCalculator.php` — Wine removed from bond (bottling, sales, transfers out)
- `api/app/Services/TTB/PartFiveCalculator.php` — Losses (evaporation, breakage, lees, variance)
- `api/app/Services/TTB/WineTypeClassifier.php` — classifies wines into TTB categories (table, dessert, sparkling, special natural)
**Acceptance criteria:**
- Given a month/year, queries ALL relevant events and produces accurate gallonage totals per line item
- Wine classified into TTB categories (table wine <14% alc, dessert wine 14-24%, etc.)
- Part I summary balances: opening inventory + produced + received = closing inventory + removed + losses
- Source event IDs are linked to every line item (for audit trail / drill-down)
- Handles edge cases: lots that span multiple months, partial operations
**Gotchas:** TTB reports are in WINE GALLONS (not proof gallons for wine — that's spirits). Volumes must be reported to the nearest tenth of a gallon. Opening inventory for month N = closing inventory for month N-1. The very first report needs a manually entered opening inventory.

### 2. TTB report model and generation flow
**Description:** Create the report model, the generation job (scheduled monthly), and the review workflow.
**Files to create:**
- `api/app/Models/TTBReport.php`
- `api/app/Models/TTBReportLine.php`
- `api/database/migrations/xxxx_create_ttb_reports_table.php`
- `api/database/migrations/xxxx_create_ttb_report_lines_table.php`
- `api/app/Jobs/GenerateMonthlyTTBReportJob.php`
**Acceptance criteria:**
- Report auto-generated on the 1st of each month for the previous month (scheduled job)
- Report starts as `draft` status
- Winemaker can regenerate a draft (recalculates from current event data)
- Once reviewed, status changes to `reviewed`
- Full report data stored as JSONB snapshot for historical reference
**Gotchas:** Report generation must be idempotent — regenerating for the same month replaces the existing draft (not creates a duplicate). Only draft reports can be regenerated.

### 3. TTB report review UI
**Description:** Build a Filament page where the winemaker reviews the auto-generated report, drills into line items to verify, and approves for filing.
**Files to create:**
- `api/app/Filament/Pages/TTBReportReview.php` (custom Livewire page)
- `api/app/Filament/Resources/TTBReportResource.php`
**Acceptance criteria:**
- Report displayed in a format matching the actual Form 5120.17 layout
- Each line item is clickable — shows the individual events that contributed to that number
- Winemaker can add notes to any line item (for their own records)
- "Approve" button changes status to `reviewed`
- Historical reports browsable by month/year
**Gotchas:** The drill-down into source events is the killer feature — it's how the winemaker verifies the numbers are correct. Make this easy and fast.

### 4. TTB report PDF export
**Description:** Generate a PDF version of the TTB report formatted for filing. Use DomPDF.
**Files to create:**
- `api/app/Services/TTB/TTBReportPdfGenerator.php`
- `api/resources/views/pdf/ttb-5120-17.blade.php` — PDF template matching official form layout
**Acceptance criteria:**
- PDF matches the visual layout of the actual TTB Form 5120.17
- All five parts rendered with correct line numbers and categories
- Winery name, permit number, reporting period printed on the form
- PDF stored in R2 and linked to the report record
- Downloadable from the management portal
**Gotchas:** The form layout is specific — use the actual TTB form as a visual reference. PDF generation via DomPDF (not Browsershot for this — DomPDF handles structured forms well).

### 5. TTB verification test suite
**Description:** The single most important test suite in the system. Compare auto-generated reports against known-good reports from real winery data.
**Files to create:**
- `api/tests/Feature/TTB/TTBVerificationTest.php`
- `api/tests/Fixtures/ttb/` — anonymized real winery data fixtures
- `api/tests/Fixtures/ttb/expected_reports/` — known-good report outputs
**Acceptance criteria:**
- At least 3 test scenarios with different winery operation patterns
- Fixture data includes: event log entries for a full month of operations
- Expected output matches actual TTB form numbers for each fixture
- Tests verify each line item in all five parts
- Tests verify Part I balance equation (opening + produced + received = closing + removed + losses)
**Gotchas:** Get real data from a friendly winery (anonymized). If no real data available yet, construct synthetic fixtures that cover all operation types. This test suite must run in CI on every commit.

### 6. Bond and permit tracking
**Description:** Store TTB permit numbers, state licenses, bond information, and COLA records with expiration tracking and renewal reminders.
**Files to create:**
- `api/app/Models/License.php`
- `api/database/migrations/xxxx_create_licenses_table.php`
- `api/app/Filament/Resources/LicenseResource.php`
- `api/app/Jobs/LicenseExpirationReminderJob.php`
**Acceptance criteria:**
- Store federal TTB permit, state licenses (multiple states), COLAs per SKU
- Document upload (store PDF of permit/license)
- Expiration date tracking with configurable reminder lead time
- Scheduled job sends reminders when licenses approach expiration
- License/permit expiration calendar view
**Gotchas:** State licenses are per-state for DTC shipping. A winery shipping to 30 states has 30+ state licenses to track.

### 7. DTC shipping compliance rules
**Description:** Maintain a database of state-by-state DTC shipping rules. Used to auto-block orders to non-compliant states and track per-customer annual shipments.
**Files to create:**
- `api/app/Models/DTCComplianceRule.php`
- `api/app/Models/CustomerDTCShipment.php`
- `api/database/migrations/xxxx_create_dtc_compliance_rules_table.php`
- `api/database/migrations/xxxx_create_customer_dtc_shipments_table.php`
- `api/app/Services/DTCComplianceService.php`
- `api/database/seeders/DTCComplianceRulesSeeder.php`
**Acceptance criteria:**
- All 50 states + DC seeded with current DTC shipping rules
- Service can check: can this customer receive a shipment to this state?
- Tracks annual shipment volume per customer per state
- Alerts when approaching state limit for a customer
- Rules database updatable (regulations change periodically)
**Gotchas:** DTC shipping rules change frequently. Include a `last_verified_at` field and build in a workflow for periodic review. Some states have quantity limits (e.g., 2 cases/month). Some prohibit DTC entirely.

### 8. Lot traceability report
**Description:** Build a one-step-back / one-step-forward lot trace — the complete chain from grape source to final sale. Required for FDA traceability and recall scenarios.
**Files to create:**
- `api/app/Services/TTB/LotTraceabilityService.php`
- `api/app/Filament/Pages/LotTraceability.php`
**Acceptance criteria:**
- Given a lot, trace backward to: grape source (vineyard, grower, block)
- Given a lot, trace forward to: blends it was used in, bottling runs, SKUs, orders
- Trace rendered as a visual chain/graph
- Exportable as PDF for audit/recall documentation
**Gotchas:** This queries the event log chain (lot_created → blend_finalized → bottling_completed → order_placed). Recursive for blends of blends. Cache the trace if performance is an issue.

### 9. Organic/sustainable certification support
**Description:** Flag non-approved inputs when a winery has organic or sustainable certification tracked.
**Files to create:**
- `api/app/Services/CertificationComplianceService.php`
- Addition to WineryProfile: `certification_types` (JSON array)
**Acceptance criteria:**
- Winery can set certification types: USDA Organic, Demeter Biodynamic, SIP Certified, CCOF, etc.
- When an addition is logged, check product against approved inputs list
- Flag non-approved inputs with a warning (not a block — winemaker may choose to lose certification for a lot)
- Certification audit trail report: all additions by lot, flagged items highlighted
**Gotchas:** This is advisory, not enforcement. A winery losing organic certification on one lot doesn't lose it on all lots. The flag is per-lot, per-addition.

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/ttb/reports` | List TTB reports | winemaker+ |
| POST | `/api/v1/ttb/reports/generate` | Generate/regenerate for month | winemaker+ |
| GET | `/api/v1/ttb/reports/{report}` | Get report with line items | winemaker+ |
| PUT | `/api/v1/ttb/reports/{report}/review` | Mark as reviewed | winemaker+ |
| GET | `/api/v1/ttb/reports/{report}/pdf` | Download PDF | winemaker+ |
| GET | `/api/v1/ttb/reports/{report}/lines/{line}/events` | Drill into source events | winemaker+ |
| GET | `/api/v1/licenses` | List licenses/permits | admin+ |
| POST | `/api/v1/licenses` | Add license/permit | admin+ |
| GET | `/api/v1/compliance/dtc/check` | Check DTC shipping eligibility | Authenticated |
| GET | `/api/v1/lots/{lot}/traceability` | Full lot trace | winemaker+ |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `ttb_report_generated` | report_id, period_month, period_year, status | ttb_reports table |
| `license_renewed` | license_id, new_expiration | licenses table |
| `shipment_compliance_checked` | order_id, customer_id, state, result | customer_dtc_shipments |

## Testing Notes
- **Unit tests:** Each Part calculator independently (given events, verify gallonage). Wine type classification logic. DTC compliance check for each state category (allowed, prohibited, limited).
- **Integration tests:** Full month of operations → report generation → verify Part I balance. Report regeneration idempotency.
- **CRITICAL:** The TTB verification test suite (sub-task 5) is the most important test in the entire system. It must run in CI. If it fails, the build fails. No exceptions.
