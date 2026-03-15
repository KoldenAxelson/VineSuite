# Testsuite Audit — Phase 3 (Lab & Fermentation)

> Audited: 2026-03-15
> Scope: 7 test files in `tests/Feature/Lab/`, 124 tests, 478 total suite
> Standard: `docs/guides/testing-and-logging.md` tier system
> Prior audit: `testsuite-audit-phase-1-2.md`

---

## Verdict

The Phase 3 test suite is **strong on the critical compliance and event logging paths** — VA threshold enforcement at the 27 CFR 4.21 legal limit is thoroughly tested with boundary precision, every event-writing operation has payload verification, and tenant isolation is covered in every file. The CSV import tests are notably robust for edge cases (column reordering, title rows, `<`/`>` prefixes, N/A values). The main gaps are in lifecycle edge cases (can't add entries to a completed round, can't complete twice) and the continued deferral of Filament Livewire CRUD tests from the Phase 1-2 audit.

---

## Tier 1 Audit (Must Have)

### Event Log Writes — PASS (Strong)

Every event-writing operation has a dedicated test verifying the event was written with correct `operation_type` and payload structure:

| Operation Type | Tested In | Payload Fields Verified |
|---|---|---|
| `lab_analysis_entered` | LabAnalysisTest, LabImportTest | lot_name, lot_variety, test_type, value, unit, method, analyst, test_date |
| `fermentation_round_created` | FermentationTest | lot_name, lot_variety, fermentation_type, yeast_strain, inoculation_date |
| `fermentation_data_entered` | FermentationTest | temperature, brix_or_density, measurement_type, entry_date |
| `fermentation_completed` | FermentationTest | status, completion_date, total_entries |
| `sensory_note_recorded` | SensoryNoteTest | lot_name, lot_variety, taster_name, date, rating, rating_scale, has_*_notes flags |

**Note:** LabThreshold CRUD does not write events — this is by design. Thresholds are configuration data, not business operations. Activity is tracked via the `LogsActivity` trait, not the event log. See `03-lab-fermentation.info.md` Sub-Task 2 Key Decisions.

**Import batch flag:** LabImportTest verifies that CSV-imported analyses include `import_batch: true` in the event payload, distinguishing bulk imports from manual entries in the event stream.

### VA Compliance Threshold — PASS (Excellent)

LabThresholdTest covers the full boundary spectrum for VA at the 27 CFR 4.21 legal limit:

- VA at exactly 0.12 g/100mL → **no alert** (at limit, not exceeding)
- VA at 0.121 g/100mL → **critical alert fires**
- VA at 0.15 g/100mL → **both warning and critical fire**
- VA at 0.05 g/100mL → **no alerts** (well within range)
- VA below minimum (free_SO2 below 15 mg/L) → **below-minimum alert**

This is the most important compliance test in the system. If this breaks, a winery could unknowingly ship non-compliant wine.

### Tenant Isolation — PASS (Complete)

Every test file verifies schema isolation:
- LabAnalysisTest: cross-tenant lab data access prevention
- LabThresholdTest: cross-tenant threshold access prevention
- FermentationTest: cross-tenant fermentation data access prevention
- FermentationChartTest: cross-tenant chart data returns 404
- SensoryNoteTest: cross-tenant sensory note access prevention
- LabImportTest: cross-tenant lot matching prevention during import

### Variety-Specific Threshold Override — PASS

LabThresholdTest verifies that when both a global threshold (variety=null) and a variety-specific threshold exist for the same test type and alert level, the variety-specific one takes precedence.

---

## Tier 2 Audit (Should Have)

### API Endpoint Contracts — PASS

All 7 test files verify the standard API envelope format (`data`, `meta`, `errors`). HTTP status codes tested: 200, 201, 403, 404, 422.

### RBAC — PASS (with minor asymmetry)

| Operation | Allowed Roles Tested | Denied Roles Tested |
|---|---|---|
| Create lab analysis | cellar_hand (201) | read_only (403) |
| Create threshold | winemaker (201) | cellar_hand (403), read_only (403) |
| Update/Delete threshold | winemaker | cellar_hand, read_only |
| Create fermentation round | winemaker (201) | cellar_hand (403), read_only (403) |
| Add fermentation entry | cellar_hand (201) | read_only (403) |
| Import lab CSV | winemaker (200) | cellar_hand (403), read_only (403) |
| Create sensory note | winemaker (201) | cellar_hand (403), read_only (403) |
| View/list (all resources) | all roles (200) | unauthenticated (401) |

Minor asymmetry: Some tests verify the allowed role but rely on other tests to verify the denied role. All operations have at least one allowed and one denied test.

### Validation — PASS

Validation tests cover: missing required fields, invalid enum values (test_type, fermentation_type, measurement_type, rating_scale, alert_level), nullable fields accepted, backdated dates accepted for historical imports.

