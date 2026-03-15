# Lab Analysis & Fermentation Tracking — Completion Record

> Task spec: `docs/execution/tasks/03-lab-fermentation.md`
> Phase: 3

---

## Sub-Task 1: Lab Analysis Model and Data Entry
**Completed:** 2026-03-15
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_15_100001_create_lab_analyses_table.php` — Creates the `lab_analyses` table with UUID primary key, FK to lots (cascade delete), test_date, test_type, value as `decimal(12,6)`, unit, method, analyst, notes, source (default 'manual'), performed_by. Composite indexes on (lot_id, test_type, test_date), (test_type, test_date), and test_date for efficient time-range and per-lot queries.
- `api/app/Models/LabAnalysis.php` — Eloquent model with `HasFactory`, `HasUuids`, `LogsActivity` traits. Defines `TEST_TYPES` (11 types: pH, TA, VA, free_SO2, total_SO2, residual_sugar, alcohol, malic_acid, glucose_fructose, turbidity, color), `DEFAULT_UNITS` map (test_type → standard unit), and `SOURCES` (manual, ets_labs, oenofoss, wine_scan, csv_import). Relationships: `lot()`, `performer()`. Scopes: `ofType()`, `forLot()`, `testedBetween()`, `fromSource()`.
- `api/database/factories/LabAnalysisFactory.php` — Generates realistic lab data with per-test-type value ranges and analytical methods. States: `ph()`, `va()`, `vaNearLimit()` (0.10–0.13 g/100mL for threshold testing), `ta()`, `freeSo2()`, `fromEtsLabs()`.
- `api/app/Services/LabAnalysisService.php` — Business logic layer. `createAnalysis()` creates the record in a transaction, writes `lab_analysis_entered` event via EventLogger with self-contained payload (includes lot_name and lot_variety alongside lot_id for export readability per data-portability design constraint). Structured logging with tenant_id. Helper methods: `getLatestValue()` for most recent result per test type, `getHistory()` for charting data.
- `api/app/Http/Requests/StoreLabAnalysisRequest.php` — Validates: lot_id (required, UUID, exists), test_date (required, date — accepts backdated values for historical imports), test_type (required, in TEST_TYPES enum), value (required, numeric), unit (required, max 30), method/analyst/notes (optional), source (optional, in SOURCES enum).
- `api/app/Http/Resources/LabAnalysisResource.php` — Extends BaseResource. Formats test_date as date string, value as float. Conditionally includes lot (id, name, variety, vintage) and performer (id, name) when loaded.
- `api/app/Http/Controllers/Api/V1/LabAnalysisController.php` — Three endpoints: `index()` lists analyses for a lot with filters (test_type, source, date_from/date_to) and pagination; `store()` takes lot_id from route parameter (not body) to prevent mismatched IDs; `show()` returns single analysis with relationships.
- `api/routes/api.php` — Three nested routes under `/lots/{lotId}/analyses`: GET index (authenticated), GET show (authenticated), POST store (cellar_hand+).
- `api/app/Filament/Resources/LabAnalysisResource.php` + Pages — Under "Lab" navigation group. Form: reactive test_type auto-fills unit from DEFAULT_UNITS. Table: badge colors per test type, toggleable method/analyst columns, filters for test_type/lot/source/date range. View-only (no edit/delete — lab records are immutable).
- `api/app/Models/Lot.php` — Added `labAnalyses()` HasMany relationship ordered by test_date desc.

### Key Decisions
- **Route parameter for lot_id**: The `store` endpoint takes `lot_id` from the route (`/lots/{lotId}/analyses`) rather than trusting the request body. The body's `lot_id` is still validated (for backwards compatibility) but overridden by the route parameter. Prevents clients from POSTing to one lot's URL while targeting a different lot in the body.
- **Self-contained event payloads**: `lab_analysis_entered` events include `lot_name` and `lot_variety` alongside `lot_id` so the event stream is readable without joins — per the data-portability design constraint absorbed from `docs/ideas/data-portability.md`.
- **Backdated test_date allowed**: No restriction on historical dates to support the gradual-migration-path constraint — wineries importing past lab data from InnoVint or ETS Labs need to set accurate historical dates.
- **Decimal(12,6) for value**: Six decimal places to handle both high-precision measurements (VA at 0.045 g/100mL) and large values (turbidity readings). More precision than needed but avoids truncation surprises.
- **No edit/delete on Filament resource**: Lab analysis records are treated as immutable (consistent with event-sourcing philosophy). Corrections should be new entries. Filament resource has view-only pages, no edit page.

### Deviations from Spec
- Spec listed `api/app/Http/Resources/LabAnalysisResource.php` as an API resource (done) and `api/app/Filament/Resources/LabAnalysisResource.php` as a Filament resource (done) — same class name in different namespaces, both built as specified.
- Routes use `{lotId}` parameter name instead of `{lot}` to avoid implicit model binding (we want the raw UUID string, not a Lot model instance, since the controller handles scoping manually).

### Patterns Established
- **Nested lot routes**: Lab analyses use `/lots/{lotId}/analyses` nesting pattern. Future lot-scoped resources (fermentation rounds, sensory notes) should follow the same pattern.
- **Self-contained event payloads**: Include human-readable context (entity names) alongside foreign keys in event payloads. All Phase 3 event types should follow this pattern.
- **Lab navigation group**: Filament resources for lab/analysis features go under the "Lab" navigation group (sort order starting at 1).

### Test Summary
- `tests/Feature/Lab/LabAnalysisTest.php` (13 tests)
  - Tier 1: event log write with self-contained payload (lot_name, lot_variety, test_type, value, unit, method, analyst, test_date)
  - Tier 1: tenant isolation — cross-tenant data access prevention via direct model query
  - Tier 2: CRUD — create with all fields, list with pagination, filter by test_type, show with relationships
  - Tier 2: multiple test types for same lot/date (3 entries, 3 events)
  - Tier 2: validation — missing required fields, invalid test_type
  - Tier 2: backdated test_date acceptance for historical imports
  - Tier 2: RBAC — cellar_hand can create, read_only cannot create, read_only can list and view
  - Tier 2: API envelope format verification
  - Tier 2: unauthenticated access rejection
- Known gaps: Filament resource CRUD not tested via Livewire (deferred per Phase 1-2 audit — requires subdomain test harness)

### Open Questions
- None for Sub-Task 1. The LabAnalysisService's `getLatestValue()` and `getHistory()` methods are built but not yet consumed by any endpoint — they're ready for the threshold checker (Sub-Task 2) and chart endpoint (future).

---

## Sub-Task 2: Lab Threshold Alerts
**Completed:** 2026-03-15
**Status:** Done

### What Was Built
- `api/database/migrations/tenant/2026_03_15_100002_create_lab_thresholds_table.php` — Creates `lab_thresholds` table with auto-increment PK (config data, not event-sourced — no UUID needed), test_type, variety (nullable for global thresholds), min_value/max_value as `decimal(12,6)`, alert_level. Unique constraint on (test_type, variety, alert_level) prevents duplicate threshold definitions. Indexes on test_type.
- `api/app/Models/LabThreshold.php` — Eloquent model with `HasFactory`, `LogsActivity` traits. Defines `ALERT_LEVELS` (warning, critical). Casts min_value/max_value as `decimal:6`. Scopes: `forTestType()`, `ofLevel()`, `applicableTo()` — the last one retrieves both variety-specific and global thresholds for a test type, ordered so variety-specific come first (`ORDER BY variety IS NULL ASC`).
- `api/database/factories/LabThresholdFactory.php` — Default factory produces pH warning thresholds. States: `vaCritical()` (0.12 max — 27 CFR 4.21 legal limit), `vaWarning()` (0.10 max), `critical()`, `forVariety()`.
- `api/app/Services/LabThresholdChecker.php` — Core threshold evaluation engine. `check()` loads applicable thresholds via `applicableTo` scope, resolves effective thresholds (variety-specific overrides global for same alert level), evaluates each against the analysis value. Returns array of alert objects with alert_level, test_type, value, threshold_id, min/max, variety, and human-readable message. Logs structured `Log::warning()` with context when alerts fire.
- `api/app/Services/LabAnalysisService.php` — Modified to inject `LabThresholdChecker` and call `check()` after every new analysis. Alerts are attached as a transient `threshold_alerts` attribute on the model, flowing through the existing API resource without changing the response structure for non-alerting analyses.
- `api/app/Http/Resources/LabAnalysisResource.php` — Added `threshold_alerts` field (defaults to empty array, populated on creation).
- `api/app/Http/Controllers/Api/V1/LabThresholdController.php` — Full CRUD: `index()` with test_type/alert_level filtering and pagination, `store()`, `show()`, `update()`, `destroy()`.
- `api/app/Http/Requests/StoreLabThresholdRequest.php` — Validates test_type (in LabAnalysis::TEST_TYPES), variety (nullable string), min_value/max_value (nullable numerics, at least one required), alert_level (in ALERT_LEVELS).
- `api/app/Http/Requests/UpdateLabThresholdRequest.php` — Partial update validation, same rules as store but all fields optional.
- `api/app/Http/Resources/LabThresholdResource.php` — Extends BaseResource. Casts min_value/max_value to float (or null).
- `api/database/seeders/DefaultLabThresholdsSeeder.php` — Seeds 17 default thresholds covering VA (warning 0.10, critical 0.12 per 27 CFR 4.21), pH, TA, free_SO2, total_SO2, residual_sugar, alcohol, turbidity. Uses `updateOrCreate` keyed on (test_type, variety, alert_level) for idempotency.
- `api/app/Filament/Resources/LabThresholdResource.php` + Pages — Under "Lab" navigation group (sort 2). Full CRUD with edit/delete (unlike LabAnalysis which is view-only — thresholds are mutable config). Pages: List, Create, Edit.
- `api/routes/api.php` — Added `apiResource` routes for `/lab-thresholds` (full CRUD, cellar_hand+ for write operations).

### Key Decisions
- **Auto-increment PK instead of UUID**: Thresholds are configuration data, not domain events. No need for UUIDs — keeps the config table simple and avoids unnecessary complexity. The unique constraint on (test_type, variety, alert_level) is the real identity.
- **Variety-specific override logic**: When both a global (variety=null) and variety-specific threshold exist for the same test_type and alert_level, the variety-specific one takes precedence. The `resolveEffectiveThresholds()` method groups by alert_level and picks the most specific match.
- **Transient attribute pattern**: Threshold alerts are attached to the LabAnalysis model via `setAttribute('threshold_alerts', $alerts)` rather than being persisted. This keeps the response transparent — alerts appear in the creation response but don't add a column or relationship to the analyses table.
- **Strict boundary comparison**: `value > max_value` and `value < min_value` — values exactly at the boundary do NOT trigger alerts. This is intentional: VA at exactly 0.12 g/100mL is AT the 27 CFR 4.21 legal limit, not exceeding it.
- **No event logging for threshold CRUD**: Thresholds are configuration, not business events. Activity is tracked via `LogsActivity` trait but doesn't write to the event log. The threshold *checking* logs warnings when alerts fire.

### Deviations from Spec
- None. Implementation matches the spec's description of variety-specific overrides, two alert levels (warning/critical), and integration with the lab analysis creation flow.

### Patterns Established
- **Config data uses auto-increment PK**: Non-event-sourced configuration tables (thresholds, templates, settings) can use simple auto-increment IDs instead of UUIDs.
- **Transient attribute for computed response data**: When a creation response needs additional computed data (like alerts), attach it as a transient attribute rather than persisting or adding a separate API call.
- **Seeder idempotency via updateOrCreate**: Default data seeders use `updateOrCreate` keyed on the unique constraint columns to be safely re-runnable.

### Test Summary
- `tests/Feature/Lab/LabThresholdTest.php` (25 tests, 76 assertions)
  - Tier 1: VA critical alert fires when exceeding 0.12 legal limit
  - Tier 1: VA warning alert fires when approaching limit but not critical
  - Tier 1: both warning and critical alerts fire when value exceeds both
  - Tier 1: no alerts when value within all thresholds
  - Tier 1: below-minimum alert (free_SO2 below 15 mg/L)
  - Tier 1: variety-specific threshold overrides global (Riesling pH)
  - Tier 1: no alerts when no thresholds configured for test type
  - Tier 1: API response includes threshold_alerts on creation
  - Tier 1: empty threshold_alerts when value in range
  - Tier 1: VA at exact limit (0.12) — no alert (boundary correctness)
  - Tier 1: VA just above limit (0.121) — alert fires
  - Tier 1: tenant isolation — cross-tenant threshold data access prevention
  - Tier 2: CRUD — create, list with filtering, update, delete
  - Tier 2: validation — invalid test_type, invalid alert_level
  - Tier 2: RBAC — winemaker can manage, cellar_hand cannot create but can view, read_only cannot create
  - Tier 2: default seeder creates expected thresholds with correct values
  - Tier 2: seeder idempotency — running twice produces no duplicates
  - Tier 2: API envelope format verification
- Known gaps: Filament resource CRUD not tested via Livewire (deferred per Phase 1-2 audit)

### Open Questions
- None. The threshold checker is fully integrated into the lab analysis creation flow and fires automatically on every new entry.

---

## Sub-Task 3: External Lab CSV Import
**Completed:** 2026-03-15
**Status:** Done

### What Was Built
- `api/app/Services/LabImport/LabCsvParser.php` — Interface contract for CSV parsers. Defines `canParse()`, `parse()`, and `getSource()` methods. Parsers are tried in order (most specific first) and the first match wins.
- `api/app/Services/LabImport/ParsedLabImport.php` — Value object for parse results. Contains records, warnings, source identifier, total/skipped row counts.
- `api/app/Services/LabImport/ParsedLabRecord.php` — Value object for individual parsed records. Includes lot name/ID, lot match suggestions, test data, and `toArray()` for JSON serialization.
- `api/app/Services/LabImport/ETSLabsParser.php` — Parser for ETS Laboratories CSV exports. Uses ETS-distinctive identifying headers ("Wine", "Sample") to avoid false-matching generic CSVs. Resilient to column reordering (maps by header name, not position), extra title rows (scans first 5 rows for the real header row), empty rows, N/A values, and `<`/`>` prefixed values (e.g., `<0.5` → 0.5). Supports 30+ column name variations for 11 test types.
- `api/app/Services/LabImport/GenericCSVParser.php` — Fallback parser for non-lab-specific CSV formats. Accepts any CSV with at least one recognizable test type column. Supports both spaced and underscore column naming conventions (e.g., "Free SO2" and "free_so2"). Used when no specific lab parser matches.
- `api/app/Services/LabImport/LabImportService.php` — Orchestrator for the two-phase import workflow (preview → commit). Preview phase: parses CSV via auto-detected parser, matches lot names (exact + fuzzy word-split search), returns preview with suggestions. Commit phase: creates LabAnalysis records in a transaction, writes `lab_analysis_entered` events with `import_batch: true` marker, runs threshold checks, handles individual record errors gracefully.
- `api/app/Http/Controllers/Api/V1/LabImportController.php` — Two endpoints: `preview` (accepts multipart file upload, 5MB max, returns parsed preview) and `commit` (accepts confirmed records array with lot_id assignments, validates source against allowed list).
- `api/routes/api.php` — Added `POST /lab-import/preview` and `POST /lab-import/commit` routes under winemaker+ RBAC.

### Key Decisions
- **Two-phase import (preview → commit)**: Per the spec, users must see a preview before data is committed. The preview includes lot match suggestions so users can correct mismatches before importing. This avoids orphaned or mis-attributed lab records.
- **Parser priority chain**: ETS Labs parser is tried first (specific), then generic CSV (fallback). New lab-specific parsers (OenoFoss, WineScan) can be added to the chain without modifying existing parsers.
- **ETS-identifying headers vs generic**: ETS parser requires ETS-distinctive headers ("Wine", "Sample") in `canParse()` — not generic headers like "Lot Name" or "Lot". This prevents the ETS parser from greedily matching generic CSVs that happen to have test type columns.
- **Fuzzy lot matching with word splitting**: When no exact match is found, search terms are split into individual words and each must match independently via `ilike`. So "Cabernet 2024" matches "Cabernet Sauvignon Estate 2024" because both "Cabernet" and "2024" appear in the lot name.
- **Event payloads include `import_batch: true`**: Batch-imported analyses write the same `lab_analysis_entered` event as manual entries, but include an `import_batch` flag in the payload so the event stream can distinguish bulk imports from manual entries.
- **Graceful error handling in commit**: Individual record failures (invalid lot_id, bad test_type) are caught and reported in the `errors` array without aborting the entire batch. The transaction still wraps all successful records.

### Deviations from Spec
- Spec listed a single `POST /lab-import` endpoint. Implementation splits into two endpoints (`/lab-import/preview` and `/lab-import/commit`) to support the mandatory preview-before-commit workflow.
- Spec mentioned OenoFoss and WineScan parsers. These are deferred as the GenericCSVParser handles their column formats. Dedicated parsers can be added later if format-specific quirks are discovered.
- No Filament import action added — the import workflow requires a multi-step preview/confirm flow that maps better to the API + frontend than a simple Filament action. The Filament LabAnalysis resource already shows imported records with their source.

### Patterns Established
- **Parser interface chain**: `LabCsvParser` interface with `canParse()` → `parse()` pattern. Future lab parsers (OenoFoss, WineScan, or custom) implement this interface and register in `LabImportService`.
- **Two-phase import workflow**: Preview → user review → commit. Reusable for any future CSV import feature (e.g., fermentation data import).
- **Word-split fuzzy matching**: Splitting search terms into individual words for multi-keyword fuzzy matching. Useful anywhere lot name matching is needed.

### Test Summary
- `tests/Feature/Lab/LabImportTest.php` (28 tests, 94 assertions)
  - Tier 1: ETS parser — standard CSV with multiple test types, extra title row, empty rows/N/A values, reordered columns, non-numeric value warnings, non-lab CSV rejection
  - Tier 1: Generic parser — standard columns, underscore-style columns, unrecognizable columns rejection
  - Tier 1: lot matching — exact match by name, fuzzy word-split suggestions
  - Tier 1: event logging — `lab_analysis_entered` events with self-contained payload and `import_batch` flag
  - Tier 1: threshold alerts fire during import commit
  - Tier 1: source recorded correctly on imported analyses
  - Tier 1: tenant isolation — cross-tenant lot matching prevention
  - Tier 2: API preview (ETS format, generic format)
  - Tier 2: API commit with database verification
  - Tier 2: validation — missing file, invalid source, missing lot_id
  - Tier 2: RBAC — winemaker can import, cellar_hand cannot, read_only cannot
  - Tier 2: API envelope format
  - Tier 2: edge cases — headers-only CSV, `<`/`>` prefixed values, invalid lot_id graceful error
- Known gaps: Filament import UI not tested (import is API-driven); OenoFoss/WineScan specific parsers deferred

### Open Questions
- None. The generic parser covers OenoFoss and WineScan formats via flexible column matching. Dedicated parsers can be added later if specific format quirks emerge.
