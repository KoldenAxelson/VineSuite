<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Addition;
use App\Models\Barrel;
use App\Models\BlendTrial;
use App\Models\BlendTrialComponent;
use App\Models\BottlingComponent;
use App\Models\BottlingRun;
use App\Models\Lot;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Vessel;
use App\Models\WorkOrder;
use App\Services\EventLogger;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds realistic production data for the Paso Robles Cellars demo winery.
 *
 * Creates: 40+ lots across 2024/2025 vintages, 24 tanks, 43 barrels,
 * work orders (pending + completed), additions history, transfers,
 * blend trials, and a completed bottling run — all with event log entries.
 *
 * Must be called within a tenant context (inside $tenant->run()).
 */
class ProductionSeeder extends Seeder
{
    private EventLogger $eventLogger;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Vessel> */
    private array $tanks = [];

    /** @var array<string, Vessel> */
    private array $barrelVessels = [];

    /** @var array<string, Lot> */
    private array $lots = [];

    public function run(): void
    {
        $this->eventLogger = app(EventLogger::class);

        $this->loadUsers();
        $this->createVessels();
        $this->createLots();
        $this->seedTransfers();
        $this->seedAdditions();
        $this->seedWorkOrders();
        $this->seedBlendTrials();
        $this->seedBottlingRuns();

        $this->command?->info('Production data seeded successfully.');
    }

    // ─── Users ──────────────────────────────────────────────────────

    private function loadUsers(): void
    {
        $this->users['winemaker'] = User::where('role', 'winemaker')->first();
        $this->users['cellar_hand'] = User::where('role', 'cellar_hand')->first();
        $this->users['admin'] = User::where('email', 'admin@vine.com')->first();
    }

    // ─── Vessels ────────────────────────────────────────────────────

    private function createVessels(): void
    {
        $this->createTanks();
        $this->createBarrels();

        $this->command?->info('  → Created '.count($this->tanks).' tanks and '.count($this->barrelVessels).' barrels.');
    }

    private function createTanks(): void
    {
        $tankDefinitions = [
            // Fermentation tanks (stainless, variable capacity)
            ['name' => 'T-001', 'capacity' => 2500, 'location' => 'Tank Hall', 'material' => 'stainless_steel'],
            ['name' => 'T-002', 'capacity' => 2500, 'location' => 'Tank Hall', 'material' => 'stainless_steel'],
            ['name' => 'T-003', 'capacity' => 1500, 'location' => 'Tank Hall', 'material' => 'stainless_steel'],
            ['name' => 'T-004', 'capacity' => 1500, 'location' => 'Tank Hall', 'material' => 'stainless_steel'],
            ['name' => 'T-005', 'capacity' => 1000, 'location' => 'Tank Hall', 'material' => 'stainless_steel'],
            ['name' => 'T-006', 'capacity' => 1000, 'location' => 'Tank Hall', 'material' => 'stainless_steel'],
            ['name' => 'T-007', 'capacity' => 750, 'location' => 'Tank Hall', 'material' => 'stainless_steel'],
            ['name' => 'T-008', 'capacity' => 750, 'location' => 'Tank Hall', 'material' => 'stainless_steel'],
            ['name' => 'T-009', 'capacity' => 500, 'location' => 'Tank Hall', 'material' => 'stainless_steel'],
            ['name' => 'T-010', 'capacity' => 500, 'location' => 'Tank Hall', 'material' => 'stainless_steel'],
            // Blending/settling tanks
            ['name' => 'T-011', 'capacity' => 300, 'location' => 'Tank Hall', 'material' => 'stainless_steel'],
            ['name' => 'T-012', 'capacity' => 300, 'location' => 'Tank Hall', 'material' => 'stainless_steel'],
            // Cold stabilization
            ['name' => 'T-013', 'capacity' => 2000, 'location' => 'Cold Room', 'material' => 'stainless_steel'],
            ['name' => 'T-014', 'capacity' => 2000, 'location' => 'Cold Room', 'material' => 'stainless_steel'],
            // Outdoor tanks (larger storage)
            ['name' => 'T-015', 'capacity' => 5000, 'location' => 'Outdoor Pad', 'material' => 'stainless_steel'],
            ['name' => 'T-016', 'capacity' => 5000, 'location' => 'Outdoor Pad', 'material' => 'stainless_steel'],
            // Flex tanks
            ['name' => 'FT-001', 'capacity' => 265, 'location' => 'Barrel Room A', 'material' => 'polyethylene'],
            ['name' => 'FT-002', 'capacity' => 265, 'location' => 'Barrel Room A', 'material' => 'polyethylene'],
            // Concrete eggs
            ['name' => 'CE-001', 'capacity' => 158, 'location' => 'Cave', 'material' => 'concrete'],
            ['name' => 'CE-002', 'capacity' => 158, 'location' => 'Cave', 'material' => 'concrete'],
            // Small totes for experimental lots
            ['name' => 'TO-001', 'capacity' => 65, 'location' => 'Crush Pad', 'material' => 'stainless_steel'],
            ['name' => 'TO-002', 'capacity' => 65, 'location' => 'Crush Pad', 'material' => 'stainless_steel'],
            ['name' => 'TO-003', 'capacity' => 65, 'location' => 'Crush Pad', 'material' => 'stainless_steel'],
            ['name' => 'TO-004', 'capacity' => 65, 'location' => 'Crush Pad', 'material' => 'stainless_steel'],
        ];

        foreach ($tankDefinitions as $def) {
            $type = match (true) {
                str_starts_with($def['name'], 'FT') => 'flexitank',
                str_starts_with($def['name'], 'CE') => 'concrete_egg',
                str_starts_with($def['name'], 'TO') => 'tote',
                default => 'tank',
            };

            $vessel = Vessel::create([
                'name' => $def['name'],
                'type' => $type,
                'capacity_gallons' => $def['capacity'],
                'material' => $def['material'],
                'location' => $def['location'],
                'status' => 'empty',
                'purchase_date' => Carbon::now()->subYears(rand(1, 5))->toDateString(),
            ]);

            $this->tanks[$def['name']] = $vessel;
        }
    }

