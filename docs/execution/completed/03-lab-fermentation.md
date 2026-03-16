# Lab Analysis & Fermentation Tracking

## Phase
Phase 2

## Dependencies
- `01-foundation.md` — event log, auth, Filament
- `02-production-core.md` — Lot model (lab analyses and fermentation entries belong to lots)

## Goal
Provide winemakers with structured lab analysis tracking and fermentation monitoring. Lab analysis records pH, TA, VA, SO2, and other metrics per lot over time with threshold alerts. Fermentation tracking records daily Brix/temperature data and generates fermentation curve charts. This data feeds into TTB reporting and AI features later.

## Data Models

- **LabAnalysis** — `id` (UUID), `lot_id`, `test_date`, `test_type` (pH/TA/VA/free_SO2/total_SO2/residual_sugar/alcohol/malic_acid/glucose_fructose/turbidity/color), `value` (decimal), `unit`, `method`, `analyst`, `notes`, `source` (manual/ets_labs/oenofoss/wine_scan), `created_at`
  - Relationships: belongsTo Lot

- **LabThreshold** — `id`, `test_type`, `variety` (nullable — for variety-specific thresholds), `min_value`, `max_value`, `alert_level` (warning/critical), `created_at`, `updated_at`

- **FermentationRound** — `id` (UUID), `lot_id`, `round_number` (1 for primary, 2 for ML), `fermentation_type` (primary/malolactic), `inoculation_date`, `yeast_strain` (nullable), `ml_bacteria` (nullable), `target_temp`, `nutrients_schedule` (JSON), `status` (active/completed/stuck), `completion_date`, `confirmation_date` (for ML), `created_at`, `updated_at`
  - Relationships: belongsTo Lot, hasMany FermentationEntries

- **FermentationEntry** — `id` (UUID), `fermentation_round_id`, `entry_date`, `temperature`, `brix_or_density` (decimal), `measurement_type` (brix/specific_gravity), `free_so2` (nullable), `notes`, `performed_by`, `created_at`
  - Relationships: belongsTo FermentationRound

- **SensoryNote** — `id` (UUID), `lot_id`, `taster_id` (FK users), `date`, `rating` (decimal — 1-5 or 100pt, configurable), `rating_scale` (five_point/hundred_point), `nose_notes`, `palate_notes`, `overall_notes`, `created_at`

## Sub-Tasks

### 1. Lab analysis model and data entry
**Description:** Create the LabAnalysis model with migration, CRUD API, and Filament resource. Support both manual entry and CSV import from external labs.
**Files to create:**
- `api/app/Models/LabAnalysis.php`
- `api/database/migrations/xxxx_create_lab_analyses_table.php`
- `api/app/Http/Controllers/Api/V1/LabAnalysisController.php`
- `api/app/Http/Resources/LabAnalysisResource.php`
- `api/app/Filament/Resources/LabAnalysisResource.php`
**Acceptance criteria:**
- Lab analysis entries can be created with all standard test types
- Entries are linked to a lot and displayed in chronological order
- Multiple test types can be recorded for the same date (one entry per test type)
- Analysis history viewable per lot as both table and chart
- Writes `lab_analysis_entered` event to event log
**Gotchas:** VA (volatile acidity) has legal limits — flag when approaching 0.12 g/100ml for table wine. This threshold varies by wine type.

### 2. Lab threshold alerts
**Description:** Implement configurable thresholds for lab values. When a new analysis entry exceeds a threshold, generate a notification/alert.
**Files to create:**
- `api/app/Models/LabThreshold.php`
- `api/database/migrations/xxxx_create_lab_thresholds_table.php`
- `api/app/Services/LabThresholdChecker.php`
- `api/database/seeders/DefaultLabThresholdsSeeder.php`
**Acceptance criteria:**
- Thresholds configurable per test type, optionally per variety
- Checking runs automatically on each new lab entry
- Alerts generated for warning and critical levels
- Default thresholds seeded for common tests (VA < 0.12, pH ranges, etc.)
**Gotchas:** VA legal limit is 0.12 g/100ml for table wine, 0.14 for dessert. pH alerts are style-dependent. Keep thresholds configurable rather than hardcoded.

### 3. External lab CSV import
**Description:** Import lab analysis results from ETS Labs, OenoFoss, and Wine Scan CSV exports.
**Files to create:**
- `api/app/Services/LabImport/LabImportService.php`
- `api/app/Services/LabImport/ETSLabsParser.php`
- `api/app/Services/LabImport/GenericCSVParser.php`
- `api/app/Http/Controllers/Api/V1/LabImportController.php`
**Acceptance criteria:**
- Upload CSV → system parses and maps columns to test types
- Preview shows parsed results before committing
- Lot matching by name (with fuzzy match suggestions if no exact match)
- Records source as the external lab name
- Handles common CSV formatting issues (extra headers, empty rows)
**Gotchas:** Lab CSV formats change. Build the parser to be resilient to column reordering. Always show a preview before importing.

