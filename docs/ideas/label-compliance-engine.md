# Automated Label Compliance / COLA Validation Engine

> Status: Idea — partially covered by Task 06 (license tracking) but validation logic is missing
> Created: 2026-03-15
> Source: Market research gap analysis
> Priority: High — genuinely differentiated, only possible in an integrated suite

---

## The Problem

TTB COLA (Certificate of Label Approval) requirements mandate specific information on every wine label: brand name, class/type, alcohol content, health warning, sulfite declaration, producer info, and appellation. Wine-specific rules add complexity:

- **75% Varietal Rule** — a wine labeled "Syrah" must contain at least 75% Syrah grapes
- **85% AVA Rule** — a wine labeled with a specific AVA (e.g., "Adelaida District") must contain at least 85% fruit from that AVA
- **95% Vintage Rule** — a wine labeled with a vintage year must contain at least 95% grapes from that vintage
- **California Conjunctive Labeling** — any sub-AVA (e.g., "Adelaida District") must also display the parent AVA ("Paso Robles")

Currently, winemakers manually verify these percentages by pulling blend composition from their cellar software (or notebooks), doing the math, and hoping they got it right before submitting a COLA application. When they get it wrong, it's weeks of back-and-forth with TTB, or worse — a label recall after bottles are already in market.

## What VineSuite Can Do That Nobody Else Can

Because VineSuite tracks lot composition at the event level (grape receiving with variety, vineyard source, AVA, and vintage), and blending operations record exact component percentages, the system already has every data point needed to validate label claims in real time. No competitor can do this because no competitor has both production data and labeling in the same system.

## Proposed Feature

### Real-Time Blend Compliance Dashboard

When a winemaker is building a blend (existing blend trial workflow in Task 02), show a live compliance panel:

```
┌─────────────────────────────────────────┐
│ Label Compliance Check                  │
│                                         │
│ Varietal: "Syrah"                       │
│   78.3% Syrah ✅ (needs 75%)           │
│   14.2% Grenache                        │
│   7.5% Mourvèdre                        │
│                                         │
│ AVA: "Paso Robles, Adelaida District"   │
│   82.1% Adelaida District ❌ (needs 85%)│
│   17.9% Willow Creek District           │
│   Action: Add 12 gal from Lot PR-24-07 │
│   to reach 85.0%                        │
│                                         │
│ Vintage: "2024"                         │
│   100% 2024 ✅ (needs 95%)             │
│                                         │
│ Conjunctive Label: Required ✅          │
│   "Paso Robles" must appear with        │
│   "Adelaida District"                   │
└─────────────────────────────────────────┘
```

### Key Behaviors

1. **Auto-calculates from blend composition** — no manual data entry. As lots are added/removed from a blend, percentages update live.
2. **Validates against target label claims** — winemaker specifies intended label (varietal name, AVA, vintage) and the system checks compliance.
3. **Suggests remediation** — when a threshold isn't met, suggest which lots could be added to fix it. "Add 12 gallons from Lot PR-24-07 (Adelaida District Syrah) to reach the 85% AVA threshold."
4. **Tracks AVA at the sub-block level** — critical for Paso Robles' 11 sub-AVAs. Each grape receiving lot should capture the source AVA.
5. **Handles multi-vintage blends** — NV (non-vintage) wines are common. If vintage isn't claimed on the label, the 95% rule doesn't apply.
6. **Stores compliance snapshot at bottling** — when a blend is finalized and bottled, lock the compliance state as a permanent record tied to that SKU. This is audit documentation.

## Data Model Changes

### Additions to Existing Models

**Lot** — needs `source_ava` (string, nullable) and `vintage_year` (integer, nullable) if not already present. Check current lot model.

**BlendTrial / BlendTrialComponent** — already tracks components and percentages. May need `source_ava` rollup calculation.

### New Models

**LabelProfile** — `id`, `sku_id` (nullable, linked after bottling), `blend_trial_id` (nullable), `varietal_claim` (string), `ava_claim` (string, nullable), `sub_ava_claim` (string, nullable), `vintage_claim` (integer, nullable), `alcohol_claim` (decimal), `other_claims` (JSON), `compliance_status` (passing/failing/unchecked), `compliance_snapshot` (JSONB — full breakdown at time of lock), `locked_at` (timestamp, nullable), `created_at`, `updated_at`

**LabelComplianceCheck** — `id`, `label_profile_id`, `rule_type` (varietal_75/ava_85/vintage_95/conjunctive_label), `threshold` (decimal), `actual_percentage` (decimal), `passes` (boolean), `details` (JSONB), `checked_at`

## Service

**LabelComplianceService** — given a blend composition and a label profile, calculate all rule checks and return pass/fail with details and remediation suggestions.

## Where This Lives in the Pipeline

This could be absorbed into Task 06 (TTB Compliance) as a new sub-task, or into Task 02 (Production Core) as an extension of the blend trial workflow. It probably belongs closer to production since it's used during the blending process, with the compliance record feeding into Task 06's audit trail.

Recommendation: New sub-task in Task 06, with a UI component embedded in the blend trial page from Task 02.

## Dependencies

- Task 02 (Production Core) — blend trial model and workflow
- Task 04 (Inventory) — SKU linkage after bottling
- Grape receiving must capture variety, AVA, and vintage per lot (check if this exists)

## Paso Robles Relevance

This feature is disproportionately valuable in Paso Robles because:
- 11 sub-AVAs with frequent cross-AVA blending
- Blending-forward culture (GSM, Bordeaux blends, cross-category experiments)
- California conjunctive labeling law adds a requirement no other state has
- Justin Winery sources from 10 of 11 sub-AVAs — imagine the compliance math

Building this well is a "show this to a Paso Robles winemaker and they'll sign up on the spot" feature.
