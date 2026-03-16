# Phase 3 Recap — Lab Analysis & Fermentation Tracking

> Duration: 2026-03-15
> Task files: `03-lab-fermentation.md` | INFO: `03-lab-fermentation.info.md`

---

## Delivered

- Complete lab analysis system: pH, TA, VA, SO2, alcohol, 6+ test types per lot with auto-firing configurable threshold alerts (VA legal limits per 27 CFR 4.21)
- CSV import pipeline: ETS Labs native parser + generic fallback, two-phase preview→commit workflow, fuzzy lot matching, resilient parsing (column reordering, header rows, `<`/`>` prefixes, empty rows, N/A values)
- Fermentation round tracking: daily Brix/temperature/SO2 entries, primary/malolactic round types, lifecycle transitions (active→completed/stuck), ML dryness confirmation
- Dual-axis Chart.js fermentation curve widget (Brix/temperature Y-axes) with target temp reference, Livewire v3 `@assets` + inline Alpine.js
- Sensory/tasting notes: per-record five-point/hundred-point rating scales, multiple tasters, boolean presence flags in events
- Demo data: 30+ lab analyses (7 lots), 9 fermentation rounds (active/completed/stuck), 10 tasting notes, industry-standard thresholds

---

## Architecture Decisions

### Per-Record Rating Scale (not per-tenant config)
Sensory notes store rating_scale per note. Wineries use both 5-point (quick checks) and 100-point (formal panels) — per-record is strictly more flexible. Avoids tenant settings migration.

### Inline Alpine Component (Livewire v3 compatibility)
`Alpine.data()` registration fails under Livewire v3 SPA navigation (`alpine:init` fires once on first load, never again). Inline `x-data="{ ... }"` components work regardless. Chart.js loaded via `@assets` for proper script lifecycle.

### Two-Phase CSV Import
Preview step before commit. Preview includes fuzzy lot name matching (word-split search) for pre-commit correction. Parsers implement `LabCsvParser` interface with `canParse()` priority chain (ETS Labs first, generic fallback).

### Threshold Variety-Specific Override
When both global and variety-specific thresholds exist for same test type/alert level, variety-specific wins. Values exactly at boundary do NOT trigger alerts (VA at 0.12 = at limit, not exceeding).

### Chart-Agnostic JSON API
Chart endpoints return data structured for any charting library — series arrays with consistent keys, axis metadata, entry counts. Mobile apps use native charts with same JSON.

### Fermentation Widget Not Auto-Discovered
Added `$isDiscovered = false` to `FermentationCurveChart` to prevent Filament Dashboard placement. Widgets in `app/Filament/Widgets/` auto-discovered by default; page-specific widgets must opt out.

---

## Deviations from Spec

- **Rating scale per-note instead of per-winery:** Built for flexibility. No downstream impact.
- **Two CSV import endpoints (not one):** `/lab-import/preview` + `/lab-import/commit` for mandatory preview-before-commit workflow.
- **OenoFoss/WineScan parsers deferred:** GenericCSVParser handles their column formats. Dedicated parsers added if quirks emerge.
- **Lot-level chart overlay endpoint added:** Spec had single-round; added `/lots/{lotId}/fermentation-chart` for multi-round comparison (winemakers want primary vs ML side-by-side).

---

## Patterns Established

### Self-Contained Event Payloads
Payloads include human-readable context (lot_name, lot_variety, taster_name) alongside foreign keys. Event stream readable without joins — critical for portability and TTB audit trails. All Phase 3 events follow this. See `references/event-log.md`.

### Boolean Flags for Text Presence in Events
When payloads would contain large text blobs (tasting notes), use boolean flags (`has_nose_notes`, `has_palate_notes`) instead. Full text lives in source record.

### Parser Interface Chain
`LabCsvParser` interface with `canParse()` → `parse()`. Most specific tried first (ETS Labs), generic fallback last. New parsers implement interface and register in `LabImportService`.

### Seeder Helpers with EventLogger
Demo seeders create records AND write events via `EventLogger::log()` for realistic event log. Helper methods (`createLabHistory()`, `createBrixCurve()`, `createSensoryNote()`) keep seeders readable.

### `$isDiscovered = false` for Page-Specific Widgets
Filament auto-discovers all widgets in `app/Filament/Widgets/`. Page-specific widgets (not Dashboard) must set `protected static bool $isDiscovered = false`.

### Redis Flush on Database Reset
`make fresh` runs `redis-cli FLUSHDB` before `migrate:fresh --seed`. Session driver is Redis; stale sessions with old UUIDs cause auth failures after reset.

---

## Known Debt

1. **No Filament Livewire CRUD tests** — impact: low — deferred from Phase 1-2 audit. Requires subdomain test harness.
2. **Token ability endpoint enforcement** — impact: low — deferred from Phase 1-2 audit. Assigned but not enforced via middleware.
3. **`confirmMlDryness()` no API endpoint** — impact: low — service method exists, exposable when ML workflow fully defined.
4. **`nutrients_schedule` JSON unvalidated** — impact: low — stored as nullable JSON array. Wineries have different nutrient protocols.
5. **Chart widget untested via browser** — impact: low — requires real browser execution. Tested via API and data loading, not visual rendering.
6. **Dashboard has no widgets** — impact: medium — placeholder Dashboard.php. Should include active rounds, stuck alerts, recent lab results.

---

## Reference Docs Updated

- `references/event-log.md` — Updated with Phase 3 event types (lab_analysis_entered, fermentation_round_created, fermentation_data_entered, fermentation_completed, sensory_note_recorded)
- `diagrams/production-erd.mermaid` — Updated with lab/fermentation entities
- `diagrams/lab-fermentation-flow.mermaid` — **Created** — Lab and fermentation data flow
- `guides/testing-and-logging.md` — Updated with Phase 3 gotchas (PostgreSQL HAVING alias, havingRaw pattern)

---

## Metrics

| Metric | Phase 1-2 | Phase 3 | Total |
|--------|-----------|---------|-------|
| Tests | 354 | 124 | 478 |
| Test breakdown | — | LabAnalysisTest (15), LabThresholdTest (25), LabImportTest (28), FermentationTest (18), FermentationChartTest (14), SensoryNoteTest (15), LabFermentationDemoDataTest (9) | — |
| Tenant migrations | 7 | 5 new | 12 |
| Filament resources | 8 | 4 new + 1 widget | 12 + 1 |
| API endpoints | 17+ | 15+ new | 32+ |
| Models | 8 | 5 new | 13 |
| Services | 3 | 5 new + 3 parsers | 8 + 3 parsers |
| PHPStan level 6 | 0 | 0 | 0 |
| Pint | 0 | 0 | 0 |
| Demo data | — | 30+ lab analyses, 9 rounds, 10 tasting notes, 17 thresholds | — |

Sub-tasks: 7/7