### 4. Fermentation round and daily entry tracking
**Description:** Build fermentation tracking with rounds (primary and malolactic) and daily data entries (temp, Brix/density, SO2, notes).
**Files to create:**
- `api/app/Models/FermentationRound.php`
- `api/app/Models/FermentationEntry.php`
- `api/database/migrations/xxxx_create_fermentation_rounds_table.php`
- `api/database/migrations/xxxx_create_fermentation_entries_table.php`
- `api/app/Http/Controllers/Api/V1/FermentationController.php`
- `api/app/Filament/Resources/FermentationRoundResource.php`
**Acceptance criteria:**
- Create fermentation rounds per lot (primary and ML tracked separately)
- Daily entries: date, temp, Brix (or specific gravity), free SO2, notes
- Round status tracking: active → completed/stuck
- ML fermentation: inoculation date, bacteria strain, completion confirmation
- Writes `fermentation_data_entered` event per entry
**Gotchas:** Brix and specific gravity are different measurements — store which type was used. Primary fermentation tracks Brix decrease; ML tracks malic acid decrease (different metrics).

### 5. Fermentation curve chart
**Description:** Build the fermentation curve visualization — temperature + Brix plotted over time per fermentation round. This is a custom Livewire component in Filament.
**Files to create:**
- `api/app/Filament/Widgets/FermentationCurveChart.php` (custom Livewire)
- `api/app/Http/Controllers/Api/V1/FermentationChartController.php` — returns chart data as JSON
**Acceptance criteria:**
- Dual-axis chart: Brix on left Y axis, temperature on right Y axis, date on X axis
- Interactive (hover to see values at any point)
- Viewable per fermentation round within the lot detail page
- API endpoint returns data in chart-ready format (for mobile app consumption)
**Gotchas:** Use a JS charting library compatible with Livewire (Alpine.js Chart.js integration or similar). Keep it simple — this will be rebuilt as a native chart in the mobile apps.

### 6. Sensory/tasting notes
**Description:** Allow winemakers to record tasting notes per lot with configurable rating scales.
**Files to create:**
- `api/app/Models/SensoryNote.php`
- `api/database/migrations/xxxx_create_sensory_notes_table.php`
- `api/app/Http/Controllers/Api/V1/SensoryNoteController.php`
**Acceptance criteria:**
- Tasting notes record: lot, taster, date, rating (1-5 or 100pt), nose/palate/overall notes
- Rating scale configurable per winery
- Notes viewable in lot timeline alongside other events
- Multiple tasters can note the same lot on the same date
**Gotchas:** This is not wine-review-style scoring — it's internal winemaker notes. Keep it lightweight.

### 7. Lab and fermentation demo data
**Description:** Extend the demo seeder with realistic lab analysis and fermentation data for demo lots.
**Files to modify:**
- `api/database/seeders/ProductionSeeder.php` — add lab and fermentation data
**Acceptance criteria:**
- Demo lots have realistic lab analysis histories (pH, TA, VA, SO2 readings over time)
- At least 2 lots have active fermentation rounds with daily entries showing Brix decrease
- Fermentation curves look realistic when charted
**Gotchas:** Realistic Brix curves start around 24-26 and decrease to -1 to -2 (dry) over 7-21 days. Temperature should be 55-65°F for whites, 75-90°F for reds.

## API Endpoints

| Method | Path | Description | Auth Scope |
|--------|------|-------------|------------|
| GET | `/api/v1/lots/{lot}/analyses` | List lab analyses for lot | Authenticated |
| POST | `/api/v1/lots/{lot}/analyses` | Add lab analysis entry | cellar_hand+ |
| POST | `/api/v1/lab-import` | Import CSV from external lab | winemaker+ |
| GET | `/api/v1/lots/{lot}/fermentations` | List fermentation rounds | Authenticated |
| POST | `/api/v1/lots/{lot}/fermentations` | Create fermentation round | winemaker+ |
| POST | `/api/v1/fermentations/{round}/entries` | Add daily entry | cellar_hand+ |
| GET | `/api/v1/fermentations/{round}/chart` | Get chart data | Authenticated |
| POST | `/api/v1/lots/{lot}/sensory-notes` | Add tasting note | winemaker+ |

## Events

| Event Name | Payload Fields | Materialized State Updated |
|------------|---------------|---------------------------|
| `lab_analysis_entered` | lot_id, test_type, value, unit, method, analyst | lab_analyses table |
| `fermentation_data_entered` | round_id, date, temp, brix, so2, notes | fermentation_entries table |
| `fermentation_completed` | round_id, completion_date | fermentation_rounds table |

## Testing Notes
- **Unit tests:** Threshold checking logic, CSV parsing for each supported lab format, Brix/SG conversion
- **Integration tests:** Full fermentation lifecycle (create round → daily entries → mark complete), lab import with lot matching
- **Critical:** VA threshold alerts must fire correctly — this has compliance implications. Test with values at, just below, and just above the legal limit.