    private function createBarrels(): void
    {
        $cooperages = [
            ['cooperage' => 'François Frères', 'oak_type' => 'french', 'forest' => 'Allier'],
            ['cooperage' => 'François Frères', 'oak_type' => 'french', 'forest' => 'Tronçais'],
            ['cooperage' => 'Seguin Moreau', 'oak_type' => 'french', 'forest' => 'Nevers'],
            ['cooperage' => 'Seguin Moreau', 'oak_type' => 'french', 'forest' => 'Vosges'],
            ['cooperage' => 'Demptos', 'oak_type' => 'french', 'forest' => 'Centre-France'],
            ['cooperage' => 'Tonnellerie Sylvain', 'oak_type' => 'french', 'forest' => 'Bertranges'],
            ['cooperage' => 'Independent Stave', 'oak_type' => 'american', 'forest' => null],
            ['cooperage' => 'World Cooperage', 'oak_type' => 'american', 'forest' => null],
            ['cooperage' => 'Kádár Hungary', 'oak_type' => 'hungarian', 'forest' => null],
        ];

        $toastLevels = ['light', 'medium', 'medium_plus', 'heavy'];
        $rooms = ['Barrel Room A', 'Barrel Room B', 'Cave'];
        $barrelNumber = 1;

        for ($i = 0; $i < 43; $i++) {
            $coop = $cooperages[array_rand($cooperages)];
            $toast = $toastLevels[array_rand($toastLevels)];
            $yearsUsed = $i < 12 ? 0 : ($i < 24 ? rand(1, 2) : rand(2, 5));
            $location = $rooms[array_rand($rooms)];

            $vessel = Vessel::create([
                'name' => sprintf('B-%03d', $barrelNumber),
                'type' => 'barrel',
                'capacity_gallons' => 59.43, // standard Bordeaux barrel
                'material' => 'oak',
                'location' => $location,
                'status' => 'empty',
                'purchase_date' => Carbon::now()->subYears($yearsUsed)->subMonths(rand(0, 6))->toDateString(),
            ]);

            Barrel::create([
                'vessel_id' => $vessel->id,
                'cooperage' => $coop['cooperage'],
                'toast_level' => $toast,
                'oak_type' => $coop['oak_type'],
                'forest_origin' => $coop['forest'],
                'volume_gallons' => 59.43,
                'years_used' => $yearsUsed,
                'qr_code' => sprintf('BRL-%04d', $barrelNumber),
            ]);

            $this->barrelVessels[sprintf('B-%03d', $barrelNumber)] = $vessel;
            $barrelNumber++;
        }
    }

    // ─── Lots ───────────────────────────────────────────────────────

