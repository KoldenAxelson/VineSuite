<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\BlendTrial;
use App\Models\LabelComplianceCheck;
use App\Models\LabelProfile;
use App\Models\Lot;
use Illuminate\Support\Facades\DB;

/**
 * LabelComplianceService — validates blend compositions against TTB labeling rules.
 *
 * Four rules evaluated:
 *   1. Varietal (75%) — wine labeled with a varietal must contain >=75% of that grape
 *   2. AVA (85%) — wine labeled with an AVA must contain >=85% fruit from that AVA
 *   3. Vintage (95%) — wine labeled with a vintage must contain >=95% grapes from that year
 *   4. Conjunctive Labeling (California) — sub-AVA labels must also display parent AVA
 *
 * Calculations are derived from blend trial components and their source lots.
 * When no blend trial is attached, the service validates against a single lot.
 */
class LabelComplianceService
{
    public const VARIETAL_THRESHOLD = 75.0;

    public const AVA_THRESHOLD = 85.0;

    public const VINTAGE_THRESHOLD = 95.0;

    /**
     * Paso Robles sub-AVAs that require conjunctive labeling under California law.
     * Any sub-AVA label must also display the parent AVA "Paso Robles".
     */
    public const PASO_ROBLES_SUB_AVAS = [
        'Adelaida District',
        'Creston District',
        'El Pomar District',
        'Estrella District',
        'Geneseo District',
        'Highlands District',
        'Paso Robles Willow Creek District',
        'San Juan Creek',
        'San Miguel District',
        'Santa Margarita Ranch',
        'Templeton Gap District',
    ];

    /**
     * California AVA hierarchy — maps sub-AVAs to their required parent AVA
     * for conjunctive labeling compliance.
     *
     * @var array<string, string>
     */
    public const CONJUNCTIVE_LABEL_PARENTS = [
        'Adelaida District' => 'Paso Robles',
        'Creston District' => 'Paso Robles',
        'El Pomar District' => 'Paso Robles',
        'Estrella District' => 'Paso Robles',
        'Geneseo District' => 'Paso Robles',
        'Highlands District' => 'Paso Robles',
        'Paso Robles Willow Creek District' => 'Paso Robles',
        'San Juan Creek' => 'Paso Robles',
        'San Miguel District' => 'Paso Robles',
        'Santa Margarita Ranch' => 'Paso Robles',
        'Templeton Gap District' => 'Paso Robles',
    ];

    /**
     * Run all applicable compliance checks for a label profile.
     *
     * Evaluates each rule based on the label claims present. Rules only apply
     * when the corresponding claim exists on the profile:
     *   - varietal_claim → varietal 75% check
     *   - ava_claim or sub_ava_claim → AVA 85% check
     *   - vintage_claim → vintage 95% check
     *   - sub_ava_claim → conjunctive labeling check
     *
     * @return array{status: string, checks: array<int, LabelComplianceCheck>}
     */
    public function evaluate(LabelProfile $profile): array
    {
        if ($profile->isLocked()) {
            return [
                'status' => $profile->compliance_status,
                'checks' => $profile->complianceChecks()->get()->all(),
            ];
        }

        $composition = $this->resolveComposition($profile);

        return DB::transaction(function () use ($profile, $composition) {
            // Clear previous checks for this profile
            $profile->complianceChecks()->delete();

            $checks = [];

            // Rule 1: Varietal 75%
            if ($profile->varietal_claim !== null) {
                $checks[] = $this->checkVarietal($profile, $composition);
            }

            // Rule 2: AVA 85%
            if ($profile->sub_ava_claim !== null || $profile->ava_claim !== null) {
                $checks[] = $this->checkAva($profile, $composition);
            }

            // Rule 3: Vintage 95%
            if ($profile->vintage_claim !== null) {
                $checks[] = $this->checkVintage($profile, $composition);
            }

            // Rule 4: Conjunctive labeling (California)
            if ($profile->sub_ava_claim !== null) {
                $checks[] = $this->checkConjunctiveLabel($profile);
            }

            // Determine overall status
            $allPass = collect($checks)->every(fn (LabelComplianceCheck $c) => $c->passes);
            $status = count($checks) === 0 ? 'unchecked' : ($allPass ? 'passing' : 'failing');

            $profile->update(['compliance_status' => $status]);

            return [
                'status' => $status,
                'checks' => $checks,
            ];
        });
    }

