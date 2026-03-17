<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BottlingRun;
use App\Models\CaseGoodsSku;
use App\Models\LaborRate;
use App\Models\Lot;
use App\Models\LotCostEntry;
use App\Models\OverheadRate;
use App\Models\User;
use App\Services\CostAccumulationService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds realistic cost accounting data for the demo winery.
 *
 * Adds fruit costs, material costs, labor costs, overhead rates,
 * and generates COGS summaries for completed bottling runs.
 * Must be called within a tenant context, AFTER ProductionSeeder
 * and InventorySeeder (needs lots, work orders, bottling runs, dry goods).
 */
class CostAccountingSeeder extends Seeder
{
    private CostAccumulationService $costService;

    /** @var array<string, Lot> */
    private array $lots = [];

    private string $winemaker;

    public function run(): void
    {
        $this->costService = app(CostAccumulationService::class);
        $this->winemaker = User::where('role', 'winemaker')->first()->id;

        // Index all lots by name for quick lookup
        Lot::all()->each(function (Lot $lot) {
            $this->lots[$lot->name] = $lot;
        });

        $this->seedLaborRates();
        $this->seedOverheadRates();
        $this->seedFruitCosts();
        $this->seedMaterialCosts();
        $this->seedLaborCosts();
        $this->seedOverheadAllocations();
        $this->seedBottlingCogs();

        $this->command?->info('  → Cost accounting data seeded ('.LotCostEntry::count().' cost entries created).');
    }

    // ─── Labor Rates ────────────────────────────────────────────────

    private function seedLaborRates(): void
    {
        $rates = [
            ['role' => 'winemaker', 'hourly_rate' => '65.0000'],
            ['role' => 'cellar_hand', 'hourly_rate' => '28.0000'],
            ['role' => 'admin', 'hourly_rate' => '45.0000'],
            ['role' => 'tasting_room_staff', 'hourly_rate' => '22.0000'],
        ];

        foreach ($rates as $rate) {
            LaborRate::create([
                'role' => $rate['role'],
                'hourly_rate' => $rate['hourly_rate'],
                'is_active' => true,
            ]);
        }

        $this->command?->info('  → Created '.count($rates).' labor rates.');
    }

    // ─── Overhead Rates ─────────────────────────────────────────────

    private function seedOverheadRates(): void
    {
        OverheadRate::create([
            'name' => 'Facility Rent',
            'allocation_method' => 'per_gallon',
            'rate' => '0.3500',
            'is_active' => true,
        ]);

        OverheadRate::create([
            'name' => 'Insurance',
            'allocation_method' => 'per_gallon',
            'rate' => '0.1200',
            'is_active' => true,
        ]);

        OverheadRate::create([
            'name' => 'Utilities',
            'allocation_method' => 'per_gallon',
            'rate' => '0.2500',
            'is_active' => true,
        ]);

        OverheadRate::create([
            'name' => 'Equipment Depreciation',
            'allocation_method' => 'per_labor_hour',
            'rate' => '15.0000',
            'is_active' => true,
        ]);

        OverheadRate::create([
            'name' => 'Packaging Line Overhead',
            'allocation_method' => 'per_case',
            'rate' => '2.5000',
            'is_active' => true,
        ]);

        $this->command?->info('  → Created 5 overhead rates.');
    }

    // ─── Fruit Costs ────────────────────────────────────────────────

