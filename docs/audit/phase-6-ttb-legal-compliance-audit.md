# Phase 6 — TTB Legal Compliance Audit

> Audited: 2026-03-16
> Source: TTB P 5120.17sm Color Coded Sample Report (01/2018), TTB.gov guidance, 27 CFR Part 24

---

## CRITICAL FINDING 1: Alcohol Threshold is Wrong (Legal Risk: HIGH)

**What the law says:**
The Craft Beverage Modernization Act (CBMA), effective January 1, 2018, changed the wine tax class threshold from 14% to **16%** alcohol by volume. The current TTB Form 5120.17sm (01/2018) columns are:

| Column | Classification |
|--------|---------------|
| (a) | Not Over 16 Percent |
| (b) | Over 16 to 21 Percent (Inclusive) |
| (c) | Over 21 to 24 Percent (Inclusive) |
| (d) | Artificially Carbonated Wine |
| (e) | Sparkling Wine |
| (f) | Hard Cider |

**What our code says:**
`WineTypeClassifier` uses `TABLE_WINE_MAX_ALCOHOL = 14.0`:
- ≤14% → table
- >14% to ≤24% → dessert

**Impact:** A wine at 15% ABV would be classified as "dessert" in our system but belongs in column (a) "Not Over 16 Percent" on the actual TTB form. This would cause incorrect tax calculations and reporting. The 14% threshold is the **pre-2018 law**.

**Fix required:** Change `TABLE_WINE_MAX_ALCOHOL` from `14.0` to `16.0`. Add a second threshold at 21% for the middle column. Rename the wine type categories to match the form columns.

---

## CRITICAL FINDING 2: Form Structure Does Not Match TTB Form 5120.17 (Legal Risk: HIGH)

**What the actual form looks like:**
TTB Form 5120.17 has **one main Part I** with two sections:

- **Part I, Section A — Bulk Wines** (32 lines): Lines 1-11 are increases (on-hand, produced by fermentation, sweetening, wine spirits, blending, amelioration, received in bond, dumped to bulk, inventory gains). Line 12 is TOTAL. Lines 13-30 are decreases (bottled, removed taxpaid, transfers, distilling material, vinegar, sweetening, wine spirits, blending, amelioration, effervescent, testing, write-ins, losses, inventory losses). Line 31 is ON HAND END. Line 32 is TOTAL. Lines 12 and 32 must balance.

- **Part I, Section B — Bottled Wines** (21 lines): Similar structure for bottled wine inventory.

- **Parts II-IX** cover: distilled spirits (Part III), materials received (Part IV), distilling material/vinegar (Part VI), fermenters (Part VII), nonbeverage wines (Part VIII), special natural wines (Part IX).

**What our code does:**
Our implementation uses a simplified 5-Part structure:
- Part I: Balance summary
- Part II: Wine produced
- Part III: Wine received
- Part IV: Wine removed
- Part V: Losses

**Impact:** The data model and line item numbering don't correspond to the actual form. A TTB auditor reviewing the generated report would find line numbers and categories that don't match Form 5120.17. The report cannot be filed as-is.

**Fix required:** Restructure to match the actual form layout — Part I Section A (bulk wines, 32 lines) and Part I Section B (bottled wines, 21 lines). Production methods need their own specific lines (fermentation, sweetening, wine spirits, blending, amelioration are all separate lines, not grouped).

---

## CRITICAL FINDING 3: Rounding Should Use Whole Gallons (Legal Risk: MEDIUM)

**What TTB says:**
From the official TTB Form 5120.17 page: *"There is no requirement to extend the figures shown on the Report Form 5120.17 beyond whole numbers."*

The form itself shows whole-gallon figures (e.g., 105,000 not 105,000.0).

**What our code does:**
All calculators round to 1 decimal place (`round($value, 1)`) and the TTBReportLine model casts gallons as `decimal:1`.

**Impact:** While reporting in tenths isn't technically prohibited (the language says "no requirement" to extend beyond whole numbers, not "must not"), it deviates from standard practice. More importantly, the balance tolerance check uses 0.1 gallons, which is correct for tenths but would need adjustment for whole numbers.

**Recommendation:** Switch to whole gallons (`round($value, 0)`) to match standard industry practice and the form's own presentation. Update the `decimal:1` cast to `integer` on the model.

---

## CRITICAL FINDING 4: Missing Wine Type Columns (Legal Risk: HIGH)

**What the form requires:**
Six separate columns, each independently tracked:
- (a) Not Over 16%
- (b) Over 16% to 21%
- (c) Over 21% to 24%
- (d) Artificially Carbonated
- (e) Sparkling Wine
- (f) Hard Cider

**What our code does:**
Uses four simplified categories: `table`, `dessert`, `sparkling`, `special_natural`. No distinction between 16-21% and 21-24%. No hard cider column. No artificially carbonated vs naturally sparkling distinction.

**Impact:** Wines in different tax classes would be incorrectly grouped. A 22% dessert wine and a 17% fortified wine would both be classified as "dessert" in our system but belong in different columns (c vs b) on the form.

**Fix required:** Replace the 4-type classification with 6 columns matching the form. The WineTypeClassifier needs thresholds at 16%, 21%, and 24%, plus detection for artificially carbonated vs naturally sparkling, and hard cider.

---

## CRITICAL FINDING 5: Bulk vs. Bottled Wine Not Separated (Legal Risk: HIGH)