    /**
     * Lock a label profile — snapshot the current compliance state as an immutable record.
     *
     * Typically called at bottling time. After locking, the profile and its checks
     * cannot be modified, serving as permanent audit documentation.
     */
    public function lock(LabelProfile $profile): LabelProfile
    {
        $result = $this->evaluate($profile);

        $snapshot = [
            'locked_at' => now()->toIso8601String(),
            'status' => $result['status'],
            'checks' => collect($result['checks'])->map(fn (LabelComplianceCheck $check) => [
                'rule_type' => $check->rule_type,
                'threshold' => (float) $check->threshold,
                'actual_percentage' => $check->actual_percentage !== null ? (float) $check->actual_percentage : null,
                'passes' => $check->passes,
                'details' => $check->details,
            ])->all(),
        ];

        $profile->update([
            'compliance_snapshot' => $snapshot,
            'locked_at' => now(),
        ]);

        return $profile->fresh();
    }

    /**
     * Resolve the blend composition into per-component data.
     *
     * Returns an array of component records, each with: source_lot, variety,
     * source_ava, vintage, volume_gallons, and percentage.
     *
     * @return array<int, array{source_lot: Lot, variety: string, source_ava: string|null, vintage: int, volume_gallons: float, percentage: float}>
     */
    private function resolveComposition(LabelProfile $profile): array
    {
        // If linked to a blend trial, use its components
        if ($profile->blend_trial_id !== null) {
            $trial = BlendTrial::with('components.sourceLot')
                ->findOrFail($profile->blend_trial_id);

            return $trial->components->map(fn ($component) => [
                'source_lot' => $component->sourceLot,
                'variety' => $component->sourceLot->variety,
                'source_ava' => $component->sourceLot->source_ava,
                'vintage' => $component->sourceLot->vintage,
                'volume_gallons' => (float) $component->volume_gallons,
                'percentage' => (float) $component->percentage,
            ])->all();
        }

        // If linked to a SKU, trace back to its lot
        if ($profile->sku_id !== null) {
            $sku = $profile->sku()->with('lot')->first();
            if ($sku !== null && $sku->lot !== null) {
                $lot = $sku->lot;

                return [[
                    'source_lot' => $lot,
                    'variety' => $lot->variety,
                    'source_ava' => $lot->source_ava,
                    'vintage' => $lot->vintage,
                    'volume_gallons' => (float) $lot->volume_gallons,
                    'percentage' => 100.0,
                ]];
            }
        }

        return [];
    }

    /**
     * Check varietal 75% rule.
     *
     * TTB requires >=75% of the named varietal for varietal labeling.
     *
     * @param  array<int, array{source_lot: Lot, variety: string, source_ava: string|null, vintage: int, volume_gallons: float, percentage: float}>  $composition
     */
    private function checkVarietal(LabelProfile $profile, array $composition): LabelComplianceCheck
    {
        $claimedVariety = $profile->varietal_claim;
        $totalVolume = array_sum(array_column($composition, 'volume_gallons'));

        $matchingVolume = 0.0;
        $breakdown = [];

        foreach ($composition as $component) {
            $isMatch = strcasecmp($component['variety'], $claimedVariety) === 0;
            if ($isMatch) {
                $matchingVolume += $component['volume_gallons'];
            }

            $breakdown[] = [
                'lot_id' => $component['source_lot']->id,
                'lot_name' => $component['source_lot']->name,
                'variety' => $component['variety'],
                'volume_gallons' => $component['volume_gallons'],
                'matches_claim' => $isMatch,
            ];
        }

        $actualPct = $totalVolume > 0 ? ($matchingVolume / $totalVolume) * 100 : 0.0;
        $passes = $actualPct >= self::VARIETAL_THRESHOLD;

        $details = [
            'claimed_varietal' => $claimedVariety,
            'matching_volume_gallons' => round($matchingVolume, 4),
            'total_volume_gallons' => round($totalVolume, 4),
            'breakdown' => $breakdown,
        ];

        if (! $passes) {
            $details['remediation'] = $this->suggestVarietalRemediation(
                $claimedVariety,
                $matchingVolume,
                $totalVolume,
            );
        }

        return LabelComplianceCheck::create([
            'label_profile_id' => $profile->id,
            'rule_type' => LabelComplianceCheck::RULE_VARIETAL_75,
            'threshold' => self::VARIETAL_THRESHOLD,
            'actual_percentage' => round($actualPct, 4),
            'passes' => $passes,
            'details' => $details,
            'checked_at' => now(),
        ]);
    }