    private function createLots(): void
    {
        // 2024 vintage — further along, some aging/bottled
        $this->createLot('2024 Estate Cabernet Sauvignon - Block A', 'Cabernet Sauvignon', 2024, 'estate', 1800, 'aging', ['vineyard' => 'Estate', 'block' => 'A']);
        $this->createLot('2024 Estate Cabernet Sauvignon - Block C', 'Cabernet Sauvignon', 2024, 'estate', 1200, 'aging', ['vineyard' => 'Estate', 'block' => 'C']);
        $this->createLot('2024 Reserve Cabernet Sauvignon', 'Cabernet Sauvignon', 2024, 'estate', 600, 'aging', ['vineyard' => 'Estate', 'block' => 'A/C Reserve']);
        $this->createLot('2024 Estate Syrah', 'Syrah', 2024, 'estate', 950, 'aging', ['vineyard' => 'Estate', 'block' => 'D']);
        $this->createLot('2024 Estrella Syrah', 'Syrah', 2024, 'purchased', 1100, 'aging', ['grower' => 'Estrella Vineyards', 'vineyard' => 'Paso Robles East']);
        $this->createLot('2024 Estate Grenache', 'Grenache', 2024, 'estate', 720, 'aging', ['vineyard' => 'Estate', 'block' => 'E']);
        $this->createLot('2024 Estate Mourvèdre', 'Mourvèdre', 2024, 'estate', 480, 'aging', ['vineyard' => 'Estate', 'block' => 'F']);
        $this->createLot('2024 Petite Sirah - Willow Creek', 'Petite Sirah', 2024, 'purchased', 850, 'aging', ['grower' => 'Willow Creek Ranch', 'vineyard' => 'Willow Creek']);
        $this->createLot('2024 Zinfandel - Westside', 'Zinfandel', 2024, 'estate', 640, 'aging', ['vineyard' => 'Estate', 'block' => 'G']);
        $this->createLot('2024 Estate Chardonnay', 'Chardonnay', 2024, 'estate', 1050, 'aging', ['vineyard' => 'Estate', 'block' => 'B']);
        $this->createLot('2024 Viognier', 'Viognier', 2024, 'estate', 380, 'aging', ['vineyard' => 'Estate', 'block' => 'H']);
        $this->createLot('2024 Tempranillo', 'Tempranillo', 2024, 'purchased', 560, 'aging', ['grower' => 'Cuesta Ridge', 'vineyard' => 'Templeton Gap']);
        $this->createLot('2024 Rosé Blend', 'Grenache', 2024, 'estate', 0, 'bottled', ['vineyard' => 'Estate', 'block' => 'E saignée']);
        $this->createLot('2024 Paso White Blend', 'Viognier', 2024, 'estate', 0, 'bottled', ['vineyard' => 'Estate', 'block' => 'B+H']);

        // 2025 vintage — harvest just completed, in-progress fermentations
        $this->createLot('2025 Estate Cabernet Sauvignon - Block A', 'Cabernet Sauvignon', 2025, 'estate', 2100, 'in_progress', ['vineyard' => 'Estate', 'block' => 'A']);
        $this->createLot('2025 Estate Cabernet Sauvignon - Block C', 'Cabernet Sauvignon', 2025, 'estate', 1400, 'in_progress', ['vineyard' => 'Estate', 'block' => 'C']);
        $this->createLot('2025 Estate Cabernet Sauvignon - Block D', 'Cabernet Sauvignon', 2025, 'estate', 800, 'in_progress', ['vineyard' => 'Estate', 'block' => 'D']);
        $this->createLot('2025 Estate Syrah', 'Syrah', 2025, 'estate', 1050, 'in_progress', ['vineyard' => 'Estate', 'block' => 'D']);
        $this->createLot('2025 James Berry Syrah', 'Syrah', 2025, 'purchased', 780, 'in_progress', ['grower' => 'James Berry Vineyard', 'vineyard' => 'Paso Robles Willow Creek']);
        $this->createLot('2025 Estate Grenache', 'Grenache', 2025, 'estate', 850, 'in_progress', ['vineyard' => 'Estate', 'block' => 'E']);
        $this->createLot('2025 Estate Mourvèdre', 'Mourvèdre', 2025, 'estate', 520, 'in_progress', ['vineyard' => 'Estate', 'block' => 'F']);
        $this->createLot('2025 Petite Sirah - Willow Creek', 'Petite Sirah', 2025, 'purchased', 920, 'in_progress', ['grower' => 'Willow Creek Ranch', 'vineyard' => 'Willow Creek']);
        $this->createLot('2025 Zinfandel - Westside', 'Zinfandel', 2025, 'estate', 710, 'in_progress', ['vineyard' => 'Estate', 'block' => 'G']);
        $this->createLot('2025 Merlot - York Mountain', 'Merlot', 2025, 'purchased', 600, 'in_progress', ['grower' => 'York Mountain Vineyards', 'vineyard' => 'York Mountain']);
        $this->createLot('2025 Estate Chardonnay', 'Chardonnay', 2025, 'estate', 1200, 'in_progress', ['vineyard' => 'Estate', 'block' => 'B']);
        $this->createLot('2025 Viognier', 'Viognier', 2025, 'estate', 420, 'in_progress', ['vineyard' => 'Estate', 'block' => 'H']);

        // 2023 vintage — fully bottled
        $this->createLot('2023 Reserve Cabernet Sauvignon', 'Cabernet Sauvignon', 2023, 'estate', 0, 'bottled', ['vineyard' => 'Estate', 'block' => 'A/C']);
        $this->createLot('2023 Estate Syrah', 'Syrah', 2023, 'estate', 0, 'bottled', ['vineyard' => 'Estate', 'block' => 'D']);
        $this->createLot('2023 GSM Blend', 'Grenache', 2023, 'estate', 0, 'bottled', ['vineyard' => 'Estate', 'block' => 'E/F']);
        $this->createLot('2023 Zinfandel', 'Zinfandel', 2023, 'estate', 0, 'bottled', ['vineyard' => 'Estate', 'block' => 'G']);
        $this->createLot('2023 Estate Chardonnay', 'Chardonnay', 2023, 'estate', 0, 'bottled', ['vineyard' => 'Estate', 'block' => 'B']);

        // 2022 vintage — sold/archived
        $this->createLot('2022 Reserve Cabernet Sauvignon', 'Cabernet Sauvignon', 2022, 'estate', 0, 'sold', ['vineyard' => 'Estate', 'block' => 'A']);
        $this->createLot('2022 Estate Syrah', 'Syrah', 2022, 'estate', 0, 'sold', ['vineyard' => 'Estate', 'block' => 'D']);
        $this->createLot('2022 GSM Blend', 'Grenache', 2022, 'estate', 0, 'archived', ['vineyard' => 'Estate', 'block' => 'E/F']);

        // Experimental micro-lots
        $this->createLot('2025 Pét-Nat Grenache', 'Grenache', 2025, 'estate', 55, 'in_progress', ['vineyard' => 'Estate', 'block' => 'E micro']);
        $this->createLot('2025 Orange Viognier', 'Viognier', 2025, 'estate', 60, 'in_progress', ['vineyard' => 'Estate', 'block' => 'H skin-contact']);
        $this->createLot('2025 Co-Ferment Syrah/Viognier', 'Syrah', 2025, 'estate', 130, 'in_progress', ['vineyard' => 'Estate', 'block' => 'D+H co-ferment']);
        $this->createLot('2024 Late Harvest Viognier', 'Viognier', 2024, 'estate', 120, 'aging', ['vineyard' => 'Estate', 'block' => 'H late pick']);
        $this->createLot('2025 Piquette', 'Grenache', 2025, 'estate', 45, 'in_progress', ['vineyard' => 'Estate', 'block' => 'E pomace']);

        $this->command?->info('  → Created '.count($this->lots).' lots across 4 vintages.');
    }

