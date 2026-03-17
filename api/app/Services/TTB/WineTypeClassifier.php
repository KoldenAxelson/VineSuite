<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\LabAnalysis;

/**
 * WineTypeClassifier — categorizes wine into TTB Form 5120.17 tax class columns.
 *
 * TTB Form 5120.17sm (01/2018) column structure per CBMA:
 *   - Column (a): Not Over 16 Percent alcohol by volume
 *   - Column (b): Over 16 to 21 Percent (Inclusive)
 *   - Column (c): Over 21 to 24 Percent (Inclusive)
 *   - Column (d): Artificially Carbonated Wine
 *   - Column (e): Sparkling Wine
 *   - Column (f): Hard Cider
 *
 * Alcohol thresholds updated per Craft Beverage Modernization Act (CBMA),
 * effective January 1, 2018. Pre-CBMA threshold was 14%.
 *
 * Classification uses the most recent alcohol lab analysis for the lot.
 * If no lab data exists, defaults to column (a) and flags for review.
 */
class WineTypeClassifier
{
    // ─── TTB Form 5120.17 column constants ───────────────────────────

    /** Column (a): Still wine not over 16% ABV. */
    public const COL_A_NOT_OVER_16 = 'not_over_16';

    /** Column (b): Still wine over 16% to 21% ABV. */
    public const COL_B_OVER_16_TO_21 = 'over_16_to_21';

    /** Column (c): Still wine over 21% to 24% ABV. */
    public const COL_C_OVER_21_TO_24 = 'over_21_to_24';

    /** Column (d): Artificially carbonated wine. */
    public const COL_D_ARTIFICIALLY_CARBONATED = 'artificially_carbonated';

    /** Column (e): Sparkling wine (natural carbonation). */
    public const COL_E_SPARKLING = 'sparkling';

    /** Column (f): Hard cider. */
    public const COL_F_HARD_CIDER = 'hard_cider';

    // ─── Legacy aliases (deprecated — use COL_ constants) ────────────

    /** @deprecated Use COL_A_NOT_OVER_16 */
    public const TYPE_TABLE = self::COL_A_NOT_OVER_16;

    /** @deprecated Use COL_B_OVER_16_TO_21 or COL_C_OVER_21_TO_24 */
    public const TYPE_DESSERT = self::COL_B_OVER_16_TO_21;

    /** @deprecated Use COL_E_SPARKLING */
    public const TYPE_SPARKLING = self::COL_E_SPARKLING;

    /** @deprecated Special natural wines are reported in Part IX, not Part I columns */
    public const TYPE_SPECIAL_NATURAL = 'special_natural';

    // ─── Alcohol thresholds ──────────────────────────────────────────

    /**
     * Column (a) upper bound: "Not Over 16 Percent".
     * Per CBMA (01/01/2018), changed from 14% to 16%.
     */
    public const TABLE_WINE_MAX_ALCOHOL = 16.0;

    /** Column (b) upper bound: "Over 16 to 21 Percent (Inclusive)". */
    public const DESSERT_WINE_MID_ALCOHOL = 21.0;

    /** Column (c) upper bound: "Over 21 to 24 Percent (Inclusive)". */
    public const DESSERT_WINE_MAX_ALCOHOL = 24.0;

    /** All valid TTB Form 5120.17 column types. */
    public const VALID_COLUMNS = [
        self::COL_A_NOT_OVER_16,
        self::COL_B_OVER_16_TO_21,
        self::COL_C_OVER_21_TO_24,
        self::COL_D_ARTIFICIALLY_CARBONATED,
        self::COL_E_SPARKLING,
        self::COL_F_HARD_CIDER,
    ];

    /** Human-readable labels for each column. */
    public const COLUMN_LABELS = [
        self::COL_A_NOT_OVER_16 => 'Not Over 16%',
        self::COL_B_OVER_16_TO_21 => 'Over 16% to 21%',
        self::COL_C_OVER_21_TO_24 => 'Over 21% to 24%',
        self::COL_D_ARTIFICIALLY_CARBONATED => 'Artificially Carbonated',
        self::COL_E_SPARKLING => 'Sparkling Wine',
        self::COL_F_HARD_CIDER => 'Hard Cider',
    ];

