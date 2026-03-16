<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LabAnalysis;
use App\Models\LabThreshold;
use App\Support\LogContext;
use Illuminate\Support\Facades\Log;

/**
 * LabThresholdChecker — evaluates a lab analysis against configured thresholds.
 *
 * Called automatically after each new lab analysis entry. Returns an array of
 * alerts (may be empty if all values are within range). Variety-specific thresholds
 * take precedence over global (variety=null) thresholds for the same test type
 * and alert level.
 */
class LabThresholdChecker
{
    /**
     * Check a lab analysis against all applicable thresholds.
     *
     * @return array<int, array{alert_level: string, test_type: string, value: float, threshold_id: int, min_value: float|null, max_value: float|null, variety: string|null, message: string}>
     */
    public function check(LabAnalysis $analysis): array
    {
        $analysis->loadMissing('lot');

        $variety = $analysis->lot?->variety;
        $value = (float) $analysis->value;
        $testType = $analysis->test_type;

        // Get all applicable thresholds (variety-specific first, then global)
        $thresholds = LabThreshold::applicableTo($testType, $variety)->get();

        if ($thresholds->isEmpty()) {
            return [];
        }

        // Group by alert level — variety-specific overrides global for same level
        $effectiveThresholds = $this->resolveEffectiveThresholds($thresholds, $variety);

        $alerts = [];

        foreach ($effectiveThresholds as $threshold) {
            $violation = $this->evaluateThreshold($value, $threshold);

            if ($violation !== null) {
                $alerts[] = [
                    'alert_level' => $threshold->alert_level,
                    'test_type' => $testType,
                    'value' => $value,
                    'threshold_id' => $threshold->id,
                    'min_value' => $threshold->min_value !== null ? (float) $threshold->min_value : null,
                    'max_value' => $threshold->max_value !== null ? (float) $threshold->max_value : null,
                    'variety' => $threshold->variety,
                    'message' => $violation,
                ];
            }
        }

        if (! empty($alerts)) {
            Log::warning('Lab threshold alert triggered', LogContext::with([
                'analysis_id' => $analysis->id,
                'lot_id' => $analysis->lot_id,
                'test_type' => $testType,
                'value' => $value,
                'alert_count' => count($alerts),
            ]));
        }

        return $alerts;
    }

    /**
     * Resolve effective thresholds: variety-specific overrides global for same alert level.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, LabThreshold>  $thresholds
     * @return array<int, LabThreshold>
     */
    private function resolveEffectiveThresholds($thresholds, ?string $variety): array
    {
        $byLevel = [];

        foreach ($thresholds as $threshold) {
            $level = $threshold->alert_level;

            // If we already have a variety-specific threshold for this level, skip globals
            if (isset($byLevel[$level]) && $byLevel[$level]->variety !== null) {
                continue;
            }

            // Variety-specific always wins, or take global if nothing set yet
            if ($threshold->variety !== null || ! isset($byLevel[$level])) {
                $byLevel[$level] = $threshold;
            }
        }

        return array_values($byLevel);
    }

    /**
     * Evaluate a value against a single threshold.
     *
     * Returns a human-readable violation message, or null if within range.
     */
    private function evaluateThreshold(float $value, LabThreshold $threshold): ?string
    {
        $min = $threshold->min_value !== null ? (float) $threshold->min_value : null;
        $max = $threshold->max_value !== null ? (float) $threshold->max_value : null;

        if ($max !== null && $value > $max) {
            $levelLabel = ucfirst($threshold->alert_level);
            $scope = $threshold->variety !== null ? " for {$threshold->variety}" : '';

            return "{$levelLabel}: {$threshold->test_type} value {$value} exceeds maximum {$max}{$scope}";
        }

        if ($min !== null && $value < $min) {
            $levelLabel = ucfirst($threshold->alert_level);
            $scope = $threshold->variety !== null ? " for {$threshold->variety}" : '';

            return "{$levelLabel}: {$threshold->test_type} value {$value} below minimum {$min}{$scope}";
        }

        return null;
    }
}
