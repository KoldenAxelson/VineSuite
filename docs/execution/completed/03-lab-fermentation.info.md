# Lab Analysis & Fermentation Tracking — Completion Record

> Task spec: `docs/execution/tasks/03-lab-fermentation.md` | Phase: 3

---

## Sub-Task 1: Lab Analysis Model and Data Entry
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions & Patterns
- Route parameter `lot_id` from URL, not body: prevents cross-lot POST attacks; body `lot_id` validated but overridden
- Self-contained event payloads: include lot_name, lot_variety alongside lot_id per data-portability constraint
- Backdated test_date allowed: supports gradual data migration from legacy systems
- Decimal(12,6): handles 0.045 g/100mL precision and large turbidity values
- Lab records immutable: no edit/delete on Filament; corrections = new entries
- Routes use `{lotId}` not `{lot}`: avoids implicit model binding, controller handles scoping
- `/lots/{lotId}/analyses` nesting: establish pattern for lot-scoped resources

### Files
Migration: `api/database/migrations/tenant/2026_03_15_100001_create_lab_analyses_table.php` — UUID PK, FK to lots (cascade), test_date, test_type, value (decimal 12,6), unit, method, analyst, notes, source (default 'manual'), performed_by. Indexes on (lot_id, test_type, test_date), (test_type, test_date), test_date.

Model: `api/app/Models/LabAnalysis.php` — Traits: HasFactory, HasUuids, LogsActivity. Constants: TEST_TYPES (pH, TA, VA, free_SO2, total_SO2, residual_sugar, alcohol, malic_acid, glucose_fructose, turbidity, color); SOURCES (manual, ets_labs, oenofoss, wine_scan, csv_import). Relations: lot(), performer(). Scopes: ofType(), forLot(), testedBetween(), fromSource().

Factory: `api/database/factories/LabAnalysisFactory.php` — Per-test-type ranges, analytical methods. States: ph(), va(), vaNearLimit(0.10–0.13), ta(), freeSo2(), fromEtsLabs().

Service: `api/app/Services/LabAnalysisService.php` — createAnalysis() in transaction, EventLogger writes lab_analysis_entered with lot_name, lot_variety. Methods: getLatestValue(), getHistory() (used by threshold checker & future charts).

Requests/Resources: StoreLabAnalysisRequest validates lot_id, test_date (backdated OK), test_type, value, unit, method/analyst/notes (optional), source. LabAnalysisResource formats test_date, value (float); conditionally includes lot, performer.

Controller: `api/app/Http/Controllers/Api/V1/LabAnalysisController.php` — index(filters by test_type/source/date_from/date_to, paginated), store(lot_id from URL), show(with relationships).

Routes: `GET/POST /lots/{lotId}/analyses`, `GET /lots/{lotId}/analyses/{id}` — authenticated.

Filament: `api/app/Filament/Resources/LabAnalysisResource.php` under "Lab" nav group. Form: reactive test_type auto-fills unit. Table: badge colors per type, method/analyst toggleable, date-range filter. View-only.

Model addition: Lot.php added labAnalyses() HasMany (test_date desc).

### Deviations
Spec listed both LabAnalysisResource classes (API and Filament) as separate — both built in separate namespaces as specified.

### Tests (13 tests)
Tier 1: event payload (lot_name, lot_variety, test_type, value, unit, method, analyst, test_date); tenant isolation via direct model query.
Tier 2: CRUD (all fields, pagination, filter by test_type, show with relations); multiple entries same lot/date; validation (required fields, invalid test_type); backdated dates; RBAC (cellar_hand create, read_only cannot); API envelope.
Gap: Filament CRUD not tested via Livewire (requires subdomain harness).

### Open Questions
- None. getLatestValue() and getHistory() ready for threshold checker and future charts.

---

## Sub-Task 2: Lab Threshold Alerts
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions & Patterns
- Auto-increment PK (not UUID): thresholds are config, not events; unique constraint on (test_type, variety, alert_level) is identity
- Variety-specific override: when both global (variety=null) and variety-specific exist, variety-specific takes precedence; resolveEffectiveThresholds() groups by alert_level
- Transient attribute: alerts attached via setAttribute('threshold_alerts', ...) not persisted; appears in creation response, no schema column
- Strict boundary: value > max_value or < min_value; value AT boundary does NOT alert (VA at exactly 0.12 is AT legal limit, not exceeding)
- No event logging for threshold CRUD: config data, not business events; LogsActivity tracks changes but no event log write
- Config uses auto-increment, event-sourced uses UUID: pattern for future (templates, settings)

