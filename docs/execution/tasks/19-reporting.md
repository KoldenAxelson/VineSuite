# Reporting & Analytics

## Phase
Spans all phases: basic reports in Phase 2 (production, inventory), full reporting dashboard in Phase 7, AI-powered reports in Phase 8 (see `20-ai-features.md`)

## Dependencies
- All modules — reports aggregate data from every part of the system
- `01-foundation.md` — event log (many reports are event log aggregations)
- `02-inventory.md` — stock levels, reorder points
- `03-production.md` — lots, yields, material usage, work orders
- `05-cost-accounting.md` — COGS and margin calculations
- `06-ttb-compliance.md` — harvest data, TTB reports
- `07-ecommerce.md` — order data, channels
- `08-reservations.md` — tasting data
- `09-wholesale.md` — wholesale orders, customer accounts
- `10-wine-club.md` — club members, processing runs, charges
- `17-vineyard.md` — harvest, blocks, chemicals

## Goal
Comprehensive reporting across all winery operations. Reports are read-only query views that aggregate existing data — no separate data warehouse. Key insight: the event log makes most reports simple time-range aggregations rather than complex joins. Reports must be filterable, exportable (CSV + PDF), and schedulable for email delivery. The reporting module is the primary way winery owners understand their business. Reports drive business decisions and must be fast, accurate, and trustworthy.

## Data Models

- **SavedReport** — `id` (UUID), `winery_id` (UUID), `name` (varchar 255), `description` (text, nullable), `report_type` (enum: sales_by_channel/sales_by_sku/sales_by_time_period/production_timeline/inventory_snapshot/club_metrics/financial_summary/compliance_dtc/custom), `filters` (JSON — saved filter state, e.g., `{"date_range": "last_month", "channel": "tasting_room", "vintage": "2021"}`), `chart_config` (JSON nullable — saved chart preferences, e.g., `{"chart_type": "bar", "group_by": "week"}`), `created_by` (UUID, user who saved), `is_shared` (boolean — visible to all team members), `is_favorite` (boolean, per user — needs SavedReportFavorite pivot table), `created_at`, `updated_at`
  - Relationships: belongsTo Winery, belongsTo User (created_by), belongsToMany User (favorites via pivot)
  - Indexes: `winery_id, created_by`, `winery_id, is_favorite`

- **ScheduledReport** — `id` (UUID), `winery_id` (UUID), `saved_report_id` (UUID, nullable — if null, ad-hoc report definition), `name` (varchar 255 — display name for this schedule), `report_type` (enum same as SavedReport), `filters` (JSON — filter state for this schedule), `frequency` (enum: daily/weekly/monthly/quarterly), `day_of_week` (nullable int 0-6, for weekly), `day_of_month` (nullable int 1-31, for monthly), `time_of_day` (time HH:MM in winery timezone), `recipients` (JSON array of email addresses), `format` (enum: csv/pdf/both), `include_charts` (boolean, only for PDF), `is_active` (boolean), `last_sent_at` (nullable timestamp), `last_sent_status` (nullable enum: success/failed/partial), `failure_reason` (nullable text), `created_at`, `updated_at`
  - Relationships: belongsTo Winery, belongsTo SavedReport (nullable)
  - Indexes: `winery_id, is_active, frequency` (for scheduler query)

- **ReportExport** — `id` (UUID), `winery_id` (UUID), `saved_report_id` (nullable UUID), `report_type` (enum), `filters` (JSON — used for generation), `format` (enum: csv/pdf), `file_path` (varchar 255 — S3 path or local disk path), `file_size_bytes` (integer), `generated_by` (UUID, user), `download_count` (integer, default 0), `generated_at` (timestamp), `expires_at` (timestamp — 7 days from generation), `deleted_at` (nullable, soft delete), `created_at`
  - Relationships: belongsTo Winery, belongsTo SavedReport (nullable), belongsTo User (generated_by)
  - Indexes: `winery_id, expires_at` (for cleanup job)