### CSV Import Edge Cases — PASS (Robust)

LabImportTest covers: ETS Labs format with distinctive headers, generic CSV fallback, column reordering, extra title rows, empty rows, N/A values, `<`/`>` prefixed values (e.g., `<0.5` → 0.5), non-numeric value warnings, headers-only file, exact lot name matching, fuzzy word-split matching, non-matching lots with empty suggestions.

### Fermentation Lifecycle — PASS (with gaps)

Full lifecycle tested end-to-end: create round → 7 daily entries (Brix decreasing) → complete round. Verifies 7 `fermentation_data_entered` events + 1 `fermentation_completed` event. ML fermentation tested with bacteria strain and null yeast_strain.

### Chart Data Format — PASS

FermentationChartTest covers: dual-axis structure, series content (date/temp/brix/measurement_type), chronological sort, y-axis label resolution (brix vs specific_gravity), round metadata, empty series, lot overview (multi-round overlay), null value handling, free_so2 inclusion.

---

## Gaps Identified

### Should Address in Phase 4 (Medium Priority)

| Gap | Severity | Details |
|-----|----------|---------|
| Fermentation lifecycle guard: completed round | Medium | No test verifies that adding entries to a completed round is rejected. If the guard is missing, stale data could enter completed rounds. |
| Fermentation lifecycle guard: double completion | Medium | No test verifies that completing an already-completed round fails gracefully. |
| Partial CSV import failure | Medium | No test for the scenario where 2 of 5 records in a commit batch fail and 3 succeed. Only 100% success tested. |
| Sensory note list sort order | Low | Test comment says "most recent first" but doesn't assert on ordering of returned records. |
| Stuck detection logic | Low | FermentationTest tests that `markStuck` changes status, but doesn't test any automated stuck detection (Brix plateau). This is because stuck detection is manual in the current implementation — winemaker marks it. Not a gap per se, but worth noting. |

### Deferred from Phase 1-2 Audit (Still Outstanding)

| Item | Status | Notes |
|------|--------|-------|
| Token ability endpoint enforcement | Not addressed | Token abilities assigned at login but not enforced via middleware. Requires implementation + tests together. |
| Filament Livewire CRUD tests | Not addressed | Requires subdomain test harness for Livewire rendering. No Phase 3 resources are tested through Filament. |

These remain non-blocking. Token enforcement is an implementation gap (no middleware exists), not a test gap. Filament CRUD is a test infrastructure investment.

---

## Tests That Could Be Stronger

| File | Test | Issue |
|------|------|-------|
| LabFermentationDemoDataTest | "seeds sensory tasting notes" | Checks count and rating scale presence but not payload structure of events. Acceptable for a seeder test. |
| SensoryNoteTest | "lists sensory notes for a lot" | Doesn't assert sort order despite the endpoint ordering by date desc. |
| FermentationChartTest | "handles entries with null temperature or brix" | Asserts series count but doesn't explicitly verify nulls are preserved as `null` in JSON (vs 0 or empty string). |

These are minor — the tests work, they just could assert more precisely.

---

## What's Working Well

**VA compliance testing is production-quality.** The boundary tests at 0.12 vs 0.121 g/100mL demonstrate the kind of precision needed for regulatory compliance. This is the test a TTB auditor would want to see.

**CSV import resilience is well-tested.** 28 tests cover the parsing pipeline including real-world ETS Labs format quirks. The two-phase preview→commit workflow is tested at both stages with proper event verification on commit.

**Self-contained event payloads are verified everywhere.** Every event test checks for human-readable fields (lot_name, lot_variety, taster_name) alongside foreign keys, ensuring the event stream remains readable without joins.

**Full fermentation lifecycle is tested end-to-end.** The lifecycle test creates a round, adds 7 daily entries with decreasing Brix, completes the round, and verifies all 8 events were written. This catches integration bugs that unit tests would miss.

---

## Metrics

| Metric | Phase 1-2 | Phase 3 | Total |
|--------|-----------|---------|-------|
| Test files | 19 | 7 | 26 |
| Tests | 354 | 124 | 478 |
| Tier 1 event types tested | 12 | 5 | 17 |
| Tier 1 tenant isolation files | 12 | 6 | 18 |
| PHPStan errors | 0 | 0 | 0 |
| Pint issues | 0 | 0 | 0 |

## Recommendation

The Phase 3 test suite is ready for shipping. The fermentation lifecycle guard tests (completed round rejection, double completion) are the most impactful additions for Phase 4 — these protect against data integrity issues that could affect TTB reporting. The Filament Livewire CRUD tests remain the largest systematic gap and should be planned as dedicated infrastructure work rather than squeezed into a feature phase.