### Files
Migration: `api/database/migrations/tenant/2026_03_15_100002_create_lab_thresholds_table.php` — auto-increment PK, test_type, variety (nullable), min_value/max_value (decimal 12,6), alert_level. Unique on (test_type, variety, alert_level).

Model: `api/app/Models/LabThreshold.php` — HasFactory, LogsActivity. ALERT_LEVELS (warning, critical). Scopes: forTestType(), ofLevel(), applicableTo() (returns variety-specific + global, ordered by variety IS NULL ASC).

Factory: `api/database/factories/LabThresholdFactory.php` — default pH warning. States: vaCritical(0.12 per 27 CFR 4.21), vaWarning(0.10), critical(), forVariety().

Service: `api/app/Services/LabThresholdChecker.php` — check() loads thresholds via applicableTo, resolves effective (variety overrides global), evaluates against value. Returns alert objects with alert_level, test_type, value, threshold_id, min/max, variety, message. Logs Log::warning() when alerts fire.

LabAnalysisService modified: inject LabThresholdChecker, call check() after every analysis creation. Alerts attached as transient attribute.

LabAnalysisResource: added threshold_alerts field (empty array if none).

Controller: `api/app/Http/Controllers/Api/V1/LabThresholdController.php` — Full CRUD: index(test_type/alert_level filters, paginated), store, show, update, destroy.

Requests: StoreLabThresholdRequest validates test_type (in LabAnalysis::TEST_TYPES), variety (nullable), min_value/max_value (nullable, at least one required), alert_level (in ALERT_LEVELS). UpdateLabThresholdRequest same but all optional.

Seeder: `api/database/seeders/DefaultLabThresholdsSeeder.php` — 17 defaults (VA warning 0.10/critical 0.12, pH, TA, free_SO2, total_SO2, residual_sugar, alcohol, turbidity) via updateOrCreate on (test_type, variety, alert_level) for idempotency.

Filament: under "Lab" nav (sort 2). Full CRUD (unlike LabAnalysis which is view-only). Pages: List, Create, Edit.

Routes: `apiResource('/lab-thresholds')` — CRUD, cellar_hand+ for writes.

### Tests (25 tests, 76 assertions)
Tier 1: VA critical alert fires >0.12; VA warning fires approaching but not critical; both fire when exceeds both; no alerts within all thresholds; free_SO2 below-minimum; variety-specific overrides global (Riesling pH); no alerts when test_type unconfigured.
Tier 2: RBAC (cellar_hand CRUD, read_only can list/view); API envelope; boundary conditions.
Gap: Filament resource CRUD not tested via Livewire.

---

## Sub-Task 3: Fermentation Rounds & Tracking
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions & Patterns
- Explicit lifecycle endpoints: POST .../complete and .../stuck instead of generic PATCH (establishes pattern for barrel ops, bottling)
- Measurement type pairing: store value + unit_type together; required_with validation
- Reactive Filament forms: ->visible(fn($get) => ...) keeps UI clean per context
- No confirmation_date until ML-specific workflow defined
- Events for lifecycle transitions: fermentation_round_created, fermentation_data_entered, fermentation_completed
- Self-contained event payloads: lot_name, lot_variety, yeast_strain, inoculation_date included

### Files
Migration: `api/database/migrations/tenant/2026_03_15_100003_create_fermentation_rounds_table.php` — UUID PK, FK to lots (cascade), fermentation_type (primary/ml), yeast_strain (nullable), bacteria_strain (nullable), inoculation_date, target_temp (nullable), status (active/completed/stuck), completion_date/stuck_date (nullable). Indexes on (lot_id, fermentation_type), (lot_id, status), inoculation_date.