    /**
     * Check AVA 85% rule.
     *
     * TTB requires >=85% of fruit from the named AVA for AVA labeling.
     * Checks against sub_ava_claim if present, otherwise ava_claim.
     *
     * @param  array<int, array{source_lot: Lot, variety: string, source_ava: string|null, vintage: int, volume_gallons: float, percentage: float}>  $composition
     */
    private function checkAva(LabelProfile $profile, array $composition): LabelComplianceCheck
    {
        // The most specific AVA claim is what needs to pass the 85% test
        $claimedAva = $profile->sub_ava_claim ?? $profile->ava_claim;
        $totalVolume = array_sum(array_column($composition, 'volume_gallons'));

        $matchingVolume = 0.0;
        $breakdown = [];

        foreach ($composition as $component) {
            $isMatch = $component['source_ava'] !== null
                && strcasecmp($component['source_ava'], $claimedAva) === 0;
            if ($isMatch) {
                $matchingVolume += $component['volume_gallons'];
            }

            $breakdown[] = [
                'lot_id' => $component['source_lot']->id,
                'lot_name' => $component['source_lot']->name,
                'source_ava' => $component['source_ava'],
                'volume_gallons' => $component['volume_gallons'],
                'matches_claim' => $isMatch,
            ];
        }

        $actualPct = $totalVolume > 0 ? ($matchingVolume / $totalVolume) * 100 : 0.0;
        $passes = $actualPct >= self::AVA_THRESHOLD;

        $details = [
            'claimed_ava' => $claimedAva,
            'matching_volume_gallons' => round($matchingVolume, 4),
            'total_volume_gallons' => round($totalVolume, 4),
            'breakdown' => $breakdown,
        ];

        if (! $passes) {
            $details['remediation'] = $this->suggestAvaRemediation(
                $claimedAva,
                $matchingVolume,
                $totalVolume,
            );
        }

        return LabelComplianceCheck::create([
            'label_profile_id' => $profile->id,
            'rule_type' => LabelComplianceCheck::RULE_AVA_85,
            'threshold' => self::AVA_THRESHOLD,
            'actual_percentage' => round($actualPct, 4),
            'passes' => $passes,
            'details' => $details,
            'checked_at' => now(),
        ]);
    }

    /**
     * Check vintage 95% rule.
     *
     * TTB requires >=95% of grapes from the labeled vintage year.
     * If no vintage is claimed (NV wine), this rule does not apply.
     *
     * @param  array<int, array{source_lot: Lot, variety: string, source_ava: string|null, vintage: int, volume_gallons: float, percentage: float}>  $composition
     */
    private function checkVintage(LabelProfile $profile, array $composition): LabelComplianceCheck
    {
        $claimedVintage = $profile->vintage_claim;
        $totalVolume = array_sum(array_column($composition, 'volume_gallons'));

        $matchingVolume = 0.0;
        $breakdown = [];

        foreach ($composition as $component) {
            $isMatch = $component['vintage'] === $claimedVintage;
            if ($isMatch) {
                $matchingVolume += $component['volume_gallons'];
            }

            $breakdown[] = [
                'lot_id' => $component['source_lot']->id,
                'lot_name' => $component['source_lot']->name,
                'vintage' => $component['vintage'],
                'volume_gallons' => $component['volume_gallons'],
                'matches_claim' => $isMatch,
            ];
        }

        $actualPct = $totalVolume > 0 ? ($matchingVolume / $totalVolume) * 100 : 0.0;
        $passes = $actualPct >= self::VINTAGE_THRESHOLD;

        $details = [
            'claimed_vintage' => $claimedVintage,
            'matching_volume_gallons' => round($matchingVolume, 4),
            'total_volume_gallons' => round($totalVolume, 4),
            'breakdown' => $breakdown,
        ];

        if (! $passes) {
            $details['remediation'] = sprintf(
                'The blend is %.1f%% from vintage %d. You need at least %.0f%%. '
                .'Consider adding more %d vintage fruit or removing non-%d components.',
                $actualPct,
                $claimedVintage,
                self::VINTAGE_THRESHOLD,
                $claimedVintage,
                $claimedVintage,
            );
        }

        return LabelComplianceCheck::create([
            'label_profile_id' => $profile->id,
            'rule_type' => LabelComplianceCheck::RULE_VINTAGE_95,
            'threshold' => self::VINTAGE_THRESHOLD,
            'actual_percentage' => round($actualPct, 4),
            'passes' => $passes,
            'details' => $details,
            'checked_at' => now(),
        ]);
    }