- **ReportScheduleLog** — `id` (UUID), `scheduled_report_id` (UUID), `scheduled_for_date` (date), `sent_at` (nullable timestamp), `status` (enum: pending/sent/failed), `recipient_count` (integer), `failed_recipient_count` (integer, nullable), `error_message` (text, nullable), `file_path` (varchar 255, nullable), `created_at`
  - Relationships: belongsTo ScheduledReport
  - Indexes: `scheduled_report_id, sent_at`

## Sub-Tasks

### 1. Report infrastructure and base query builder
**Description:** Build the foundational reporting service — parameterized query builders, filter system, export pipeline, and the report page shell in Filament.

**Files to create:**
- `api/app/Services/Reporting/ReportBuilder.php` — abstract base class with common methods (filters, pagination, export)
- `api/app/Services/Reporting/ReportFilterService.php` — applies date range, channel, SKU, location, variety, vintage filters
- `api/app/Services/Reporting/ReportExportService.php` — CSV and PDF generation, S3 upload
- `api/app/Services/Reporting/ReportQueryBuilder.php` — constructs eloquent queries for different report types
- `api/app/Models/SavedReport.php`
- `api/app/Models/ScheduledReport.php`
- `api/app/Models/ReportExport.php`
- `api/app/Models/ReportScheduleLog.php`
- `api/database/migrations/2024_xx_xx_create_saved_reports_table.php`
- `api/database/migrations/2024_xx_xx_create_scheduled_reports_table.php`
- `api/database/migrations/2024_xx_xx_create_report_exports_table.php`
- `api/database/migrations/2024_xx_xx_create_report_schedule_logs_table.php`
- `api/app/Filament/Pages/Reports/ReportsIndex.php` — report selection dashboard with favorites
- `api/app/Filament/Pages/Reports/ReportViewer.php` — generic report display page (chart + table + filters)
- `api/app/Jobs/GenerateAndExportReportJob.php` — queued job for report generation
- `api/app/Jobs/SendScheduledReportJob.php` — queued job for email delivery
- `api/app/Console/Commands/DispatchScheduledReports.php` — called by Laravel scheduler (runs every hour)

**Acceptance criteria:**
- Common filter system available on all reports:
  - Date range (quick options: today, this week, this month, last month, last 3 months, this year, custom date picker)
  - Vintage (multi-select)
  - Variety (multi-select)
  - Channel (multi-select: tasting room, club, eCommerce, wholesale, events)
  - Location (multi-select: blocks or cellar locations depending on context)
  - Customer segment (multi-select: VIP, club, wholesale, etc.)
  - SKU (multi-select — depends on report type)
- CSV export for every report: consistent headers, one-line-per-row format, UTF-8 encoding, proper quoting for commas in data
- PDF export for key reports (using DomPDF): professional layout with winery logo, report title, filters applied, data table with subtotals, footer with generated date/time
- Save report with filters: modal form → name + optional description + confirm → stores SavedReport with is_shared toggle
- Mark report as favorite (star icon → toggles boolean per user)
- Schedule report for recurring email delivery:
  - Modal: select frequency (daily, weekly, monthly), select time, add recipients (comma-separated or email chips), select format (CSV, PDF, or both), toggle include charts (for PDF)
  - Confirm → creates ScheduledReport, next send calculated