    private function seedFruitCosts(): void
    {
        // Realistic Paso Robles grape costs per ton (converted to per-gallon)
        // ~160 gal/ton for red, ~150 gal/ton for white
        $fruitCosts = [
            // 2024 vintage — estate
            ['2024 Estate Cabernet Sauvignon - Block A', 12.50, Carbon::create(2024, 9, 15)],
            ['2024 Estate Cabernet Sauvignon - Block C', 12.50, Carbon::create(2024, 9, 20)],
            ['2024 Estate Syrah', 9.00, Carbon::create(2024, 9, 25)],
            ['2024 Estate Grenache', 8.50, Carbon::create(2024, 10, 1)],
            ['2024 Estate Mourvèdre', 10.00, Carbon::create(2024, 10, 5)],
            ['2024 Estate Chardonnay', 11.00, Carbon::create(2024, 8, 28)],
            ['2024 Rosé Blend', 7.50, Carbon::create(2024, 9, 10)],
            ['2024 Reserve Cabernet Sauvignon', 14.00, Carbon::create(2024, 9, 18)],
            ['2024 Paso White Blend', 9.50, Carbon::create(2024, 8, 30)],
            ['2024 Late Harvest Viognier', 15.00, Carbon::create(2024, 11, 1)],

            // 2024 — purchased fruit
            ['2024 Estrella Syrah', 7.00, Carbon::create(2024, 10, 10)],
            ['2024 Petite Sirah - Willow Creek', 8.00, Carbon::create(2024, 10, 12)],

            // 2025 vintage
            ['2025 Estate Cabernet Sauvignon - Block A', 13.00, Carbon::create(2025, 9, 12)],
            ['2025 Estate Cabernet Sauvignon - Block C', 13.00, Carbon::create(2025, 9, 18)],
            ['2025 Estate Syrah', 9.50, Carbon::create(2025, 9, 22)],
            ['2025 Estate Grenache', 8.75, Carbon::create(2025, 10, 3)],
            ['2025 Estate Mourvèdre', 10.50, Carbon::create(2025, 10, 8)],
            ['2025 Estate Chardonnay', 11.50, Carbon::create(2025, 8, 25)],
            ['2025 Viognier', 10.00, Carbon::create(2025, 8, 28)],
            ['2025 Merlot - York Mountain', 8.00, Carbon::create(2025, 10, 1)],
            ['2025 Petite Sirah - Willow Creek', 8.50, Carbon::create(2025, 10, 15)],

            // 2023 vintage — historical
            ['2023 Reserve Cabernet Sauvignon', 11.00, Carbon::create(2023, 9, 20)],
            ['2023 Estate Syrah', 8.00, Carbon::create(2023, 9, 28)],
            ['2023 GSM Blend', 8.50, Carbon::create(2023, 10, 5)],
            ['2023 Zinfandel', 7.50, Carbon::create(2023, 10, 10)],
            ['2023 Estate Chardonnay', 10.00, Carbon::create(2023, 8, 25)],
        ];

        $count = 0;
        foreach ($fruitCosts as [$lotName, $costPerGallon, $date]) {
            if (! isset($this->lots[$lotName])) {
                continue;
            }

            $lot = $this->lots[$lotName];
            // Use the lot's volume or original volume (for bottled/sold lots use a realistic estimate)
            $volume = $lot->volume_gallons > 0
                ? (string) $lot->volume_gallons
                : (string) rand(400, 1200); // historical lots had volume before bottling

            $amount = bcmul((string) $costPerGallon, $volume, 4);

            $this->costService->recordFruitCost(
                lot: $lot,
                amount: $amount,
                quantity: $volume,
                unitCost: number_format($costPerGallon, 4, '.', ''),
                performedBy: $this->winemaker,
                options: [
                    'description' => "Fruit purchase — {$lot->variety} ({$lot->source_type})",
                    'performed_at' => $date,
                ],
            );
            $count++;
        }

        $this->command?->info("  → Created {$count} fruit cost entries.");
    }

    // ─── Material Costs ─────────────────────────────────────────────