    /**
     * @param  array<string, mixed>  $sourceDetails
     */
    private function createLot(
        string $name,
        string $variety,
        int $vintage,
        string $sourceType,
        float $volume,
        string $status,
        array $sourceDetails
    ): Lot {
        $createdAt = match (true) {
            $vintage <= 2022 => Carbon::create($vintage, rand(9, 10), rand(1, 28)),
            $vintage === 2023 => Carbon::create(2023, rand(9, 10), rand(1, 28)),
            $vintage === 2024 => Carbon::create(2024, rand(8, 10), rand(1, 28)),
            default => Carbon::create(2025, rand(8, 10), rand(1, 28)),
        };

        $lot = Lot::create([
            'name' => $name,
            'variety' => $variety,
            'vintage' => $vintage,
            'source_type' => $sourceType,
            'source_details' => $sourceDetails,
            'volume_gallons' => $volume,
            'status' => $status,
        ]);

        // Log creation event
        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $lot->id,
            operationType: 'lot_created',
            payload: [
                'name' => $name,
                'variety' => $variety,
                'vintage' => $vintage,
                'source' => $sourceType,
                'initial_volume' => $volume > 0 ? $volume : rand(400, 2000),
            ],
            performedBy: $this->users['winemaker']->id,
            performedAt: $createdAt,
        );

        $this->lots[$name] = $lot;

