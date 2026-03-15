# Phase 3 Recap — Lab Analysis & Fermentation Tracking

> Duration: 2026-03-15 → 2026-03-15
> Task files: `03-lab-fermentation.md`
> INFO files: `03-lab-fermentation.info.md`

---

## What Was Delivered
- A complete lab analysis system: record pH, TA, VA, SO2, alcohol, and 6 other test types per lot, with configurable threshold alerts that fire automatically on each new entry — including VA legal limits per 27 CFR 4.21
- A CSV import pipeline for external lab results (ETS Labs native parser + generic fallback) with a two-phase preview→commit workflow, fuzzy lot name matching, and resilient parsing (handles column reordering, extra header rows, `<`/`>` prefixed values, empty rows)
- Fermentation round tracking with daily Brix/temperature/SO2 entries, primary and malolactic round types, lifecycle transitions (active→completed/stuck), and ML dryness confirmation
- A dual-axis Chart.js fermentation curve widget on the round detail page (Brix on left Y, temperature on right Y) with target temperature reference line, built via Livewire v3 `@assets` + inline Alpine.js
- Sensory/tasting notes with per-record five-point or hundred-point rating scale, multiple tasters per lot, and boolean presence flags in event payloads
- A realistic demo dataset: 30+ lab analyses across 7 lots, 9 fermentation rounds (active, completed, stuck, white vs red temperature ranges), 10 tasting notes, and industry-standard lab thresholds seeded

## Architecture Decisions Made

### Per-Record Rating Scale over Per-Tenant Config (Sub-Task 6)
Sensory notes store the rating scale (five_point/hundred_point) per note rather than per winery. A winery might use 5-point for quick barrel checks and 100-point for formal panels — per-record is strictly more flexible and avoids a tenant settings migration. See `03-lab-fermentation.info.md` Sub-Task 6.

### Inline Alpine Component for Livewire v3 Compatibility (Sub-Task 5/7)
`Alpine.data()` registration via `document.addEventListener('alpine:init', ...)` fails under Livewire v3 SPA navigation because `alpine:init` fires once on first page load and never again. Inline `x-data="{ ... }"` components work regardless of navigation timing. Chart.js loaded via Livewire v3 `@assets` directive for proper script lifecycle. See `03-lab-fermentation.info.md` Sub-Task 7.

### Two-Phase CSV Import (Sub-Task 3)
External lab CSV imports require a preview step before commit. The preview includes fuzzy lot name matching with word-split search so users can correct mismatches before data enters the system. Parsers implement `LabCsvParser` interface with `canParse()` priority chain (ETS Labs first, generic fallback).

### Threshold Variety-Specific Override (Sub-Task 2)
When both global and variety-specific thresholds exist for the same test type and alert level, the variety-specific one wins. Values exactly at the boundary do NOT trigger alerts (VA at 0.12 is AT the legal limit, not exceeding it).

### Chart-Agnostic JSON API (Sub-Task 5)
Chart endpoints return data structured for immediate consumption by any charting library — series arrays with consistent keys, axis configuration metadata, and entry count. Mobile apps will use native charts with the same JSON.

### Fermentation Widget Not Auto-Discovered (Sub-Task 7)
Added `$isDiscovered = false` to `FermentationCurveChart` to prevent Filament from placing a per-round detail widget on the main Dashboard. Widgets in `app/Filament/Widgets/` are auto-discovered by default — page-specific widgets must opt out.

## Deviations from Original Spec
- **Rating scale per-note instead of per-winery** — Planned as "configurable per winery," built per-note for flexibility. No downstream impact.
- **Two CSV import endpoints instead of one** — Spec listed `POST /lab-import`; implementation splits into `/lab-import/preview` and `/lab-import/commit` for the mandatory preview-before-commit workflow.
- **OenoFoss/WineScan parsers deferred** — GenericCSVParser handles their column formats. Dedicated parsers can be added if format-specific quirks emerge.
- **Lot-level chart overlay endpoint added** — Spec had single-round chart only; added `GET /lots/{lotId}/fermentation-chart` for multi-round comparison since winemakers want primary vs ML side by side.

## Patterns Established