    /**
     * Classify a lot's wine type into a TTB Form 5120.17 column.
     *
     * @param  string  $lotId  UUID of the lot
     * @param  array<string, mixed>|null  $eventPayload  Event payload for supplemental hints
     * @return array{type: string, alcohol_pct: float|null, needs_review: bool, source: string}
     */
    public function classify(string $lotId, ?array $eventPayload = null): array
    {
        // Check event payload for hard cider indicator
        if ($eventPayload !== null && $this->isHardCider($eventPayload)) {
            return [
                'type' => self::COL_F_HARD_CIDER,
                'alcohol_pct' => $this->getAlcoholPercentage($lotId),
                'needs_review' => false,
                'source' => 'event_payload',
            ];
        }

        // Check event payload for artificially carbonated indicator
        if ($eventPayload !== null && $this->isArtificiallyCarbonated($eventPayload)) {
            return [
                'type' => self::COL_D_ARTIFICIALLY_CARBONATED,
                'alcohol_pct' => $this->getAlcoholPercentage($lotId),
                'needs_review' => false,
                'source' => 'event_payload',
            ];
        }

        // Check event payload for sparkling wine indicator (natural carbonation)
        if ($eventPayload !== null && $this->isSparkling($eventPayload)) {
            return [
                'type' => self::COL_E_SPARKLING,
                'alcohol_pct' => $this->getAlcoholPercentage($lotId),
                'needs_review' => false,
                'source' => 'event_payload',
            ];
        }

        // Look up alcohol percentage from lab data
        $alcoholPct = $this->getAlcoholPercentage($lotId);

        if ($alcoholPct === null) {
            // No lab data — default to column (a) and flag for review
            return [
                'type' => self::COL_A_NOT_OVER_16,
                'alcohol_pct' => null,
                'needs_review' => true,
                'source' => 'default_no_lab_data',
            ];
        }

        return [
            'type' => $this->classifyByAlcohol($alcoholPct),
            'alcohol_pct' => $alcoholPct,
            'needs_review' => false,
            'source' => 'lab_analysis',
        ];
    }

    /**
     * Classify wine into a TTB column by alcohol percentage alone.
     *
     * Per TTB Form 5120.17sm (01/2018) and CBMA:
     *   Column (a): ≤16%
     *   Column (b): >16% and ≤21%
     *   Column (c): >21% and ≤24%
     *   Above 24%: spirits territory (edge case, classified as column c)
     */
    public function classifyByAlcohol(float $alcoholPct): string
    {
        if ($alcoholPct <= self::TABLE_WINE_MAX_ALCOHOL) {
            return self::COL_A_NOT_OVER_16;
        }

        if ($alcoholPct <= self::DESSERT_WINE_MID_ALCOHOL) {
            return self::COL_B_OVER_16_TO_21;
        }

        // Over 21% goes to column (c), even if above 24% (edge case)
        return self::COL_C_OVER_21_TO_24;
    }

    /**
     * Get the most recent alcohol percentage for a lot from lab analyses.
     */
    public function getAlcoholPercentage(string $lotId): ?float
    {
        $analysis = LabAnalysis::where('lot_id', $lotId)
            ->where('test_type', 'alcohol')
            ->orderByDesc('test_date')
            ->first();

        if ($analysis === null) {
            return null;
        }

        return (float) $analysis->value;
    }

    /**
     * Get a human-readable label for a column type.
     */
    public function getColumnLabel(string $columnType): string
    {
        return self::COLUMN_LABELS[$columnType] ?? $columnType;
    }

    /**
     * Check if event payload indicates sparkling wine (natural carbonation).
     *
     * @param  array<string, mixed>  $payload
     */
    private function isSparkling(array $payload): bool
    {
        return isset($payload['wine_style']) && $payload['wine_style'] === 'sparkling';
    }

    /**
     * Check if event payload indicates artificially carbonated wine.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isArtificiallyCarbonated(array $payload): bool
    {
        return isset($payload['wine_style']) && $payload['wine_style'] === 'artificially_carbonated';
    }

    /**
     * Check if event payload indicates hard cider.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isHardCider(array $payload): bool
    {
        return isset($payload['wine_style']) && $payload['wine_style'] === 'hard_cider';
    }
}