- Export download link: signed URL (Laravel's signed routes), expires in 24 hours, tracks download count
- Report list/index page: categories (Sales, Production, Inventory, Club, Financial, Compliance), each with thumbnail cards showing report name, last run date, chart preview
- Bulk actions: email multiple reports, export multiple reports (zip them)

**Gotchas:**
- Reports query against materialized state tables, NOT the event log directly (except compliance reports that need the event trail for traceability). Example: sales_by_channel report queries Order table grouped by channel, not OrderPlaced events. This is much faster.
- Use database views or Eloquent query scopes for complex aggregations — keep ReportBuilder classes thin. Example: create a database view `v_sales_by_channel` that aggregates orders, then query it via Eloquent scope.
- All money amounts formatted consistently: use Laravel's `Money` package or custom accessor that formats to 2 decimal places with $ prefix.
- Date ranges default to current month if not specified (not "all time" — too broad).
- Dates stored as UTC in DB, but display in winery timezone (via accessor or formatting service).
- Large exports (full year, all SKUs, all customers) should run as queued jobs — don't block the request. Return a "generating..." status, email the link when ready.
- CSV export headers should use human-readable names ("Customer Name") not database column names ("customer_name").
- Performance critical: "Sales by channel" for a full year of data with 50 SKUs must load in < 2 seconds. Consider caching, materialized views, or partial index on orders.

### 2. Sales reports
**Description:** Sales performance across all channels — the most-viewed report category. These reports answer: "How much revenue did we make? From which channels? Which products? Which customers?"

**Files to create:**
- `api/app/Services/Reporting/SalesReportBuilder.php`
- `api/app/Filament/Pages/Reports/SalesReports.php`
- `api/database/views/v_sales_by_channel.sql` (optional, if using raw SQL view)

**Acceptance criteria:**
- **Sales by channel:** table with rows for tasting room, club, eCommerce, wholesale, events. Columns: revenue (sum), units sold (count), % of total revenue, average order value. Filterable by date range. Chartable (pie or bar). Export CSV.
- **Sales by SKU:** table with one row per SKU. Columns: SKU name/code, revenue (sum), units sold, avg price per unit, % of total revenue, margin (if COGS available). Sortable by any column (default: revenue desc). Filterable by date range, channel, SKU type (wine, merchandise, etc.). Export CSV.
- **Sales by time period:** configurable X-axis (daily, weekly, monthly, quarterly, yearly). Y-axis: revenue or units. Trend line optional. Filterable by channel, SKU. Line chart or bar chart. Export CSV of underlying data.
- **Top products trend:** top 10 SKUs by revenue over time (stacked area chart). Show revenue contribution week-over-week.
- **Top customers by revenue:** table with customer name, total spent, order count, average order value, LTV if available. Ranked. Click customer → customer detail page. Filterable by segment.
- **Discount and promo usage:** table with promo code, description, times used, total discount given ($), affected revenue (revenue before discount), effective discount rate. Sortable by discount $. Helpful for evaluating promotion ROI.
- **Refund and return report:** count, total $ refunded, breakdown by reason (customer return, damaged, mistake, etc.), by time period. Trend chart.
- **Channel comparison:** side-by-side comparison (bar chart) of revenue/units/AOV across channels, side-by-side for multiple time periods (e.g., this month vs. last month).
- All filterable by date range, channel, SKU, customer segment
- All chartable (user can toggle chart type: bar, line, pie as appropriate)

**Gotchas:**
- "Sales by channel" is the report winery owners check most often on login — it must be fast (< 2 second load for a year of data). Profile the query, add database indexes on orders.channel and orders.created_at. Consider caching this specific report (invalidate cache on every order creation).
- Club revenue should include the per-member charge amount (from ClubCharge table), not just the aggregate processing run total. Total per month = count(active club members that month) × charge amount for that month.
- Refund counts should include full refunds and partial refunds. Track refund reason in the refund record.
- Discount calculation: if promo code gives 10% off, track the "original price" and "discounted price" in order line items so discount $ is reliable.
- AOV (average order value) should include only completed orders, not cancelled or pending ones.

### 3. Production reports
**Description:** Winemaking operation reports — yields, timelines, material usage, work order tracking.

**Files to create:**
- `api/app/Services/Reporting/ProductionReportBuilder.php`
- `api/app/Filament/Pages/Reports/ProductionReports.php`

**Acceptance criteria:**
- **Harvest yield by block/variety:** table with block name, variety, tons received (from harvest record), juice yield % (juice liters / (tons × 1000 kg/ton) × density), vs. projected yield from block data. Trend over multiple vintages. Filterable by vintage, block, variety.
- **Lot volume tracking (waterfall chart):** starting volume (from harvest intake) → additions (other lots blended in) → losses (evaporation, sampling, transfers out) → current volume. Visual waterfall chart per lot showing each adjustment. Downloadable as CSV.
- **Production timeline per lot:** event log visualization for single lot: created → received → crushing → fermentation start → fermentation end → MLF start → MLF end → aging start → aging end (projected). Gantt chart or timeline view. Shows actual dates vs. scheduled dates.
- **Material usage summary:** table of additives consumed per time period: acid, tannin, SO2, yeast, etc. Group by material type. Columns: material, quantity used, unit (kg, L, etc.), cost if tracked, supplier. Filterable by vintage, lot, date range.
- **Bottling yield report:** gallons/liters in (from bulk wine lot) → bottles out (count), loss % (loss liters / input liters), breakage count (if tracked). Helps evaluate bottling efficiency.
- **Work order completion rate:** table grouped by staff member and by operation type. Columns: work orders created, completed on-time, completed late, overdue, completion rate %. Helpful for staff performance.
- **Lab analysis summary:** latest lab results per lot: date, pH, TA, alcohol, VA (volatile acidity), SO2, notes. Rows = lots. Red flag rows if any value outside threshold (e.g., VA > 0.9 in red). Sortable by any column.
- **Production calendar:** month/year view with events (harvest expected, crush dates, fermentation status, bottling dates). Visual calendar grouped by block or lot.

**Gotchas:**
- Projected yield is challenging — it requires block-level yield estimates from vineyard module. If not available, show actual vs. previous vintage average.
- Waterfall chart for lot volume is complex to calculate — track every volume adjustment event in the event log or in a lot_transactions table. Sum by transaction type (addition, loss, etc.).
- Bottling yield: track both expected loss (normal evaporation, sampling) vs. breakage loss (broken bottles). These are different and have different impacts.
- Work order completion: "overdue" means due_date has passed, "completed late" means completed_at > due_date. "Completed on-time" = completed_at <= due_date. Clarity important.

### 4. Inventory reports
**Description:** Stock position, movement, and reorder reports. Answers: "What's in stock? What's selling? What do we need to order?"

**Files to create:**
- `api/app/Services/Reporting/InventoryReportBuilder.php`
- `api/app/Filament/Pages/Reports/InventoryReports.php`

**Acceptance criteria:**
- **Current stock on hand:** table with SKU, location, available qty (on-hand minus committed), committed qty (reserved for orders), on-hand qty (physical count), total value (on-hand qty × average cost). Sortable by location, SKU, qty. Status indicator (in-stock, low, out-of-stock).
- **Inventory movement history:** table filtered by SKU. Rows: transaction date, transaction type (receipt, sale, adjustment, transfer, waste), qty, notes, user. Timeline view or list. Useful for tracing stock movement.
- **Slow-moving inventory:** SKUs with zero sales in last X days (configurable threshold, default 90 days). Columns: SKU, last sale date, qty on-hand, value, months-to-sell at current rate. Identifies obsolete or stagnant stock.
- **Dry goods reorder report:** dry goods (supplies, equipment, labels, bottles, corks, etc.) with qty below reorder_point. Columns: item, current qty, reorder point, suggested order qty (reorder qty from master data), supplier, lead time, recommended action. Filterable, exportable, printable (useful for procurement).
- **Variance report:** last physical count vs. book (system) quantity per SKU/location. Columns: SKU, location, book qty, physical count qty, variance (count - book), variance %, value of variance ($). High variances flagged red. Helpful for inventory audits.
- **Bulk wine aging report:** wine in bulk storage (barrel, tank, carboy) with volume, variety, vintage, age (time in vessel), projected bottling date, current location. Table with sortable columns. Useful for planning bottling schedule.
- **Stock valuation report:** total inventory value broken down by category (wine by vintage, merchandise, dry goods), by location. Useful for balance sheet and insurance.
- **Receiving log:** incoming shipments (date, supplier, SKUs, qtys, cost), unmatched receipts (received but not yet matched to PO), aging (how long since receipt).

**Gotchas:**
- **Committed vs. available:** committed = sum of order line items not yet fulfilled. available = on_hand - committed. Some systems show negative available if oversold (backorder scenario).
- **Slow-moving calculation:** needs sales history. If a SKU has never been sold, it's infinitely slow-moving. Clarify: only include SKUs with at least one sale.
- **Variance:** physical count is typically done via barcode scan or manual count. Compare to system quantity. Large variances trigger audit.
- **Bulk wine volume:** track in liters or gallons consistently. Aging calculation: current_date - racked_date or created_date, depending on vintage tracking practice.

### 5. Club and subscription reports
**Description:** Wine club health metrics and member analytics. Answers: "How healthy is our club? Are we losing members? Who's most valuable?"

**Files to create:**
- `api/app/Services/Reporting/ClubReportBuilder.php`
- `api/app/Filament/Pages/Reports/ClubReports.php`

**Acceptance criteria:**
- **Active members by tier:** table grouped by club tier (club name, size, frequency). Columns: active member count, trend (vs. last month, last quarter), acquired this period, churned this period, net change. Pie or bar chart of member distribution by tier.
- **Churn rate:** monthly and quarterly. Columns: period, active member count start, churned (cancelled), churn rate %. Trend chart over time. Filterable by tier. Identify if seasonal churn (e.g., high in summer).
- **Revenue per run:** processing run date, total charged, per-member charge, member count for that run, successful charges, failed charges. Useful for understanding club revenue per shipment.
- **Failed payment rate and recovery rate:** month, total charges attempted, first-attempt success rate, retry success rate, recovery (payment eventually successful after retry). Track improvement as payment system gets retries/remediation.
- **Average member LTV:** by tier, by join source (POS signup, online, manual). Useful for understanding which acquisition sources are most valuable.
- **Acquisition by source:** breakdown of new club members by source (POS, website, email campaign, staff referral). Count and revenue impact (estimated LTV by source).
- **Retention cohort analysis [PRO]:** cohort table with rows = join month (e.g., Jan 2024, Feb 2024), columns = months after join (0, 1, 2, 3... 12). Cell values = % of cohort still active. Shows membership retention pattern. Useful for understanding if recent members are stickier than old members.
- **Customization participation:** processing run date, % of members who customized (chose different bottles), top customization choices (which bottles chosen, frequency).
- **Tier upgrade/downgrade:** transitions from one tier to another (e.g., Silver → Gold), count, revenue impact.

**Gotchas:**
- **Churn definition:** member status = "cancelled" with cancellation date in period. Exclude members who paused (temporary pause is not churn).
- **Active member count:** depends on "as of when?" — use month-end for consistency, or "at start of period". Clarify in report.
- **LTV calculation:** sum of all charges to member + estimated future charges (if ongoing). Or simpler: sum of charges to date. Clarify definition.
- **Cohort analysis:** requires join date and activity history. Only possible if you track member creation date and status history precisely.
- **Revenue per run:** if members joined/churned mid-run, calculation is tricky. Define: count active members as of run_date.

### 6. Financial reports
**Description:** Revenue, COGS, margin, and cash flow reports. These are critical for business health and often reconciled against accounting software.

**Files to create:**
- `api/app/Services/Reporting/FinancialReportBuilder.php`
- `api/app/Filament/Pages/Reports/FinancialReports.php`

**Acceptance criteria:**
- **Revenue summary:** P&L style — gross revenue, less discounts, less refunds = net revenue, grouped by channel. Alternate: grouped by product category. Columns: period, channel, gross revenue, discounts, refunds, net revenue, margin %. Trend chart.
- **COGS by lot, vintage, variety:** from cost accounting module — cost per bottle, bottles produced, total COGS. Rolled up by vintage or variety. Cost trend over time.
- **Gross margin by SKU and channel:** (net revenue - COGS) / net revenue. Table: SKU, channel, revenue, COGS, margin $, margin %. Identify which SKUs/channels are most profitable.
- **Accounts receivable aging:** wholesale customers only. Rows: customer, invoice date, amount, days outstanding, status (current, 30, 60, 90+ days overdue). Total AR balance. Useful for DSO (days sales outstanding) analysis.
- **Gift card liability:** total gift cards issued, total gift cards redeemed, unredeemed balance. Obligation for financial statements (liability). Trend (increasing/decreasing float).
- **Cash flow summary:** (optional integration with Stripe or bank accounts) — inflow (sales, payments received), outflow (refunds, expenses if tracked), net cash flow. Monthly trend. Useful for cash planning.
- **Tax report preview:** if applicable, summary of taxable sales by state (for DTC), by category (for COGS deduction). Coordinated with accounting module.

**Gotchas:**
- **COGS accuracy depends on cost accounting module.** If costs not tracked, margin reports are incomplete. Define fallback (e.g., assume standard cost per bottle).
- **Discount tracking:** order line items should store both gross_price and net_price (post-discount). Discount$ = (gross - net) × qty.
- **Refund impact:** refunds reduce net revenue. Track as negative revenue, not a separate line.
- **AR aging:** only applies to wholesale (with payment terms). Direct-to-consumer is mostly prepaid.
- **Gift card accounting:** liability decreases when card is redeemed (revenue recognized). Useful for recognizing deferred revenue.
- **Margin by channel:** club and eCommerce are typically higher margin (no tasting room overhead) — report should surface this.

### 7. Compliance reports
**Description:** Regulatory reporting that aggregates from the event log and compliance tracking.

**Files to create:**
- `api/app/Services/Reporting/ComplianceReportBuilder.php`
- `api/app/Filament/Pages/Reports/ComplianceReports.php`

**Acceptance criteria:**
- **TTB 5120.17:** reference report already built in `06-ttb-compliance.md`. Report page should link/embed it. Display: report period, quantities by product type, tax liability.
- **DTC shipment volume by state:** table: state, gallons shipped, cases shipped, count of orders. Trend over time. Highlight states approaching state limits (if configured). Useful for monitoring DTC compliance and forecasting.
- **Lot traceability report:** for recall scenarios. Input: lot ID or bottle code. Output: full chain from grape intake (harvest block) → lot creation → blending (if applicable) → bottling → distributor/customer order. Each step with date, qty, test results (lab analysis). Exportable as detailed PDF for regulatory submission.
- **Chemical application log:** spray records grouped by block and date. Columns: date, block, chemical name, rate (qty/acre), timing (growth stage), applicator. Useful for organic/sustainable certifications and audits. Filterable by vintage, block, chemical type.
- **License and permit expiration calendar:** visual calendar showing upcoming expirations (federal basic permit, state license, any other permits). Color-coded by days remaining (red <14 days, yellow <30 days). Table view with renewal URLs and responsible party.
- All compliance reports exportable as PDF with winery header and logo.

**Gotchas:**
- **DTC state limits:** these limits vary by state and by product type. Configure in winery settings. Report should check current shipment volume against limit and flag if approaching.
- **Lot traceability:** this report depends on perfect lineage tracking across harvest, lot, blend, and bottling. Ensure every blend and bottling line item tracks source lot. This is critical for recalls.
- **Chemical application:** must match records in vineyard module (see `17-vineyard.md`). Include organic certification status.

### 8. Scheduled report delivery
**Description:** Automated email delivery of reports on a recurring schedule. Most wineries want weekly sales summary delivered to inbox Monday morning.

**Files to create:**
- `api/app/Jobs/SendScheduledReportJob.php`
- `api/app/Console/Commands/DispatchScheduledReports.php` — Artisan command, called by Laravel scheduler (runs every hour)
- `api/resources/views/emails/scheduled-report-delivery.blade.php` — email template

**Acceptance criteria:**
- Schedule any saved report: select frequency (daily, weekly at day, monthly at date), delivery time (in winery timezone, default 7am)
- Multiple recipients per scheduled report (comma-separated, or email multi-select UI)
- Format: CSV attachment, PDF attachment, or both
- Include charts toggle (only for PDF)
- Delivery log visible in Filament: list of scheduled deliveries with sent/failed status, retry history
- Scheduled report: can be created from "Save Report" dialog, or from ScheduledReport resource in Filament
- Bulk actions: send report now (immediate delivery), enable/disable, edit recipients/schedule
- Delivery tracking: track when sent, to whom, success/failure, file size
- Error handling: if email send fails, log error and retry up to 3 times with exponential backoff

**Gotchas:**
- Use winery timezone for scheduling, not UTC. If winery is in PST and chooses "7am Monday", that's 7am PST = 3pm UTC (or 2pm during PDT). Store as "winery timezone offset" not fixed UTC time.
- "Monday morning weekly sales summary to owner" is the most common use case — make this a one-click setup ("Schedule: Sales by Channel, weekly Monday 7am, to owner email").
- Large reports (full year, all SKUs, all customers) may take time to generate (up to 30 seconds for PDF with charts). Don't block the scheduler — generate as queued job, email the link when ready.
- Test scheduled reports: provide "send test email" button that dispatches immediately to verify formatting.
- Cleanup expired exports: ReportExport records older than 7 days should be deleted (and S3 files cleaned up) — run cleanup job daily.

## API Endpoints

| Method | Path | Description | Auth Scope | Returns |
|--------|------|-------------|------------|---------|
| GET | `/api/v1/reports` | List available report types | Authenticated | `{reports: [{type, name, icon, description}]}` |
| GET | `/api/v1/reports/{type}` | Get report data with filters | Authenticated | `{data: [...], summary: {...}, metadata: {...}}` (varies by report type) |
| GET | `/api/v1/reports/{type}/schema` | Get filter/column schema for report | Authenticated | `{filters: [...], columns: [...], chart_types: [...]}` |
| POST | `/api/v1/reports/saved` | Save report with filters | Authenticated | `{id, name, filters, created_at}` |
| GET | `/api/v1/reports/saved` | List saved reports (favorites first) | Authenticated | `{data: [{id, name, report_type, created_at, is_favorite, created_by_name}]}` |
| DELETE | `/api/v1/reports/saved/{id}` | Delete saved report | Authenticated | `{success: bool}` |
| POST | `/api/v1/reports/saved/{id}/favorite` | Toggle favorite status | Authenticated | `{is_favorite: bool}` |
| POST | `/api/v1/reports/{type}/export` | Generate CSV/PDF export | Authenticated | `{export_id, status: "generating", expires_at}` (or immediate download for small reports) |
| GET | `/api/v1/reports/exports/{id}/download` | Download generated export (signed URL) | Authenticated | File download with Content-Disposition header |
| POST | `/api/v1/reports/saved/{id}/schedule` | Schedule recurring delivery | admin+ | `{scheduled_report_id, frequency, recipients, next_send_at}` |
| GET | `/api/v1/reports/scheduled` | List scheduled reports | admin+ | `{data: [{id, name, frequency, recipients, last_sent_at, next_send_at}]}` |
| PUT | `/api/v1/reports/scheduled/{id}` | Update scheduled report | admin+ | `{...}` |
| DELETE | `/api/v1/reports/scheduled/{id}` | Delete scheduled report | admin+ | `{success: bool}` |
| POST | `/api/v1/reports/scheduled/{id}/send-now` | Dispatch scheduled report immediately | admin+ | `{success: bool, scheduled_for: "now"}` |

## Events

| Event Name | Payload Fields | Materialized State Updated | Notes |
|------------|---------------|---------------------------|-------|
| `report_exported` | report_type, format, user_id, file_size, generated_at | report_exports table | Fired after export generation completes |
| `report_scheduled` | scheduled_report_id, frequency, recipients, next_send_at | scheduled_reports table | Fired when schedule created/updated |
| `scheduled_report_sent` | scheduled_report_id, recipient_count, format, sent_at | report_schedule_logs table | Fired after email delivery |

## Testing Notes

### Unit Tests
- **ReportFilterService:** verify date range filter (last_month returns correct date range). Verify channel filter applied correctly. Verify multiple filters combined (date + channel + SKU).
- **ReportQueryBuilder:** verify SQL queries return correct aggregations. Sales by channel query returns sum of revenue grouped by channel. Inventory query returns correct available qty (on_hand - committed).
- **ReportExportService:** verify CSV formatting (headers, quoting, escaping). Verify PDF rendering without errors. Verify large dataset export (10k rows) completes without timeout.
- **FinancialReportBuilder:** verify margin calculation: (revenue - cogs) / revenue. Verify COGS lookup from cost accounting. Verify AR aging buckets (current, 30, 60, 90+ days).

### Integration Tests
- **Sales report totals:** sales-by-channel report totals equal sum of all individual orders. Verify with known test data.
- **Inventory report accuracy:** current stock report on-hand qty matches inventory records. Committed qty matches unfulfilled order line items.
- **Club metrics:** active member count = count(members with status active as of month end). Churn rate = churned / (active_start + acquired). Verify with known member dataset.
- **Scheduled report delivery:** create scheduled report for "weekly Monday 7am". Manually trigger DispatchScheduledReports command. Verify email sent to recipients. Verify report_schedule_logs entry created.
- **Export download:** generate report export. Verify file created on disk/S3. Verify signed URL is valid. Verify download increments download_count. Verify expired URL (after 7 days) returns 403.
- **Favorite toggle:** save report, toggle favorite, verify is_favorite flipped. List saved reports, verify favorited reports listed first.

### Critical
- **Report accuracy:** financial report numbers must match accounting software exports — they will be compared. Unit test: known orders + known costs = expected margin. Integration test: export report, sum in spreadsheet, compare to accounting export.
- **Performance:** sales-by-channel for full year of data with 50 SKUs must load in < 2 seconds. Benchmark query, add indexes (orders.channel, orders.created_at), or materialized view if needed.
- **Timezone handling:** all dates displayed in winery timezone, not UTC. Test: create order at 11pm PST (7am UTC next day). Verify order shows in "today" report in PST timezone, not "tomorrow".
- **Large export handling:** report with 100k rows must generate as queued job, not block request. Verify status endpoint returns "generating", then "ready" when complete. Verify email sent with download link.
- **Concurrency:** two users export same report simultaneously. Verify two separate ReportExport records created, not overwritten.

## File Path Manifest
- Models: `api/app/Models/SavedReport.php`, `ScheduledReport.php`, `ReportExport.php`, `ReportScheduleLog.php`
- Services: `api/app/Services/Reporting/ReportBuilder.php`, `ReportFilterService.php`, `ReportExportService.php`, `ReportQueryBuilder.php`, `SalesReportBuilder.php`, `ProductionReportBuilder.php`, `InventoryReportBuilder.php`, `ClubReportBuilder.php`, `FinancialReportBuilder.php`, `ComplianceReportBuilder.php`
- Jobs: `api/app/Jobs/GenerateAndExportReportJob.php`, `SendScheduledReportJob.php`
- Console: `api/app/Console/Commands/DispatchScheduledReports.php`
- Filament: `api/app/Filament/Pages/Reports/ReportsIndex.php`, `ReportViewer.php`, `SalesReports.php`, `ProductionReports.php`, `InventoryReports.php`, `ClubReports.php`, `FinancialReports.php`, `ComplianceReports.php`
- API Controllers: `api/app/Http/Controllers/Api/V1/ReportController.php`
- Migrations: `api/database/migrations/2024_xx_xx_create_saved_reports_table.php`, `create_scheduled_reports_table.php`, `create_report_exports_table.php`, `create_report_schedule_logs_table.php`
- Views: `api/resources/views/emails/scheduled-report-delivery.blade.php`, Filament report pages
- Tests: `tests/Unit/Services/ReportFilterServiceTest.php`, `tests/Unit/Services/ReportExportServiceTest.php`, `tests/Integration/ReportAccuracyTest.php`, `tests/Integration/ScheduledReportDeliveryTest.php`
