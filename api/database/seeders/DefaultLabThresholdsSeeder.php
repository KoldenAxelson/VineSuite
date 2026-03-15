<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LabThreshold;
use Illuminate\Database\Seeder;

/**
 * Seeds default lab analysis thresholds for common wine tests.
 *
 * These are industry-standard ranges. Wineries can customize them
 * per variety via the API or Filament interface.
 *
 * Run per tenant (called from DemoWinerySeeder or during tenant provisioning).
 */
class DefaultLabThresholdsSeeder extends Seeder
{
    /**
     * Default thresholds: [test_type, min, max, alert_level, variety].
     *
     * @var array<int, array{test_type: string, min_value: float|null, max_value: float|null, alert_level: string, variety: string|null}>
     */
    private const DEFAULTS = [
        // ─── VA (Volatile Acidity) ─────────────────────────────
        // Legal limit is 0.12 g/100mL for table wine (27 CFR 4.21)
        ['test_type' => 'VA', 'min_value' => null, 'max_value' => 0.10, 'alert_level' => 'warning', 'variety' => null],
        ['test_type' => 'VA', 'min_value' => null, 'max_value' => 0.12, 'alert_level' => 'critical', 'variety' => null],

        // ─── pH ────────────────────────────────────────────────
        // White wines: 3.0–3.4 ideal; reds: 3.3–3.6 ideal
        // Global range covers both; variety-specific can narrow
        ['test_type' => 'pH', 'min_value' => 2.9, 'max_value' => 3.8, 'alert_level' => 'warning', 'variety' => null],
        ['test_type' => 'pH', 'min_value' => 2.8, 'max_value' => 4.0, 'alert_level' => 'critical', 'variety' => null],

        // ─── TA (Titratable Acidity) ───────────────────────────
        // Typical range 5.0–8.0 g/L; outside suggests acid adjustment needed
        ['test_type' => 'TA', 'min_value' => 5.0, 'max_value' => 8.5, 'alert_level' => 'warning', 'variety' => null],
        ['test_type' => 'TA', 'min_value' => 4.0, 'max_value' => 10.0, 'alert_level' => 'critical', 'variety' => null],

        // ─── Free SO2 ─────────────────────────────────────────
        // Below 15 mg/L risks oxidation; above 50 mg/L detectable
        ['test_type' => 'free_SO2', 'min_value' => 15.0, 'max_value' => 50.0, 'alert_level' => 'warning', 'variety' => null],
        ['test_type' => 'free_SO2', 'min_value' => 10.0, 'max_value' => 60.0, 'alert_level' => 'critical', 'variety' => null],

        // ─── Total SO2 ────────────────────────────────────────
        // Legal limits: 350 mg/L (US) for all wines
        ['test_type' => 'total_SO2', 'min_value' => null, 'max_value' => 250.0, 'alert_level' => 'warning', 'variety' => null],
        ['test_type' => 'total_SO2', 'min_value' => null, 'max_value' => 350.0, 'alert_level' => 'critical', 'variety' => null],

        // ─── Residual Sugar ────────────────────────────────────
        // Dry wine < 4 g/L; warning if unexpectedly high (possible stuck fermentation)
        ['test_type' => 'residual_sugar', 'min_value' => null, 'max_value' => 4.0, 'alert_level' => 'warning', 'variety' => null],

        // ─── Alcohol ───────────────────────────────────────────
        // Table wine legal range 7–14% (27 CFR 4.21); practical range wider
        ['test_type' => 'alcohol', 'min_value' => 10.0, 'max_value' => 16.0, 'alert_level' => 'warning', 'variety' => null],
        ['test_type' => 'alcohol', 'min_value' => 7.0, 'max_value' => 24.0, 'alert_level' => 'critical', 'variety' => null],

        // ─── Turbidity ────────────────────────────────────────
        // < 1 NTU for bottling readiness; high turbidity needs fining
        ['test_type' => 'turbidity', 'min_value' => null, 'max_value' => 5.0, 'alert_level' => 'warning', 'variety' => null],
        ['test_type' => 'turbidity', 'min_value' => null, 'max_value' => 20.0, 'alert_level' => 'critical', 'variety' => null],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $threshold) {
            LabThreshold::updateOrCreate(
                [
                    'test_type' => $threshold['test_type'],
                    'variety' => $threshold['variety'],
                    'alert_level' => $threshold['alert_level'],
                ],
                [
                    'min_value' => $threshold['min_value'],
                    'max_value' => $threshold['max_value'],
                ],
            );
        }
    }
}