**What the form requires:**
Part I has **two separate sections**: Section A for bulk wines and Section B for bottled wines. Each has its own opening inventory, production/receipt lines, removal/loss lines, and closing inventory. The balance equation is independent for each section.

**What our code does:**
All wine volumes are treated as a single pool. There is no bulk vs. bottled distinction in any calculator.

**Impact:** The bottling line (Section A Line 13 / Section B Line 2) is where wine moves from bulk to bottled. Our system counts bottling as a "removal" in Part IV but doesn't track it as an "increase" in Section B. The two-section balance would not work.

**Fix required:** Add a bulk/bottled dimension to the line item structure. Bottling events should decrease Section A and increase Section B by the same amount.

---

## MODERATE FINDING 6: Missing Production Method Granularity

**What the form requires:**
Section A Lines 2-6 break production into five specific methods:
- Line 2: Produced by Fermentation
- Line 3: Produced by Sweetening
- Line 4: Produced by Addition of Wine Spirits
- Line 5: Produced by Blending
- Line 6: Produced by Amelioration

**What our code does:**
PartTwoCalculator groups all production into two categories: `wine_produced` (from lot_created) and `wine_produced_blending` (from blend_finalized). No distinction for sweetening, wine spirits addition, or amelioration.

**Impact:** The form line items wouldn't be populated correctly. An auditor would see blank lines for sweetening/spirits/amelioration even if those operations occurred.

**Note:** This may be acceptable for initial release if the winery doesn't perform these operations, but the event log should capture them and the calculator should map them to the correct lines.

---

## MODERATE FINDING 7: Missing Removal Categories

**What the form requires:**
Section A Lines 14-28 include many specific removal types:
- Line 14: Removed Taxpaid
- Line 15: Transfers in Bond
- Line 16: Removed for Distilling Material
- Line 17: Removed to Vinegar Plant
- Line 18-21: Used for Sweetening/Spirits/Blending/Amelioration
- Line 22: Used for Effervescent Wine
- Line 23: Used for Testing
- Lines 24-28: Write-in entries

**What our code does:**
PartFourCalculator has two categories: `wine_bottled` and `wine_sold`. No separate tracking for taxpaid removals, in-bond transfers, distilling, vinegar, testing, etc.

**Impact:** Many removal line items would be blank or incorrectly categorized.

---

## LOW FINDING 8: No Distilled Spirits Tracking (Part III)

The actual form's Part III tracks distilled spirits in proof gallons. Our implementation has no distilled spirits tracking. This is acceptable for wineries that don't handle spirits, but the form section exists.

---

## LOW FINDING 9: No Materials Tracking (Part IV)

The actual form's Part IV tracks grape materials received and used (uncrushed grapes in pounds, field crushed in gallons, juice, concentrate, etc.). Our implementation doesn't track this. Lower priority but required for a complete filing.

---

## SUMMARY OF LEGAL RISK

| Finding | Risk | Status |
|---------|------|--------|
| 1. Alcohol threshold 14% vs 16% | **HIGH** | Must fix before any production use |
| 2. Form structure mismatch | **HIGH** | Must fix before filing with TTB |
| 3. Rounding (tenths vs whole gallons) | **MEDIUM** | Should fix to match standard practice |
| 4. Missing wine type columns | **HIGH** | Must fix — incorrect tax classification |
| 5. No bulk/bottled separation | **HIGH** | Must fix — form has two separate sections |
| 6. Missing production method lines | **MEDIUM** | Should fix for complete reporting |
| 7. Missing removal categories | **MEDIUM** | Should fix for complete reporting |
| 8. No distilled spirits (Part III) | **LOW** | Only needed if winery handles spirits |
| 9. No materials tracking (Part IV) | **LOW** | Required for complete filing |

---

## RECOMMENDATION

The current implementation provides a solid architectural foundation — event-sourced aggregation, wine type classification, review workflow, PDF generation, and test infrastructure. However, **the data model and classification logic must be reworked to match the actual TTB Form 5120.17 before this can be used for regulatory filing.**

The highest priority fixes are:
1. Change alcohol threshold from 14% to 16% (CBMA compliance)
2. Expand wine type columns from 4 to 6 matching the form
3. Split Part I into Section A (Bulk) and Section B (Bottled)
4. Map line items to the actual form's 32-line (Section A) and 21-line (Section B) structure
5. Switch rounding from tenths to whole gallons

These fixes do not require changing the underlying event-sourcing architecture, calculators, or test patterns — they require updating the classification constants, adding the bulk/bottled dimension, and restructuring the line item mapping.

---

## SOURCES

- [TTB Form 5120.17 Official Page](https://www.ttb.gov/ttb-form-512017)
- [Guide to Form 5120.17](https://www.ttb.gov/regulated-commodities/beverage-alcohol/wine/guide-to-form-5120-17)
- [TTB Color Coded Sample Report (PDF)](https://www.ttb.gov/system/files?file=images/pdfs/ttb-p-512017.pdf)
- [TTB Detailed Instructions (PDF)](https://www.ttb.gov/system/files/images/pdfs/wine_report_detailed_instructions.pdf)
- [27 CFR Part 24 Subpart O — Records and Reports](https://www.ecfr.gov/current/title-27/chapter-I/subchapter-A/part-24/subpart-O)
- [Craft Beverage Modernization Act (CBMA)](https://www.ttb.gov/alcohol/craft-beverage-modernization-and-tax-reform-cbmtra)
- [Wine Operations Report Filing Requirements](https://www.ttb.gov/regulated-commodities/beverage-alcohol/wine/report-of-wine-premises-operations)
