# Testsuite Audit — Phase 3 (Lab & Fermentation)

> Audited: 2026-03-15
> Scope: 7 test files, 124 tests, 478 total suite
> Standard: `docs/guides/testing-and-logging.md` tier system

---

## Verdict

**Strong on critical compliance and event logging paths.** VA threshold enforcement at 27 CFR 4.21 thoroughly tested at boundary precision. CSV import handles edge cases robustly. Main gaps: lifecycle edge cases (no test for adding entries to completed round, double completion) and deferred Filament Livewire CRUD tests.

---

## Tier 1 Audit (Must Have)

### Event Log Writes — PASS (Strong)

| Operation Type | Tested In | Payload Fields Verified |
|---|---|---|
| `lab_analysis_entered` | LabAnalysisTest, LabImportTest | lot_name, lot_variety, test_type, value, unit, method, analyst, test_date |
| `fermentation_round_created` | FermentationTest | lot_name, lot_variety, fermentation_type, yeast_strain, inoculation_date |
| `fermentation_data_entered` | FermentationTest | temperature, brix_or_density, measurement_type, entry_date |
| `fermentation_completed` | FermentationTest | status, completion_date, total_entries |
| `sensory_note_recorded` | SensoryNoteTest | lot_name, lot_variety, taster_name, date, rating, rating_scale, has_*_notes |

**Note:** LabThreshold CRUD doesn't write events (configuration data, not operations). Activity tracked via LogsActivity trait. CSV imports include `import_batch: true` flag.

### VA Compliance Threshold — PASS (Excellent)

LabThresholdTest covers full 27 CFR 4.21 boundary spectrum:
- VA = 0.12 → no alert (at limit)
- VA = 0.121 → critical alert fires
- VA = 0.15 → both warning and critical fire
- VA = 0.05 → no alerts (within range)
- Free SO2 < 15 mg/L → below-minimum alert

**Most important compliance test in system.** If this breaks, winery could ship non-compliant wine.

### Tenant Isolation — PASS (Complete)

Every test file verifies schema isolation: cross-tenant lab data, thresholds, fermentation, chart data, sensory notes, lot matching during import.

### Variety-Specific Threshold Override — PASS

Variety-specific threshold takes precedence over global threshold for same test type/alert level.

---

## Tier 2 Audit (Should Have)

### API Endpoint Contracts — PASS

All 7 files verify standard envelope format. HTTP status codes tested: 200, 201, 403, 404, 422.

### RBAC — PASS (with minor asymmetry)

| Operation | Allowed | Denied |
|---|---|---|
| Create lab analysis | cellar_hand (201) | read_only (403) |
| Create threshold | winemaker (201) | cellar_hand, read_only (403) |
| Update/Delete threshold | winemaker | cellar_hand, read_only |
| Create fermentation round | winemaker (201) | cellar_hand, read_only (403) |
| Add fermentation entry | cellar_hand (201) | read_only (403) |
| Import lab CSV | winemaker (200) | cellar_hand, read_only (403) |
| Create sensory note | winemaker (201) | cellar_hand, read_only (403) |
| View/list (all) | all roles (200) | unauthenticated (401) |

### Validation — PASS

Covers: required fields, invalid enums (test_type, fermentation_type, measurement_type, rating_scale, alert_level), nullable fields, backdated dates.

### CSV Import Edge Cases — PASS (Robust)

LabImportTest covers 28+ edge cases: ETS Labs format, generic CSV fallback, column reordering, title rows, empty rows, N/A values, `<`/`>` prefixes, non-numeric warnings, headers-only, fuzzy lot matching, non-matching lots.

### Fermentation Lifecycle — PASS (with gaps)

Full lifecycle: create → 7 daily entries (Brix decreasing) → complete. Verifies 8 events total. ML fermentation tested with bacteria strain.

**Gaps:** No test for adding entries to completed round or completing twice.

### Chart Data Format — PASS

Dual-axis structure, series content (date/temp/brix/measurement_type), chronological sort, y-axis labels, round metadata, null handling, free_so2 inclusion, multi-round overlay.

---

## Gaps Identified

### Should Address in Phase 4 (Medium)

| Gap | Severity | Details |
|-----|----------|---------|
| Fermentation lifecycle guard: completed round | Medium | No test verifies rejection of entries to completed rounds. |
| Fermentation lifecycle guard: double completion | Medium | No test verifies completing already-completed round fails. |
| Partial CSV import failure | Medium | No test for 2-of-5 records failing; only 100% success tested. |
| Sensory note list sort order | Low | Test says "most recent first" but doesn't assert on returned order. |
| Stuck detection logic | Low | Tests markStuck changes status, but not automated stuck detection (manual in current implementation). |

### Deferred from Phase 1-2 Audit

- **Token ability endpoint enforcement** — No middleware exists. Implementation gap, not test gap.
- **Filament Livewire CRUD tests** — Requires subdomain test harness. Test infrastructure investment.

---

## Tests That Could Be Stronger

| File | Test | Issue |
|------|------|-------|
| LabFermentationDemoDataTest | "seeds sensory tasting notes" | Checks count/scale but not event payload structure. |
| SensoryNoteTest | "lists sensory notes for a lot" | Doesn't assert sort order (date desc). |
| FermentationChartTest | "handles entries with null temperature or brix" | Doesn't verify nulls preserved as null (not 0 or empty string). |

---

## What's Working Well

- **VA compliance testing is production-quality.** Boundary tests at 0.12 vs 0.121 g/100mL show precision needed for regulatory compliance.
- **CSV import resilience well-tested.** 28 tests cover parsing quirks including real ETS Labs format.
- **Self-contained event payloads verified everywhere.** Human-readable fields (lot_name, taster_name) alongside foreign keys.
- **Full fermentation lifecycle tested end-to-end.** Creates round, 7 entries, completes, verifies all 8 events. Catches integration bugs.

---

## Metrics

| Metric | Phase 1-2 | Phase 3 | Total |
|--------|-----------|---------|-------|
| Test files | 19 | 7 | 26 |
| Tests | 354 | 124 | 478 |
| Tier 1 event types | 12 | 5 | 17 |
| Tier 1 tenant isolation files | 12 | 6 | 18 |
| PHPStan level 6 errors | 0 | 0 | 0 |
| Pint issues | 0 | 0 | 0 |

## Recommendation

Phase 3 ready for shipping. Fermentation lifecycle guard tests (completed round rejection, double completion) are most impactful for Phase 4. Filament Livewire CRUD tests remain largest gap — plan as dedicated infrastructure work.