### Self-Contained Event Payloads
Event payloads include human-readable context (lot_name, lot_variety, taster_name) alongside foreign keys. Makes the event stream readable without joins — critical for data portability and TTB audit trails. All Phase 3 events follow this pattern. Detailed in `references/event-log.md`.

### Boolean Flags for Text Presence in Events
When event payloads would contain large text blobs (tasting notes), use boolean presence flags (`has_nose_notes`, `has_palate_notes`) instead. The full text is in the source record.

### Parser Interface Chain
`LabCsvParser` interface with `canParse()` → `parse()` pattern. Most specific parser tried first (ETS Labs), generic fallback last. New parsers implement the interface and register in `LabImportService`.

### Seeder Helpers with EventLogger
Demo data seeders create records AND write corresponding events via `EventLogger::log()` to maintain a realistic event log. Helper methods (`createLabHistory()`, `createBrixCurve()`, `createSensoryNote()`) keep seeders readable.

### $isDiscovered = false for Page-Specific Widgets
Filament auto-discovers all widgets in `app/Filament/Widgets/`. Widgets intended only for specific resource pages (not Dashboard) must set `protected static bool $isDiscovered = false`.

### Redis Flush on Database Reset
`make fresh` now runs `redis-cli FLUSHDB` before `migrate:fresh --seed`. Session driver is Redis, and stale sessions with old user UUIDs cause authentication failures after database reset.

## Known Debt
1. **No Filament Livewire CRUD tests** — impact: low — deferred from Phase 1-2 audit. Requires subdomain test harness for Filament resource testing.
2. **Token ability endpoint enforcement** — impact: low — deferred from Phase 1-2 audit. Token abilities assigned but not enforced via middleware.
3. **`confirmMlDryness()` has no API endpoint** — impact: low — service method exists, can be exposed when ML workflow is fully defined.
4. **`nutrients_schedule` JSON unvalidated** — impact: low — stored as nullable JSON array, no schema enforcement. Wineries have very different nutrient protocols.
5. **Chart widget untested via browser** — impact: low — Chart.js rendering requires real browser execution. Tested via API endpoints and data loading, not visual rendering.
6. **Dashboard has no widgets** — impact: medium — `Dashboard.php` is a placeholder. A proper overview widget (active rounds, stuck alerts, recent lab results) should be built in a polish phase.

## Reference Docs Updated
- `references/event-log.md` — Updated with Phase 3 event types (lab_analysis_entered, fermentation_round_created, fermentation_data_entered, fermentation_completed, sensory_note_recorded)
- `diagrams/production-erd.mermaid` — Updated with lab/fermentation entities
- `diagrams/lab-fermentation-flow.mermaid` — **Created** — Data flow diagram for lab and fermentation operations
- `guides/testing-and-logging.md` — Updated with Phase 3 gotchas (PostgreSQL HAVING alias, havingRaw pattern)

## Metrics
- Sub-tasks completed: 7/7
- Test count: 478 total (up from 354 at end of Phase 2), 124 new in Phase 3
- Phase 3 test breakdown: LabAnalysisTest (15), LabThresholdTest (25), LabImportTest (28), FermentationTest (18), FermentationChartTest (14), SensoryNoteTest (15), LabFermentationDemoDataTest (9)
- Tenant migrations: 5 new (lab_analyses, lab_thresholds, fermentation_rounds, fermentation_entries, sensory_notes)
- Filament resources: 4 new (LabAnalysis, LabThreshold, FermentationRound, SensoryNote) + 1 widget (FermentationCurveChart)
- API endpoints: 15+ new (lab CRUD, threshold CRUD, import preview/commit, fermentation CRUD + lifecycle, chart, sensory CRUD)
- Models: 5 new (LabAnalysis, LabThreshold, FermentationRound, FermentationEntry, SensoryNote)
- Services: 5 new (LabAnalysisService, LabThresholdChecker, LabImportService, FermentationService, SensoryNoteService) + 3 parsers (ETSLabsParser, GenericCSVParser, LabCsvParser interface)
- PHPStan: level 6, zero errors
- Pint: zero style issues
- Demo data: 30+ lab analyses, 9 fermentation rounds, 10 sensory notes, 17 default thresholds