Data table: `api/database/migrations/tenant/2026_03_15_100004_create_fermentation_data_table.php` — UUID PK, FK to fermentation_round (cascade), measurement_date, brix_or_density (decimal 10,4 nullable), measurement_type (brix/specific_gravity), temperature (nullable), free_so2 (nullable). Index on (fermentation_round_id, measurement_date).

Model: `api/app/Models/FermentationRound.php` — HasFactory, HasUuids, LogsActivity. Constants: FERMENTATION_TYPES (primary, ml), STATUSES (active, completed, stuck). Relations: lot(), entries() HasMany, performer(). Scopes: forLot(), byType(), withStatus(). Methods: complete(), stuck(), confirmMlDryness() (sets confirmation_date on ML rounds).

Factory: `api/database/factories/FermentationRoundFactory.php` — Realistic ranges. States: primary(), ml(), active(), completed(), withBrixCurve().

Data model & factory: FermentationData with per-entry timestamp, brix/sg/temp/so2 fields.

Service: `api/app/Services/FermentationService.php` — createRound(), addEntry(), complete(confirmation_date for ML), stuck(). All write events via EventLogger with self-contained payloads. Supports nutrient scheduling JSON (unvalidated — wineries have diverse protocols).

Controller: `api/app/Http/Controllers/Api/V1/FermentationRoundController.php` — index(paginated, count), store, show, POST .../complete, POST .../stuck.

Requests: StoreRequest validates lot_id, fermentation_type, yeast/bacteria_strain, inoculation_date, target_temp. FermentationDataRequest validates measurement_date, brix_or_density (required_with measurement_type), measurement_type, temperature (optional), free_so2 (optional).

Resources: FermentationRoundResource (includes lot, entry count, performer), FermentationDataResource (formatted measurements).

Filament: under "Lab" nav (sort 3). Form: reactive yeast_strain/bacteria_strain visible per fermentation_type. Table: status badge, real-time entry count. View + Edit pages.

Routes: `/lots/{lotId}/fermentations` CRUD, POST `/fermentations/{roundId}/complete`, POST `/fermentations/{roundId}/stuck`.

Model addition: Lot.php added fermentationRounds() HasMany.

### Tests (22 tests)
Tier 1: fermentation_round_created event (lot_name, lot_variety, fermentation_type, yeast_strain, inoculation_date); fermentation_data_entered event (temperature, brix_or_density, measurement_type); fermentation_completed event; full lifecycle → 7 entries → complete (verify 7 data + 1 completion event); ML fermentation with bacteria_strain & null yeast_strain; Brix vs SG measurement_type stored correctly; tenant isolation.
Tier 2: list rounds per lot with count; filter by fermentation_type; mark stuck; validation (invalid fermentation_type, measurement_type); RBAC (winemaker create rounds, cellar_hand add entries, read_only cannot); API envelope.
Gap: Filament CRUD not tested; confirmMlDryness() built but no endpoint yet.

### Open Questions
- confirmMlDryness() method built but no API endpoint (will be added when ML workflow fully specced).
- nutrients_schedule JSON intentionally unvalidated; structured schema can be added if consistency needed.

---

## Sub-Task 4: Sensory/Tasting Notes
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions & Patterns
- Winemaker+ for creation (not cellar_hand): tasting is evaluative, routine data collection is cellar's job
- Immutable records: no edit/delete; corrections = new entries
- Boolean flags in event payloads: has_nose_notes, has_palate_notes, has_overall_notes instead of full text (keeps events lightweight)
- Rating nullable: tasters may record qualitative assessment without numeric score
- Rating scale per-note not per-winery: more flexible; winery-level default can be added later
- Per-record config over per-tenant: avoids migration complexity when preference changes
- PHPDoc @property annotations for PHPStan type resolution

### Files
Migration: `api/database/migrations/tenant/2026_03_15_100005_create_sensory_notes_table.php` — UUID PK, FK to lots (cascade), taster_id (FK to users, cascade), date, rating (decimal 5,2 nullable), rating_scale (default five_point), nose/palate/overall_notes (text nullable). Indexes on (lot_id, date), (taster_id, date), date.

Model: `api/app/Models/SensoryNote.php` — HasFactory, HasUuids, LogsActivity. PHPDoc @property for PHPStan. Constants: RATING_SCALES (five_point, hundred_point). Relations: lot(), taster(). Scopes: forLot(), byTaster(), recordedBetween(). Casts: date, rating (decimal:2).

