# Water Usage & SGMA Compliance Tracking

> **🟡 TRIAGED → Phase 7 (extend Task 17)** — Deferred to Phase 7 as sub-tasks within Vineyard Management. Not yet written into the Task 17 spec. See Task 17's "Ideas to Evaluate" section.
> Created: 2026-03-15
> Source: Market research gap analysis
> Priority: Medium — beachhead-critical for Paso Robles

---

## The Problem

The Paso Robles Groundwater Basin is classified as "critically overdrafted" under California's Sustainable Groundwater Management Act (SGMA). Irrigated agriculture uses over 90% of pumped water. Well metering is required. A voluntary fallowing program was approved in February 2026, and regulatory pressure is only increasing.

SIP Certified covers 43,600+ acres in the Paso Robles region. These wineries need to document water usage per vineyard block for certification audits. There is no winery software that tracks this.

## Why This Matters for VineSuite

For a product headquartered in Paso Robles targeting Paso Robles wineries as its beachhead market, water compliance is not optional — it's table stakes for the audience. A Paso Robles grower who hears "we built this for Paso Robles wineries" will immediately ask "does it track my water usage?" If the answer is no, the claim rings hollow.

This is also a feature that grows in importance over time. SGMA compliance requirements will only get stricter, and more California basins are being classified as critically overdrafted. Building it now positions VineSuite for the regulatory trajectory.

## Proposed Feature

### Extension to Task 17 (Vineyard Management)

Add water tracking as sub-tasks within the existing vineyard module:

**Water Usage Logging**
- Per-block irrigation events: date, duration, volume (gallons or acre-feet), method (drip/sprinkler/flood), source (well/municipal/recycled)
- Well meter readings: date, reading, calculated usage since last reading
- Rainfall logging (manual or weather API integration)
- Seasonal water budget per block (target vs. actual)

**SGMA Compliance Reporting**
- Annual water usage report per block and total
- Comparison against basin allocation (if applicable)
- Fallowing documentation (blocks taken out of production for water credits)
- Export format compatible with GSA (Groundwater Sustainability Agency) reporting requirements

**Sustainability Certification Support**
- Water usage data feeds into SIP Certified, CSWA (California Sustainable Winegrowing Alliance), and LODI RULES audit documentation
- Water efficiency metrics: gallons per ton of fruit, gallons per acre, seasonal totals
- Year-over-year trend tracking for continuous improvement documentation

### Data Model Additions

**IrrigationEvent** — `id`, `block_id`, `date`, `duration_hours`, `volume_gallons`, `method` (drip/sprinkler/flood/none), `source` (well/municipal/recycled/rainfall), `notes`, `created_at`

**WellMeterReading** — `id`, `well_id` (string identifier), `reading_date`, `meter_reading` (decimal), `calculated_usage` (decimal, derived from delta between readings), `notes`, `created_at`

**WaterBudget** — `id`, `block_id`, `season_year`, `budgeted_gallons`, `actual_gallons` (computed from irrigation events), `rainfall_gallons` (computed from weather data), `created_at`, `updated_at`

### Events

- `irrigation_logged` — block_id, volume, method, source
- `well_meter_read` — well_id, reading, calculated_usage
- `water_budget_set` — block_id, season, budgeted_gallons

## Weather API Integration

A lightweight weather integration (e.g., Open-Meteo, free tier) could auto-log rainfall at the vineyard's location and provide evapotranspiration estimates for smarter irrigation scheduling. This doesn't need to be a Phase 7 deliverable — it could be a Phase 8 enhancement — but the data model should accommodate it from the start.

## Timing

This should be absorbed into Task 17 (Vineyard Management) as 2–3 additional sub-tasks. It doesn't change the phase (still Phase 7) but adds scope. The data models are simple and the UI is straightforward CRUD — the value is in having the data connected to the rest of the vineyard and production data.

## Open Questions

- What format does the Paso Robles Basin GSA (Paso Robles Basin Water District) require for compliance reporting? Need to research before building the export.
- Should water data be part of the community insights / benchmarking program? (Sensitive — water usage is politically charged in Paso Robles.)
- Is there demand for this outside Paso Robles in v1, or is it a beachhead-only feature initially?