    private function seedMaterialCosts(): void
    {
        // Material costs for additions (SO2, nutrients, fining agents, etc.)
        $materialCosts = [
            ['2024 Estate Chardonnay', 'SO2 addition (30 ppm)', '45.00', Carbon::create(2024, 9, 5)],
            ['2024 Estate Chardonnay', 'Bentonite fining', '120.00', Carbon::create(2024, 11, 15)],
            ['2024 Estate Cabernet Sauvignon - Block A', 'SO2 addition (25 ppm)', '65.00', Carbon::create(2024, 9, 20)],
            ['2024 Estate Cabernet Sauvignon - Block A', 'Yeast nutrients (Fermaid-O)', '85.00', Carbon::create(2024, 9, 22)],
            ['2024 Estate Syrah', 'SO2 addition (30 ppm)', '55.00', Carbon::create(2024, 10, 1)],
            ['2024 Estate Syrah', 'Tartaric acid adjustment', '42.00', Carbon::create(2024, 10, 5)],
            ['2024 Estate Grenache', 'Tartaric acid adjustment', '38.00', Carbon::create(2024, 10, 8)],
            ['2024 Estate Grenache', 'SO2 addition', '48.00', Carbon::create(2024, 10, 15)],
            ['2024 Rosé Blend', 'SO2 addition', '25.00', Carbon::create(2024, 9, 15)],
            ['2024 Rosé Blend', 'Cold stabilization agents', '35.00', Carbon::create(2024, 12, 1)],
            ['2024 Paso White Blend', 'Bentonite fining', '95.00', Carbon::create(2024, 11, 20)],
            ['2024 Paso White Blend', 'SO2 addition', '30.00', Carbon::create(2025, 1, 10)],
            ['2024 Reserve Cabernet Sauvignon', 'Premium oak extract', '200.00', Carbon::create(2025, 1, 15)],
            ['2024 Reserve Cabernet Sauvignon', 'SO2 addition', '75.00', Carbon::create(2025, 2, 1)],
            ['2025 Estate Cabernet Sauvignon - Block A', 'Yeast nutrients', '90.00', Carbon::create(2025, 9, 18)],
            ['2025 Estate Syrah', 'SO2 addition', '50.00', Carbon::create(2025, 10, 5)],
            ['2025 Merlot - York Mountain', 'Enological tannin', '65.00', Carbon::create(2025, 10, 10)],
            ['2023 Reserve Cabernet Sauvignon', 'Final SO2 before bottling', '55.00', Carbon::create(2024, 7, 15)],
            ['2023 Estate Syrah', 'Final SO2 before bottling', '40.00', Carbon::create(2024, 6, 20)],
        ];

        $count = 0;
        foreach ($materialCosts as [$lotName, $description, $amount, $date]) {
            if (! isset($this->lots[$lotName])) {
                continue;
            }

            $this->costService->recordManualCost(
                lot: $this->lots[$lotName],
                costType: 'material',
                description: $description,
                amount: $amount,
                performedBy: $this->winemaker,
                performedAt: $date,
            );
            $count++;
        }

        $this->command?->info("  → Created {$count} material cost entries.");
    }

    // ─── Labor Costs ────────────────────────────────────────────────

    private function seedLaborCosts(): void
    {
        // Labor costs for cellar work
        $laborCosts = [
            ['2024 Estate Cabernet Sauvignon - Block A', 'Punch-down labor (harvest)', '28.00', '8.00', Carbon::create(2024, 9, 16)],
            ['2024 Estate Cabernet Sauvignon - Block A', 'Rack & return', '28.00', '3.00', Carbon::create(2024, 11, 1)],
            ['2024 Estate Cabernet Sauvignon - Block C', 'Punch-down labor (harvest)', '28.00', '6.00', Carbon::create(2024, 9, 22)],
            ['2024 Estate Syrah', 'Pump-over labor', '28.00', '4.00', Carbon::create(2024, 9, 28)],
            ['2024 Estate Syrah', 'Barrel filling', '28.00', '3.00', Carbon::create(2024, 10, 15)],
            ['2024 Estate Chardonnay', 'Pressing labor', '28.00', '5.00', Carbon::create(2024, 9, 1)],
            ['2024 Estate Chardonnay', 'Barrel stirring', '65.00', '2.00', Carbon::create(2024, 10, 20)],
            ['2024 Rosé Blend', 'Bleed-off & pressing', '28.00', '3.00', Carbon::create(2024, 9, 12)],
            ['2024 Rosé Blend', 'Bottling line labor', '28.00', '6.00', Carbon::create(2025, 2, 15)],
            ['2024 Paso White Blend', 'Blending labor', '65.00', '4.00', Carbon::create(2025, 1, 20)],
            ['2024 Paso White Blend', 'Bottling line labor', '28.00', '8.00', Carbon::create(2025, 3, 1)],
            ['2024 Reserve Cabernet Sauvignon', 'Barrel selection & blending', '65.00', '8.00', Carbon::create(2025, 2, 10)],
            ['2024 Estate Grenache', 'Punch-down labor', '28.00', '4.00', Carbon::create(2024, 10, 3)],
            ['2024 Estate Mourvèdre', 'Pump-over labor', '28.00', '3.00', Carbon::create(2024, 10, 8)],
            ['2025 Estate Cabernet Sauvignon - Block A', 'Harvest processing', '28.00', '10.00', Carbon::create(2025, 9, 14)],
            ['2025 Estate Syrah', 'Crush & destem', '28.00', '4.00', Carbon::create(2025, 9, 24)],
            ['2023 Reserve Cabernet Sauvignon', 'Bottling line labor', '28.00', '12.00', Carbon::create(2024, 8, 20)],
            ['2023 Estate Syrah', 'Bottling line labor', '28.00', '8.00', Carbon::create(2024, 6, 25)],
        ];

        $count = 0;
        foreach ($laborCosts as [$lotName, $description, $hourlyRate, $hours, $date]) {
            if (! isset($this->lots[$lotName])) {
                continue;
            }

            $amount = bcmul($hourlyRate, $hours, 4);

            $this->costService->recordManualCost(
                lot: $this->lots[$lotName],
                costType: 'labor',
                description: $description,
                amount: $amount,
                performedBy: $this->winemaker,
                quantity: $hours,
                unitCost: $hourlyRate,
                performedAt: $date,
            );
            $count++;
        }

        $this->command?->info("  → Created {$count} labor cost entries.");
    }

