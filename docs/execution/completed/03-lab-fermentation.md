# Lab Analysis & Fermentation Tracking — COMPLETED

> **Status: COMPLETED** — This phase is historical. Agents should use phase recaps instead.

## Quick Reference

**Phase:** 2
**Dependencies:** Foundation, Production Core (lots).
**Core accomplishments:** Lab analysis with threshold alerts, fermentation tracking (primary + ML), fermentation curve charts, sensory notes.

---

## Sub-Tasks (Completed)

1. **Lab analysis entry** — LabAnalysis model with test_type (pH/TA/VA/free_SO2/total_SO2/residual_sugar/alcohol/malic_acid/glucose_fructose/turbidity/color). Manual or CSV import. Writes `lab_analysis_entered` event. Chronological history per lot.

2. **Threshold alerts** — LabThreshold configurable per test type, optionally per variety. Auto-check on new entry. Default thresholds seeded (VA <0.12 g/100ml table, <0.14 dessert). Alerts at warning/critical levels.

3. **External lab CSV import** — ETS Labs, OenoFoss, Wine Scan CSV parsers. Fuzzy lot matching. Preview before import. Resilient to column reordering, extra headers.

4. **Fermentation rounds** — Primary and malolactic tracked separately. FermentationRound: inoculation_date, yeast_strain, ML bacteria, target_temp, nutrients_schedule (JSON), status (active/completed/stuck). FermentationEntry: daily temp, Brix/SG (stored which type), free SO2, notes.

5. **Fermentation curve chart** — Dual-axis: Brix (left Y), temp (right Y), date (X). Interactive. Viewable per round. API endpoint returns chart-ready JSON (for mobile).

6. **Sensory/tasting notes** — SensoryNote: lot, taster, date, rating (1-5 or 100pt, configurable per winery), nose/palate/overall notes. Viewable in lot timeline.

7. **Lab + fermentation demo data** — Realistic lab histories (pH, TA, VA, SO2), 2+ lots with active fermentation, Brix curves (24-26 down to -1/-2 over 7-21d), temps 55-65°F (whites), 75-90°F (reds).

---

## Remaining Gotchas

- **VA legal limits:** 0.12 g/100ml table wine, 0.14 dessert. Compliance implication—must alert correctly.
- **Brix vs. SG:** Different measurements. Store which type. Primary tracks Brix decrease; ML tracks malic acid.
- **Lab CSV formats change:** Parser must be resilient. Always preview before import.
- **Fermentation curve:** Simple first version (will be native charts in mobile apps later).

---

## Critical Tests

- VA threshold alerts fire at, below, and above legal limit.
- Brix/SG conversion accuracy.
- CSV parsing resilience (reordering, extra headers).