    /**
     * Check California conjunctive labeling rule.
     *
     * Under California law, any wine labeled with a sub-AVA must also display
     * the parent AVA name on the label. For example, "Adelaida District" must
     * also show "Paso Robles".
     *
     * This is a structural check (does the profile have both claims?), not a
     * percentage-based check.
     */
    private function checkConjunctiveLabel(LabelProfile $profile): LabelComplianceCheck
    {
        $subAva = $profile->sub_ava_claim;
        $parentAva = $profile->ava_claim;
        $requiredParent = self::CONJUNCTIVE_LABEL_PARENTS[$subAva] ?? null;

        // If the sub-AVA is in our known hierarchy, check that the parent matches
        if ($requiredParent !== null) {
            $passes = $parentAva !== null
                && strcasecmp($parentAva, $requiredParent) === 0;

            $details = [
                'sub_ava' => $subAva,
                'declared_parent_ava' => $parentAva,
                'required_parent_ava' => $requiredParent,
            ];

            if (! $passes) {
                $details['remediation'] = sprintf(
                    'California conjunctive labeling requires "%s" to appear alongside "%s" on the label.',
                    $requiredParent,
                    $subAva,
                );
            }
        } else {
            // Unknown sub-AVA — pass by default but flag for manual review
            $passes = true;
            $details = [
                'sub_ava' => $subAva,
                'declared_parent_ava' => $parentAva,
                'note' => 'Sub-AVA not in known California conjunctive labeling hierarchy. Manual review recommended.',
            ];
        }

        return LabelComplianceCheck::create([
            'label_profile_id' => $profile->id,
            'rule_type' => LabelComplianceCheck::RULE_CONJUNCTIVE_LABEL,
            'threshold' => 0.00, // structural check, not percentage-based
            'actual_percentage' => null,
            'passes' => $passes,
            'details' => $details,
            'checked_at' => now(),
        ]);
    }

    /**
     * Suggest remediation for a failing varietal check.
     *
     * Calculates how many additional gallons of the target variety would be
     * needed to reach the 75% threshold.
     */
    private function suggestVarietalRemediation(
        string $claimedVariety,
        float $matchingVolume,
        float $totalVolume,
    ): string {
        // To reach 75%: (matchingVolume + x) / (totalVolume + x) = 0.75
        // matchingVolume + x = 0.75 * totalVolume + 0.75 * x
        // 0.25 * x = 0.75 * totalVolume - matchingVolume
        // x = (0.75 * totalVolume - matchingVolume) / 0.25
        $needed = ((self::VARIETAL_THRESHOLD / 100) * $totalVolume - $matchingVolume) / (1 - self::VARIETAL_THRESHOLD / 100);
        $needed = max(0, ceil($needed * 10) / 10); // round up to nearest 0.1 gal

        $currentPct = $totalVolume > 0 ? ($matchingVolume / $totalVolume) * 100 : 0;

        return sprintf(
            'The blend is %.1f%% %s (needs %.0f%%). Add approximately %.1f gallons of %s to reach the threshold.',
            $currentPct,
            $claimedVariety,
            self::VARIETAL_THRESHOLD,
            $needed,
            $claimedVariety,
        );
    }

    /**
     * Suggest remediation for a failing AVA check.
     */
    private function suggestAvaRemediation(
        string $claimedAva,
        float $matchingVolume,
        float $totalVolume,
    ): string {
        $needed = ((self::AVA_THRESHOLD / 100) * $totalVolume - $matchingVolume) / (1 - self::AVA_THRESHOLD / 100);
        $needed = max(0, ceil($needed * 10) / 10);

        $currentPct = $totalVolume > 0 ? ($matchingVolume / $totalVolume) * 100 : 0;

        return sprintf(
            'The blend is %.1f%% from %s (needs %.0f%%). Add approximately %.1f gallons of %s fruit to reach the threshold.',
            $currentPct,
            $claimedAva,
            self::AVA_THRESHOLD,
            $needed,
            $claimedAva,
        );
    }
}
