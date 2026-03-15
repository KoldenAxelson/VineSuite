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