        return $lot;
    }

    // ─── Transfers ──────────────────────────────────────────────────

    private function seedTransfers(): void
    {
        $winemaker = $this->users['winemaker'];
        $cellarHand = $this->users['cellar_hand'];
        $count = 0;

        // 2024 Cab Block A → barrel down to B-001 through B-012 (new French oak)
        $cabBlockA = $this->lots['2024 Estate Cabernet Sauvignon - Block A'];
        $barrelNames = array_slice(array_keys($this->barrelVessels), 0, 10);
        foreach ($barrelNames as $bName) {
            $barrel = $this->barrelVessels[$bName];
            $this->createTransfer($cabBlockA, $this->tanks['T-001'], $barrel, 58.0, 'pump', $cellarHand, Carbon::create(2024, 11, 15)->addDays(rand(0, 3)));
            $count++;
        }

        // 2024 Syrah → barrels B-013 through B-020
        $syrah = $this->lots['2024 Estate Syrah'];
        $syrahBarrels = array_slice(array_keys($this->barrelVessels), 12, 6);
        foreach ($syrahBarrels as $bName) {
            $barrel = $this->barrelVessels[$bName];
            $this->createTransfer($syrah, $this->tanks['T-003'], $barrel, 58.5, 'pump', $cellarHand, Carbon::create(2024, 11, 20)->addDays(rand(0, 3)));
            $count++;
        }

        // 2024 Chardonnay → concrete eggs for partial fermentation
        $chard = $this->lots['2024 Estate Chardonnay'];
        $this->createTransfer($chard, $this->tanks['T-005'], $this->tanks['CE-001'], 150.0, 'pump', $cellarHand, Carbon::create(2024, 9, 25));
        $this->createTransfer($chard, $this->tanks['T-005'], $this->tanks['CE-002'], 150.0, 'pump', $cellarHand, Carbon::create(2024, 9, 25));
        $count += 2;

        // 2025 lots filling into tanks (recent crush)
        $tankAssignments = [
            '2025 Estate Cabernet Sauvignon - Block A' => 'T-001',
            '2025 Estate Cabernet Sauvignon - Block C' => 'T-002',
            '2025 Estate Cabernet Sauvignon - Block D' => 'T-007',
            '2025 Estate Syrah' => 'T-003',
            '2025 James Berry Syrah' => 'T-004',
            '2025 Estate Grenache' => 'T-005',
            '2025 Estate Mourvèdre' => 'T-006',
            '2025 Petite Sirah - Willow Creek' => 'T-008',
            '2025 Zinfandel - Westside' => 'T-009',
            '2025 Merlot - York Mountain' => 'T-010',
            '2025 Estate Chardonnay' => 'T-013',
            '2025 Viognier' => 'T-011',
        ];

        foreach ($tankAssignments as $lotName => $tankName) {
            $lot = $this->lots[$lotName];
            $tank = $this->tanks[$tankName];

            // Mark tank as in use and attach lot-vessel pivot
            $tank->update(['status' => 'in_use']);
            $tank->lots()->attach($lot->id, [
                'id' => (string) Str::uuid(),
                'volume_gallons' => $lot->volume_gallons,
                'filled_at' => Carbon::create(2025, rand(9, 10), rand(1, 15)),
            ]);
        }

        // Experimental lots in totes
        $toteAssignments = [
            '2025 Pét-Nat Grenache' => 'TO-001',
            '2025 Orange Viognier' => 'TO-002',
            '2025 Co-Ferment Syrah/Viognier' => 'TO-003',
            '2025 Piquette' => 'TO-004',
        ];

        foreach ($toteAssignments as $lotName => $toteName) {
            $lot = $this->lots[$lotName];
            $tote = $this->tanks[$toteName];
            $tote->update(['status' => 'in_use']);
            $tote->lots()->attach($lot->id, [
                'id' => (string) Str::uuid(),
                'volume_gallons' => $lot->volume_gallons,
                'filled_at' => Carbon::create(2025, 10, rand(1, 15)),
            ]);
        }

        // Mark barrel vessels as in_use for the ones with wine
        foreach (array_merge($barrelNames, $syrahBarrels) as $bName) {
            $this->barrelVessels[$bName]->update(['status' => 'in_use']);
        }

        $this->command?->info("  → Created {$count} transfers with event logs.");
    }

    private function createTransfer(
        Lot $lot,
        Vessel $from,
        Vessel $to,
        float $volume,
        string $type,
        User $performer,
        Carbon $performedAt
    ): Transfer {
        $variance = round(rand(0, 15) / 10, 2); // 0.0 - 1.5 gal variance

        $transfer = Transfer::create([
            'lot_id' => $lot->id,
            'from_vessel_id' => $from->id,
            'to_vessel_id' => $to->id,
            'volume_gallons' => $volume,
            'transfer_type' => $type,
            'variance_gallons' => $variance,
            'performed_by' => $performer->id,
            'performed_at' => $performedAt,
        ]);

        // Attach to destination vessel pivot
        $to->lots()->attach($lot->id, [
            'id' => (string) Str::uuid(),
            'volume_gallons' => $volume,
            'filled_at' => $performedAt,
        ]);

        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $lot->id,
            operationType: 'transfer_executed',
            payload: [
                'lot_id' => $lot->id,
                'from_vessel' => $from->name,
                'to_vessel' => $to->name,
                'volume' => $volume,
                'variance' => $variance,
                'transfer_type' => $type,
            ],
            performedBy: $performer->id,
            performedAt: $performedAt,
        );

        return $transfer;
    }

    // ─── Additions ──────────────────────────────────────────────────

    private function seedAdditions(): void
    {
        $cellarHand = $this->users['cellar_hand'];
        $winemaker = $this->users['winemaker'];
        $count = 0;

        // SO2 additions to all 2024 aging lots (standard cellar practice)
        $agingLots = collect($this->lots)->filter(fn (Lot $l) => $l->status === 'aging');
        foreach ($agingLots as $lot) {
            // Initial SO2 post-fermentation
            $this->createAddition($lot, 'sulfite', 'Potassium Metabisulfite', 25.0, 'ppm', $cellarHand, Carbon::create(2024, 11, rand(1, 30)));
            $count++;

            // Maintenance SO2 (every ~2 months)
            for ($m = 1; $m <= 3; $m++) {
                $this->createAddition($lot, 'sulfite', 'Potassium Metabisulfite', rand(15, 25), 'ppm', $cellarHand, Carbon::create(2024, 11, 1)->addMonths($m * 2)->addDays(rand(0, 7)));
                $count++;
            }
        }

        // Nutrient additions to 2025 fermenting lots
        $fermentingLots = collect($this->lots)->filter(fn (Lot $l) => $l->vintage === 2025 && $l->status === 'in_progress');
        foreach ($fermentingLots as $lot) {
            // Yeast rehydration nutrient
            $this->createAddition($lot, 'nutrient', 'Go-Ferm Protect Evolution', 0.3, 'g/L', $winemaker, Carbon::create(2025, rand(9, 10), rand(1, 10)));
            $count++;

            // DAP at 1/3 sugar depletion
            $this->createAddition($lot, 'nutrient', 'DAP (Diammonium Phosphate)', 0.5, 'g/L', $cellarHand, Carbon::create(2025, rand(9, 10), rand(10, 20)));
            $count++;

            // Fermaid O at 2/3 sugar depletion
            $this->createAddition($lot, 'nutrient', 'Fermaid O', 0.4, 'g/L', $cellarHand, Carbon::create(2025, rand(9, 10), rand(15, 28)));
            $count++;
        }

        // Fining on 2024 Chardonnay (bentonite for protein stability)
        $chard = $this->lots['2024 Estate Chardonnay'];
        $this->createAddition($chard, 'fining', 'Bentonite (sodium)', 0.5, 'g/L', $winemaker, Carbon::create(2025, 1, 15));
        $count++;

        // Acid adjustment on a couple of lots
        $this->createAddition($this->lots['2024 Estate Grenache'], 'acid', 'Tartaric Acid', 1.0, 'g/L', $winemaker, Carbon::create(2024, 10, 20));
        $this->createAddition($this->lots['2025 Estate Grenache'], 'acid', 'Tartaric Acid', 0.75, 'g/L', $winemaker, Carbon::create(2025, 10, 5));
        $count += 2;

        // Enzyme addition for a Petite Sirah (color extraction)
        $this->createAddition($this->lots['2025 Petite Sirah - Willow Creek'], 'enzyme', 'Lallzyme EX-V', 0.03, 'g/L', $winemaker, Carbon::create(2025, 9, 20));
        $count++;

        $this->command?->info("  → Created {$count} additions with event logs.");
    }

    private function createAddition(
        Lot $lot,
        string $type,
        string $product,
        float $rate,
        string $rateUnit,
        User $performer,
        Carbon $performedAt
    ): Addition {
        // Calculate total amount based on lot volume and rate
        $volumeLiters = (float) $lot->volume_gallons * 3.78541;
        $totalAmount = round($rate * $volumeLiters / 1000, 2); // rough approximation

        if ($totalAmount < 0.01) {
            $totalAmount = round($rate * max((float) $lot->volume_gallons, 500) * 3.78541 / 1000, 2);
        }

        $totalUnit = match ($rateUnit) {
            'ppm' => 'g',
            'g/L' => 'g',
            'mg/L' => 'g',
            default => 'g',
        };

        $addition = Addition::create([
            'lot_id' => $lot->id,
            'addition_type' => $type,
            'product_name' => $product,
            'rate' => $rate,
            'rate_unit' => $rateUnit,
            'total_amount' => $totalAmount,
            'total_unit' => $totalUnit,
            'performed_by' => $performer->id,
            'performed_at' => $performedAt,
        ]);

        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $lot->id,
            operationType: 'addition_made',
            payload: [
                'lot_id' => $lot->id,
                'type' => $type,
                'product' => $product,
                'rate' => $rate,
                'rate_unit' => $rateUnit,
                'amount' => $totalAmount,
                'unit' => $totalUnit,
            ],
            performedBy: $performer->id,
            performedAt: $performedAt,
        );

        return $addition;
    }

    // ─── Work Orders ────────────────────────────────────────────────

    private function seedWorkOrders(): void
    {
        $winemaker = $this->users['winemaker'];
        $cellarHand = $this->users['cellar_hand'];
        $count = 0;

        // Completed work orders for 2024 lots (historical)
        $completedOps = [
            ['lot' => '2024 Estate Cabernet Sauvignon - Block A', 'op' => 'Punch Down', 'due' => '2024-10-01', 'completed' => '2024-10-01'],
            ['lot' => '2024 Estate Cabernet Sauvignon - Block A', 'op' => 'Pump Over', 'due' => '2024-10-05', 'completed' => '2024-10-05'],
            ['lot' => '2024 Estate Cabernet Sauvignon - Block A', 'op' => 'Press', 'due' => '2024-10-20', 'completed' => '2024-10-21'],
            ['lot' => '2024 Estate Cabernet Sauvignon - Block A', 'op' => 'Barrel Down', 'due' => '2024-11-15', 'completed' => '2024-11-15'],
            ['lot' => '2024 Estate Cabernet Sauvignon - Block A', 'op' => 'Add SO2', 'due' => '2024-11-20', 'completed' => '2024-11-20'],
            ['lot' => '2024 Estate Cabernet Sauvignon - Block A', 'op' => 'Rack', 'due' => '2025-01-15', 'completed' => '2025-01-16'],
            ['lot' => '2024 Estate Syrah', 'op' => 'Punch Down', 'due' => '2024-10-03', 'completed' => '2024-10-03'],
            ['lot' => '2024 Estate Syrah', 'op' => 'Press', 'due' => '2024-10-22', 'completed' => '2024-10-22'],
            ['lot' => '2024 Estate Syrah', 'op' => 'Barrel Down', 'due' => '2024-11-20', 'completed' => '2024-11-20'],
            ['lot' => '2024 Estate Chardonnay', 'op' => 'Inoculate', 'due' => '2024-09-20', 'completed' => '2024-09-20'],
            ['lot' => '2024 Estate Chardonnay', 'op' => 'Fine', 'due' => '2025-01-15', 'completed' => '2025-01-17'],
        ];

        foreach ($completedOps as $op) {
            WorkOrder::create([
                'operation_type' => $op['op'],
                'lot_id' => $this->lots[$op['lot']]->id,
                'assigned_to' => $cellarHand->id,
                'due_date' => $op['due'],
                'status' => 'completed',
                'priority' => 'normal',
                'completed_at' => Carbon::parse($op['completed']),
                'completed_by' => $cellarHand->id,
                'completion_notes' => 'Completed as scheduled.',
            ]);
            $count++;
        }

        // Pending work orders for 2025 lots (active cellar operations)
        $pendingOps = [
            ['lot' => '2025 Estate Cabernet Sauvignon - Block A', 'op' => 'Punch Down', 'due' => Carbon::now()->addDays(1), 'priority' => 'high'],
            ['lot' => '2025 Estate Cabernet Sauvignon - Block A', 'op' => 'Pump Over', 'due' => Carbon::now()->addDays(2), 'priority' => 'high'],
            ['lot' => '2025 Estate Cabernet Sauvignon - Block C', 'op' => 'Punch Down', 'due' => Carbon::now()->addDays(1), 'priority' => 'high'],
            ['lot' => '2025 Estate Syrah', 'op' => 'Punch Down', 'due' => Carbon::now()->addDays(1), 'priority' => 'normal'],
            ['lot' => '2025 Estate Syrah', 'op' => 'Sample', 'due' => Carbon::now()->addDays(3), 'priority' => 'normal'],
            ['lot' => '2025 Estate Grenache', 'op' => 'Pump Over', 'due' => Carbon::now()->addDays(2), 'priority' => 'normal'],
            ['lot' => '2025 Estate Mourvèdre', 'op' => 'Punch Down', 'due' => Carbon::now()->addDays(1), 'priority' => 'normal'],
            ['lot' => '2025 Petite Sirah - Willow Creek', 'op' => 'Pump Over', 'due' => Carbon::now()->addDays(2), 'priority' => 'normal'],
            ['lot' => '2025 Zinfandel - Westside', 'op' => 'Sample', 'due' => Carbon::now()->addDays(4), 'priority' => 'low'],
            ['lot' => '2025 Estate Chardonnay', 'op' => 'Sample', 'due' => Carbon::now()->addDays(5), 'priority' => 'low'],
            ['lot' => '2025 Viognier', 'op' => 'Sample', 'due' => Carbon::now()->addDays(5), 'priority' => 'low'],
            // Barrel maintenance for 2024 aging lots
            ['lot' => '2024 Estate Cabernet Sauvignon - Block A', 'op' => 'Top', 'due' => Carbon::now()->addDays(7), 'priority' => 'normal'],
            ['lot' => '2024 Estate Cabernet Sauvignon - Block A', 'op' => 'Add SO2', 'due' => Carbon::now()->addDays(14), 'priority' => 'normal'],
            ['lot' => '2024 Estate Syrah', 'op' => 'Top', 'due' => Carbon::now()->addDays(7), 'priority' => 'normal'],
            ['lot' => '2024 Estate Syrah', 'op' => 'Rack', 'due' => Carbon::now()->addDays(21), 'priority' => 'normal'],
            ['lot' => '2024 Estate Chardonnay', 'op' => 'Filter', 'due' => Carbon::now()->addDays(10), 'priority' => 'high'],
        ];

        foreach ($pendingOps as $op) {
            WorkOrder::create([
                'operation_type' => $op['op'],
                'lot_id' => $this->lots[$op['lot']]->id,
                'assigned_to' => $cellarHand->id,
                'due_date' => $op['due'],
                'status' => 'pending',
                'priority' => $op['priority'],
            ]);
            $count++;
        }

        // A couple of in-progress work orders
        WorkOrder::create([
            'operation_type' => 'Transfer',
            'lot_id' => $this->lots['2025 Merlot - York Mountain']->id,
            'assigned_to' => $cellarHand->id,
            'due_date' => Carbon::now(),
            'status' => 'in_progress',
            'priority' => 'high',
            'notes' => 'Moving from T-010 to flex tanks for MLF.',
        ]);
        $count++;

        // One overdue work order
        WorkOrder::create([
            'operation_type' => 'Sample',
            'lot_id' => $this->lots['2024 Petite Sirah - Willow Creek']->id,
            'assigned_to' => $cellarHand->id,
            'due_date' => Carbon::now()->subDays(3),
            'status' => 'pending',
            'priority' => 'normal',
            'notes' => 'Check free SO2 before spring racking.',
        ]);
        $count++;

        $this->command?->info("  → Created {$count} work orders (completed + pending).");
    }

    // ─── Blend Trials ───────────────────────────────────────────────

    private function seedBlendTrials(): void
    {
        $winemaker = $this->users['winemaker'];

        // 2024 GSM Blend Trial (Grenache/Syrah/Mourvèdre) — draft, being worked on
        $gsmTrial = BlendTrial::create([
            'name' => '2024 Adelaida GSM Blend Trial #1',
            'status' => 'draft',
            'version' => 2,
            'variety_composition' => [
                'Grenache' => 52,
                'Syrah' => 30,
                'Mourvèdre' => 18,
            ],
            'ttb_label_variety' => null, // GSM blend — no single variety ≥75%
            'total_volume_gallons' => 500,
            'created_by' => $winemaker->id,
            'notes' => 'Revised from v1 — increased Mourvèdre for more structure. Tasted 3/1, revisit 3/15.',
        ]);

        BlendTrialComponent::create([
            'blend_trial_id' => $gsmTrial->id,
            'source_lot_id' => $this->lots['2024 Estate Grenache']->id,
            'percentage' => 52,
            'volume_gallons' => 260,
        ]);
        BlendTrialComponent::create([
            'blend_trial_id' => $gsmTrial->id,
            'source_lot_id' => $this->lots['2024 Estate Syrah']->id,
            'percentage' => 30,
            'volume_gallons' => 150,
        ]);
        BlendTrialComponent::create([
            'blend_trial_id' => $gsmTrial->id,
            'source_lot_id' => $this->lots['2024 Estate Mourvèdre']->id,
            'percentage' => 18,
            'volume_gallons' => 90,
        ]);

        // 2024 Reserve Cab blend — finalized
        $cabReserveTrial = BlendTrial::create([
            'name' => '2024 Reserve Cabernet Blend Trial #1',
            'status' => 'finalized',
            'version' => 1,
            'variety_composition' => [
                'Cabernet Sauvignon' => 85,
                'Petite Sirah' => 10,
                'Syrah' => 5,
            ],
            'ttb_label_variety' => 'Cabernet Sauvignon',
            'total_volume_gallons' => 600,
            'resulting_lot_id' => $this->lots['2024 Reserve Cabernet Sauvignon']->id,
            'created_by' => $winemaker->id,
            'finalized_at' => Carbon::create(2025, 2, 1),
            'notes' => 'Final blend approved by winemaker. Petite Sirah adds color depth, Syrah adds spice.',
        ]);

        BlendTrialComponent::create([
            'blend_trial_id' => $cabReserveTrial->id,
            'source_lot_id' => $this->lots['2024 Estate Cabernet Sauvignon - Block A']->id,
            'percentage' => 50,
            'volume_gallons' => 300,
        ]);
        BlendTrialComponent::create([
            'blend_trial_id' => $cabReserveTrial->id,
            'source_lot_id' => $this->lots['2024 Estate Cabernet Sauvignon - Block C']->id,
            'percentage' => 35,
            'volume_gallons' => 210,
        ]);
        BlendTrialComponent::create([
            'blend_trial_id' => $cabReserveTrial->id,
            'source_lot_id' => $this->lots['2024 Petite Sirah - Willow Creek']->id,
            'percentage' => 10,
            'volume_gallons' => 60,
        ]);
        BlendTrialComponent::create([
            'blend_trial_id' => $cabReserveTrial->id,
            'source_lot_id' => $this->lots['2024 Estrella Syrah']->id,
            'percentage' => 5,
            'volume_gallons' => 30,
        ]);

        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $this->lots['2024 Reserve Cabernet Sauvignon']->id,
            operationType: 'blend_finalized',
            payload: [
                'new_lot_id' => $this->lots['2024 Reserve Cabernet Sauvignon']->id,
                'sources' => [
                    ['lot_id' => $this->lots['2024 Estate Cabernet Sauvignon - Block A']->id, 'pct' => 50, 'volume' => 300],
                    ['lot_id' => $this->lots['2024 Estate Cabernet Sauvignon - Block C']->id, 'pct' => 35, 'volume' => 210],
                    ['lot_id' => $this->lots['2024 Petite Sirah - Willow Creek']->id, 'pct' => 10, 'volume' => 60],
                    ['lot_id' => $this->lots['2024 Estrella Syrah']->id, 'pct' => 5, 'volume' => 30],
                ],
            ],
            performedBy: $winemaker->id,
            performedAt: Carbon::create(2025, 2, 1),
        );

        $this->command?->info('  → Created 2 blend trials (1 draft, 1 finalized).');
    }

    // ─── Bottling Runs ──────────────────────────────────────────────

    private function seedBottlingRuns(): void
    {
        $winemaker = $this->users['winemaker'];

        // 2024 Rosé — completed bottling
        $rose = $this->lots['2024 Rosé Blend'];
        $roseBottling = BottlingRun::create([
            'lot_id' => $rose->id,
            'bottle_format' => '750ml',
            'bottles_filled' => 480,
            'bottles_breakage' => 3,
            'waste_percent' => 1.2,
            'volume_bottled_gallons' => 95.0,
            'status' => 'completed',
            'sku' => 'PRC-2024-ROSE-750',
            'cases_produced' => 40,
            'bottles_per_case' => 12,
            'performed_by' => $winemaker->id,
            'bottled_at' => Carbon::create(2025, 2, 15),
            'completed_at' => Carbon::create(2025, 2, 15),
            'notes' => '2024 Rosé of Grenache. Bright salmon color, crisp acidity.',
        ]);

        BottlingComponent::create([
            'bottling_run_id' => $roseBottling->id,
            'component_type' => 'bottle',
            'product_name' => '750ml Burgundy Clear',
            'quantity_used' => 480,
            'quantity_wasted' => 3,
            'unit' => 'each',
        ]);
        BottlingComponent::create([
            'bottling_run_id' => $roseBottling->id,
            'component_type' => 'cork',
            'product_name' => 'DIAM 5 Natural',
            'quantity_used' => 480,
            'quantity_wasted' => 5,
            'unit' => 'each',
        ]);

        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $rose->id,
            operationType: 'bottling_completed',
            payload: [
                'lot_id' => $rose->id,
                'format' => '750ml',
                'bottles' => 480,
                'waste_pct' => 1.2,
                'cases' => 40,
                'sku' => 'PRC-2024-ROSE-750',
            ],
            performedBy: $winemaker->id,
            performedAt: Carbon::create(2025, 2, 15),
        );

        // 2024 White Blend — completed bottling
        $white = $this->lots['2024 Paso White Blend'];
        $whiteBottling = BottlingRun::create([
            'lot_id' => $white->id,
            'bottle_format' => '750ml',
            'bottles_filled' => 600,
            'bottles_breakage' => 2,
            'waste_percent' => 0.9,
            'volume_bottled_gallons' => 118.8,
            'status' => 'completed',
            'sku' => 'PRC-2024-WHT-750',
            'cases_produced' => 50,
            'bottles_per_case' => 12,
            'performed_by' => $winemaker->id,
            'bottled_at' => Carbon::create(2025, 3, 1),
            'completed_at' => Carbon::create(2025, 3, 1),
            'notes' => '2024 Paso Robles White — 60% Viognier, 40% Chardonnay.',
        ]);

        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $white->id,
            operationType: 'bottling_completed',
            payload: [
                'lot_id' => $white->id,
                'format' => '750ml',
                'bottles' => 600,
                'waste_pct' => 0.9,
                'cases' => 50,
                'sku' => 'PRC-2024-WHT-750',
            ],
            performedBy: $winemaker->id,
            performedAt: Carbon::create(2025, 3, 1),
        );

        // 2023 Reserve Cab — completed bottling (past vintage)
        $cabReserve23 = $this->lots['2023 Reserve Cabernet Sauvignon'];
        BottlingRun::create([
            'lot_id' => $cabReserve23->id,
            'bottle_format' => '750ml',
            'bottles_filled' => 1200,
            'bottles_breakage' => 5,
            'waste_percent' => 1.5,
            'volume_bottled_gallons' => 237.6,
            'status' => 'completed',
            'sku' => 'PRC-2023-RCAB-750',
            'cases_produced' => 100,
            'bottles_per_case' => 12,
            'performed_by' => $winemaker->id,
            'bottled_at' => Carbon::create(2024, 8, 20),
            'completed_at' => Carbon::create(2024, 8, 20),
        ]);

        // Planned bottling for 2024 Chardonnay (upcoming)
        BottlingRun::create([
            'lot_id' => $this->lots['2024 Estate Chardonnay']->id,
            'bottle_format' => '750ml',
            'bottles_filled' => 0,
            'bottles_breakage' => 0,
            'waste_percent' => 0,
            'volume_bottled_gallons' => 0,
            'status' => 'planned',
            'sku' => 'PRC-2024-CHRD-750',
            'bottles_per_case' => 12,
            'performed_by' => $winemaker->id,
            'bottled_at' => Carbon::now()->addDays(30),
            'notes' => 'Target: ~850 gal. Schedule after final filtration.',
        ]);

        $this->command?->info('  → Created 4 bottling runs (3 completed, 1 planned).');
    }
}