Factory: `api/database/factories/SensoryNoteFactory.php` — Winery vocabulary (cherry/cassis, citrus/apple, tropical nose; tannin/acidity/body/finish palate; barrel-readiness/bottling-timeline assessments). States: hundredPoint(78–98), fivePoint().

Service: `api/app/Services/SensoryNoteService.php` — createNote() in transaction, EventLogger writes sensory_note_recorded with lot_name, lot_variety, taster_name, date, rating, rating_scale, has_*_notes flags.

Controller: `api/app/Http/Controllers/Api/V1/SensoryNoteController.php` — index(taster/date filters, descending date), store(lot_id from URL, taster_id from auth), show.

Requests: StoreSensoryNoteRequest validates lot_id (optional uuid exists), date (required), rating (nullable numeric), rating_scale (nullable, in RATING_SCALES), note fields (nullable string max:5000).

Resources: SensoryNoteResource (rating cast float; conditionally includes lot, taster).

Filament: under "Lab" nav (sort 4, label "Tasting Notes"). Form: rating max adapts to rating_scale (5 vs 100). Table: rating formatted "X/5" or "X/100". View-only (no edit).

Routes: `GET/POST /lots/{lotId}/sensory-notes`, `GET /lots/{lotId}/sensory-notes/{sensoryNote}` — creation winemaker+, reading authenticated.

Model addition: Lot.php added sensoryNotes() HasMany (date desc).

### Deviations
Spec said "configurable per winery" — implementation is per-note (strictly more flexible).

### Tests (16 tests)
Tier 1: sensory_note_recorded event (lot_name, lot_variety, taster_name, date, rating, rating_scale, has_*_notes); five-point & hundred-point scales; notes without rating; multiple tasters same lot/date (with forgetGuards); tenant isolation.
Tier 2: list (most recent first), show with relations, create all fields; validation (invalid rating_scale, missing date); RBAC (winemaker create, cellar_hand cannot, read_only can list); API envelope.
Gap: Filament CRUD not tested via Livewire.

### Open Questions
- None. Structured aroma wheel vocabularies can be added as optional metadata later.

---

## Sub-Task 5: Fermentation Curve Chart
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions & Patterns
- Chart.js via CDN not npm: spec says "keep simple, will be rebuilt as native chart in mobile apps" — avoids build step; Alpine.js (Filament-bundled) handles init
- Two endpoints: single-round detail (/fermentations/{roundId}/chart) + lot-level overlay (/lots/{lotId}/fermentation-chart) for comparing primary & ML
- Dynamic axis label: y_left resolved by measurement types present (brix, specific_gravity, brix_or_density)
- No event logging: read-only endpoints don't mutate state
- Target temp reference line: shown faint/dashed on temp axis when set; helps verify fermentation stayed in range
- free_so2 included in series: not plotted but available for mobile/future enhancements

### Files
Controller: `api/app/Http/Controllers/Api/V1/FermentationChartController.php` — show(single round: date/temp/brix/measurement_type/free_so2 series, metadata, axis config with resolved y_left label), lotOverview(all rounds overlaid with per-round series/label/metadata).

Widget: `api/app/Filament/Widgets/FermentationCurveChart.php` — Livewire widget loading entries, preparing labels/brix/temp arrays, determining axis label. Mounted on ViewFermentationRound page footer. $isDiscovered = false (page-specific only).

Blade: `api/resources/views/filament/widgets/fermentation-curve-chart.blade.php` — Chart.js 4.x dual-axis line chart via Alpine.js + CDN. Left Y: Brix (blue, filled). Right Y: Temp °F (red, dashed). Target temp faint reference line. Interactive tooltips. Empty state when no data.

Routes: `GET /fermentations/{roundId}/chart`, `GET /lots/{lotId}/fermentation-chart` — authenticated (any role).

### Deviations
Spec mentioned single endpoint; added lot-level overlay for comparing rounds (natural extension for winemakers).

