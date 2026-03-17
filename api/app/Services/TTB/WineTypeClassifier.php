<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\LabAnalysis;

/**
 * WineTypeClassifier — categorizes wine into TTB Form 5120.17 types.
 *
 * TTB wine types:
 *   - table: still wine ≤14% alcohol by volume
 *   - dessert: still wine >14% and ≤24% alcohol by volume
 *   - sparkling: carbonated wine (any alcohol level)
 *   - special_natural: wine with added flavors/herbs
 *
 * Classification uses the most recent alcohol lab analysis for the lot.
 * If no lab data exists, defaults to 'table' and flags for review.
 */
class WineTypeClassifier
{
    /** TTB wine type constants. */
    public const TYPE_TABLE = 'table';

    public const TYPE_DESSERT = 'dessert';

    public const TYPE_SPARKLING = 'sparkling';

    public const TYPE_SPECIAL_NATURAL = 'special_natural';

    /** Alcohol threshold between table and dessert wine (exclusive). */
    public const TABLE_WINE_MAX_ALCOHOL = 14.0;

    /** Maximum alcohol for dessert wine classification. */
    public const DESSERT_WINE_MAX_ALCOHOL = 24.0;

    /** All valid TTB wine types for Form 5120.17. */
    public const VALID_TYPES = [
        self::TYPE_TABLE,
        self::TYPE_DESSERT,
        self::TYPE_SPARKLING,
        self::TYPE_SPECIAL_NATURAL,
    ];

    /**
     * Classify a lot's wine type based on lab analysis data.
     *
     * @param  string  $lotId  UUID of the lot
     * @param  array<string, mixed>|null  $eventPayload  Event payload for supplemental hints (e.g., sparkling flag)
     * @return array{type: string, alcohol_pct: float|null, needs_review: bool, source: string}
     */
    public function classify(string $lotId, ?array $eventPayload = null): array
    {
        // Check event payload for explicit sparkling indicator
        if ($eventPayload !== null && $this->isSparkling($eventPayload)) {
            return [
                'type' => self::TYPE_SPARKLING,
                'alcohol_pct' => $this->getAlcoholPercentage($lotId),
                'needs_review' => false,
                'source' => 'event_payload',
            ];
        }

        // Check event payload for special natural indicator
        if ($eventPayload !== null && $this->isSpecialNatural($eventPayload)) {
            return [
                'type' => self::TYPE_SPECIAL_NATURAL,
                'alcohol_pct' => $this->getAlcoholPercentage($lotId),
                'needs_review' => false,
                'source' => 'event_payload',
            ];
        }

        // Look up alcohol percentage from lab data
        $alcoholPct = $this->getAlcoholPercentage($lotId);

        if ($alcoholPct === null) {
            // No lab data — default to table wine and flag for review
            return [
                'type' => self::TYPE_TABLE,
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
     * Classify wine type by alcohol percentage alone.
     */
    public function classifyByAlcohol(float $alcoholPct): string
    {
        if ($alcoholPct <= self::TABLE_WINE_MAX_ALCOHOL) {
            return self::TYPE_TABLE;
        }

        if ($alcoholPct <= self::DESSERT_WINE_MAX_ALCOHOL) {
            return self::TYPE_DESSERT;
        }

        // Over 24% would be spirits territory — flag as dessert (edge case)
        return self::TYPE_DESSERT;
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
     * Check if event payload indicates sparkling wine.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isSparkling(array $payload): bool
    {
        return isset($payload['wine_style']) && $payload['wine_style'] === 'sparkling';
    }

    /**
     * Check if event payload indicates special natural wine.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isSpecialNatural(array $payload): bool
    {
        return isset($payload['wine_style']) && $payload['wine_style'] === 'special_natural';
    }
}