    // ─── Overhead Allocations ───────────────────────────────────────

    private function seedOverheadAllocations(): void
    {
        // Apply per-gallon overhead to lots with volume
        $perGallonRates = OverheadRate::where('allocation_method', 'per_gallon')
            ->where('is_active', true)
            ->get();

        $activeLots = Lot::whereIn('status', ['in_progress', 'aging'])->get();

        $count = 0;
        foreach ($perGallonRates as $rate) {
            foreach ($activeLots as $lot) {
                $amount = bcmul((string) $rate->rate, (string) $lot->volume_gallons, 4);
                if (bccomp($amount, '0', 4) <= 0) {
                    continue;
                }

                $this->costService->recordManualCost(
                    lot: $lot,
                    costType: 'overhead',
                    description: "{$rate->name} allocation ({$rate->allocation_method}: \${$rate->rate}/gal)",
                    amount: $amount,
                    performedBy: $this->winemaker,
                    performedAt: Carbon::now()->subDays(rand(5, 30)),
                );
                $count++;
            }
        }

        $this->command?->info("  → Created {$count} overhead allocation entries.");
    }

    // ─── Bottling COGS ──────────────────────────────────────────────

    private function seedBottlingCogs(): void
    {
        $completedRuns = BottlingRun::where('status', 'completed')
            ->where('bottles_filled', '>', 0)
            ->get();

        $count = 0;
        foreach ($completedRuns as $run) {
            $lot = Lot::find($run->lot_id);
            if (! $lot) {
                continue;
            }

            // Ensure the lot has at least some cost entries
            $hasCosts = LotCostEntry::where('lot_id', $lot->id)->exists();
            if (! $hasCosts) {
                continue;
            }

            try {
                $this->costService->calculateBottlingCogs($lot, $run, $this->winemaker);
                $count++;
            } catch (\Throwable $e) {
                // Skip if COGS calculation fails (missing relationships, etc.)
                $this->command?->warn("  ⚠ COGS calculation failed for lot {$lot->name}: {$e->getMessage()}");
            }
        }

        // Also set prices on SKUs that have cost_per_bottle for margin report
        $skus = CaseGoodsSku::whereNotNull('cost_per_bottle')->get();
        foreach ($skus as $sku) {
            if ($sku->price === null) {
                // Set a realistic retail price: roughly 3-4× COGS
                $multiplier = (string) (rand(28, 42) / 10); // 2.8x to 4.2x
                $price = bcmul((string) $sku->cost_per_bottle, $multiplier, 2);
                $sku->update(['price' => $price]);
            }
        }

        $this->command?->info("  → Generated {$count} COGS summaries for completed bottling runs.");
    }
}