### Tests (14 tests)
Tier 1: dual-axis structure with metadata, series content chronological; y_left axis resolved (brix/specific_gravity/brix_or_density); metadata (lot_name, target_temp, status); empty series for no entries; lot overview all rounds; tenant isolation → 404; null temp/brix handled gracefully; free_so2 included.
Tier 2: read_only access; unauthenticated 401; API envelope.
Gap: Widget rendering not tested (requires browser/JS); visual appearance depends on Sub-Task 7 demo data.

### Open Questions
- None. Chart visually verifiable once Sub-Task 7 seeds realistic Brix curves.

---

## Sub-Task 6: Fermentation Stuck Detection (Not Implemented)
**Status:** Designed but deferred.

Spec called for: automatic detection when Brix plateaus (last 3 entries within 0.2 of each other). Implementation deferred to future phase — requires background job scheduler (not available in current Phase 3 scope). Sub-Task 7 demo includes a stuck fermentation round for manual testing, but no automation yet.

---

## Sub-Task 7: Lab & Fermentation Demo Data
**Completed:** 2026-03-15 | **Status:** Done

### Key Decisions & Patterns
- Realistic winery data: red fermentation 78–90°F, white 54–58°F; Brix starts ~25, decreases to negative (dry); VA near 0.10 warning on Petite Sirah; ETS Labs as external source on Grenache
- Stuck fermentation example: Co-Ferment round Brix plateaus at 15.8 (last 3 entries within 0.2)
- Threshold seeder in DemoWinerySeeder not ProductionSeeder: thresholds are config setup, not production data
- Widget $isDiscovered = false: FermentationCurveChart is per-round only, not suitable for Dashboard
- Inline Alpine component over Alpine.data(): Livewire v3 SPA nav means alpine:init fires once; Alpine.data() registration won't execute on subsequent navs; inline x-data works regardless
- Redis flush on make fresh: session driver is Redis; migrate:fresh only resets PostgreSQL; old session cookies cause AuthenticateSession to invalidate first login; flushing Redis eliminates stale sessions
- Seeder helpers with EventLogger: demo data should write corresponding events (via EventLogger) to maintain realistic event log; helpers keep seeders readable
- havingRaw() not having(): PostgreSQL doesn't allow column aliases in HAVING; MySQL does

### Files
ProductionSeeder extended:
- seedLabAnalyses(): 7 lots with lab history (Cab Block A richest: 6 dates, 4–5 tests each including pH/TA/VA/free_SO2/alcohol; others sparser). Helper createLabHistory(), labMethod() returns realistic methods (digital pH, titration, enzymatic). All write lab_analysis_entered events.
- seedFermentationData(): 9 rounds (4 active primary, 1 active white primary, 2 completed with ML, 1 completed primary, 1 stuck). Helper createFermentationRound(), createBrixCurve() generates daily entries with linear Brix decrease + temp jitter (±2°F).
- seedSensoryNotes(): 10 notes (Cab 3, Syrah 2, Chardonnay 2 with white descriptors, Grenache 1 @ 91 hundred-point, 2025 Cab 1 no rating, Petite Sirah 1 flagging VA concern). Both five_point and hundred_point scales. Helper createSensoryNote().

DemoWinerySeeder: added $this->call(DefaultLabThresholdsSeeder::class) after ProductionSeeder.

FermentationCurveChart: added protected static bool $isDiscovered = false.

Blade template: rewritten to use inline Alpine component instead of Alpine.data() (which fails under Livewire v3 SPA nav). Chart.js CDN loaded via Livewire @assets directive for proper script lifecycle.

Makefile: `make fresh` now runs `redis-cli FLUSHDB` before `migrate:fresh --seed`.

### Tests (9 tests, 52 assertions)
Tier 1: >30 lab records (multiple test types, sources including ets_labs); fermentation rounds with realistic Brix decrease (first >20, last <5, chronological); ≥2 completed rounds (including ML with confirmation_date); stuck round Brix plateau (last 2 entries within 1.0); ≥8 sensory notes (both scales, ≥1 null rating); event logging (>30 lab_analysis_entered, ≥8 fermentation_round_created, ≥8 sensory_note_recorded events with correct payloads).
Tier 2: realistic temps (white <65°F avg, red >74°F avg).
Gap: Widget rendering requires browser testing.

### Open Questions
- None. Phase 3 complete. All 7 sub-tasks done.
