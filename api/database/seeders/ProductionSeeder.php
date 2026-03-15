<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Addition;
use App\Models\Barrel;
use App\Models\BlendTrial;
use App\Models\BlendTrialComponent;
use App\Models\BottlingComponent;
use App\Models\BottlingRun;
use App\Models\FermentationEntry;
use App\Models\FermentationRound;
use App\Models\LabAnalysis;
use App\Models\Lot;
use App\Models\SensoryNote;
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
        $this->seedLabAnalyses();
        $this->seedFermentationData();
        $this->seedSensoryNotes();

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

    // ─── Lab Analyses ────────────────────────────────────────────────

    private function seedLabAnalyses(): void
    {
        $winemaker = $this->users['winemaker'];
        $count = 0;

        // Lab analysis histories for key lots — realistic readings over time
        // 2024 Estate Cab Block A — aging, regular monitoring
        $cabA = $this->lots['2024 Estate Cabernet Sauvignon - Block A'];
        $cabAReadings = [
            ['date' => '2024-10-01', 'tests' => ['pH' => 3.52, 'TA' => 6.8, 'free_SO2' => 8, 'total_SO2' => 22]],
            ['date' => '2024-10-15', 'tests' => ['pH' => 3.55, 'TA' => 6.5, 'VA' => 0.02, 'free_SO2' => 6]],
            ['date' => '2024-11-01', 'tests' => ['pH' => 3.58, 'TA' => 6.2, 'VA' => 0.03, 'alcohol' => 14.2, 'residual_sugar' => 0.8]],
            ['date' => '2024-12-01', 'tests' => ['pH' => 3.60, 'TA' => 6.0, 'VA' => 0.04, 'free_SO2' => 28, 'total_SO2' => 65, 'malic_acid' => 0.02]],
            ['date' => '2025-02-01', 'tests' => ['pH' => 3.62, 'TA' => 5.9, 'VA' => 0.05, 'free_SO2' => 24, 'total_SO2' => 58]],
            ['date' => '2025-04-01', 'tests' => ['pH' => 3.63, 'TA' => 5.8, 'VA' => 0.05, 'free_SO2' => 20, 'total_SO2' => 52]],
        ];
        $count += $this->createLabHistory($cabA, $cabAReadings, $winemaker, 'manual');

        // 2024 Estate Syrah — aging
        $syrah = $this->lots['2024 Estate Syrah'];
        $syrahReadings = [
            ['date' => '2024-10-05', 'tests' => ['pH' => 3.68, 'TA' => 5.9, 'free_SO2' => 6]],
            ['date' => '2024-10-20', 'tests' => ['pH' => 3.70, 'TA' => 5.7, 'VA' => 0.03, 'alcohol' => 14.8]],
            ['date' => '2024-11-15', 'tests' => ['pH' => 3.72, 'TA' => 5.5, 'VA' => 0.04, 'residual_sugar' => 0.5, 'malic_acid' => 0.01]],
            ['date' => '2025-01-15', 'tests' => ['pH' => 3.73, 'TA' => 5.4, 'VA' => 0.05, 'free_SO2' => 26, 'total_SO2' => 60]],
            ['date' => '2025-03-15', 'tests' => ['pH' => 3.74, 'TA' => 5.3, 'VA' => 0.06, 'free_SO2' => 22, 'total_SO2' => 54]],
        ];
        $count += $this->createLabHistory($syrah, $syrahReadings, $winemaker, 'manual');

        // 2024 Estate Chardonnay — white wine, different profile
        $chard = $this->lots['2024 Estate Chardonnay'];
        $chardReadings = [
            ['date' => '2024-09-20', 'tests' => ['pH' => 3.28, 'TA' => 7.2, 'free_SO2' => 30]],
            ['date' => '2024-10-10', 'tests' => ['pH' => 3.30, 'TA' => 7.0, 'VA' => 0.01, 'alcohol' => 13.5, 'residual_sugar' => 1.2]],
            ['date' => '2024-11-15', 'tests' => ['pH' => 3.32, 'TA' => 6.8, 'VA' => 0.02, 'free_SO2' => 35, 'total_SO2' => 85, 'turbidity' => 15.0]],
            ['date' => '2025-01-15', 'tests' => ['pH' => 3.33, 'TA' => 6.7, 'VA' => 0.02, 'free_SO2' => 30, 'total_SO2' => 78, 'turbidity' => 3.2]],
        ];
        $count += $this->createLabHistory($chard, $chardReadings, $winemaker, 'manual');

        // 2025 Estate Cab Block A — in-progress, early readings
        $cab25A = $this->lots['2025 Estate Cabernet Sauvignon - Block A'];
        $cab25AReadings = [
            ['date' => '2025-09-15', 'tests' => ['pH' => 3.45, 'TA' => 7.5]],
            ['date' => '2025-10-01', 'tests' => ['pH' => 3.50, 'TA' => 7.0, 'VA' => 0.01]],
        ];
        $count += $this->createLabHistory($cab25A, $cab25AReadings, $winemaker, 'manual');

        // 2025 Estate Syrah — in-progress
        $syrah25 = $this->lots['2025 Estate Syrah'];
        $syrah25Readings = [
            ['date' => '2025-09-18', 'tests' => ['pH' => 3.60, 'TA' => 6.2]],
            ['date' => '2025-10-05', 'tests' => ['pH' => 3.65, 'TA' => 5.9, 'VA' => 0.02, 'alcohol' => 14.5]],
        ];
        $count += $this->createLabHistory($syrah25, $syrah25Readings, $winemaker, 'manual');

        // 2024 Grenache — ETS Labs source (external lab)
        $grenache = $this->lots['2024 Estate Grenache'];
        $grenacheReadings = [
            ['date' => '2024-10-10', 'tests' => ['pH' => 3.55, 'TA' => 5.5, 'VA' => 0.03, 'alcohol' => 14.0, 'residual_sugar' => 0.6, 'malic_acid' => 0.01]],
            ['date' => '2025-02-15', 'tests' => ['pH' => 3.58, 'TA' => 5.3, 'VA' => 0.04, 'free_SO2' => 22, 'total_SO2' => 48]],
        ];
        $count += $this->createLabHistory($grenache, $grenacheReadings, $winemaker, 'ets_labs');

        // 2024 Petite Sirah — a lot with slightly elevated VA (approaching warning)
        $ps = $this->lots['2024 Petite Sirah - Willow Creek'];
        $psReadings = [
            ['date' => '2024-10-15', 'tests' => ['pH' => 3.75, 'TA' => 5.2, 'VA' => 0.06, 'alcohol' => 15.1]],
            ['date' => '2024-12-01', 'tests' => ['pH' => 3.78, 'TA' => 5.0, 'VA' => 0.08, 'free_SO2' => 18, 'total_SO2' => 42]],
            ['date' => '2025-03-01', 'tests' => ['pH' => 3.80, 'TA' => 4.9, 'VA' => 0.09, 'free_SO2' => 15, 'total_SO2' => 38]],
        ];
        $count += $this->createLabHistory($ps, $psReadings, $winemaker, 'manual');

        $this->command?->info("  → Created {$count} lab analysis records across multiple lots.");
    }

    /**
     * Create a series of lab analysis records for a lot.
     *
     * @param  array<int, array{date: string, tests: array<string, float>}>  $readings
     * @return int Number of records created
     */
    private function createLabHistory(Lot $lot, array $readings, User $performer, string $source): int
    {
        $count = 0;
        foreach ($readings as $reading) {
            foreach ($reading['tests'] as $testType => $value) {
                LabAnalysis::create([
                    'lot_id' => $lot->id,
                    'test_date' => $reading['date'],
                    'test_type' => $testType,
                    'value' => $value,
                    'unit' => LabAnalysis::DEFAULT_UNITS[$testType] ?? '',
                    'method' => $this->labMethod($testType),
                    'analyst' => $source === 'ets_labs' ? 'ETS Laboratories' : $performer->name,
                    'source' => $source,
                    'performed_by' => $performer->id,
                ]);

                $this->eventLogger->log(
                    entityType: 'lot',
                    entityId: $lot->id,
                    operationType: 'lab_analysis_entered',
                    payload: [
                        'lot_name' => $lot->name,
                        'lot_variety' => $lot->variety,
                        'test_type' => $testType,
                        'value' => $value,
                        'unit' => LabAnalysis::DEFAULT_UNITS[$testType] ?? '',
                        'source' => $source,
                        'test_date' => $reading['date'],
                    ],
                    performedBy: $performer->id,
                    performedAt: Carbon::parse($reading['date']),
                );

                $count++;
            }
        }

        return $count;
    }

    private function labMethod(string $testType): string
    {
        return match ($testType) {
            'pH' => 'pH Meter',
            'TA' => 'Titration (NaOH)',
            'VA' => 'Cash Still / Titration',
            'free_SO2' => 'Aeration-Oxidation',
            'total_SO2' => 'Aeration-Oxidation',
            'residual_sugar' => 'Clinitest / Enzymatic',
            'alcohol' => 'Ebulliometer',
            'malic_acid' => 'Enzymatic',
            'glucose_fructose' => 'Enzymatic',
            'turbidity' => 'Nephelometer',
            'color' => 'Spectrophotometer',
            default => 'Standard',
        };
    }

    // ─── Fermentation Data ───────────────────────────────────────────

    private function seedFermentationData(): void
    {
        $winemaker = $this->users['winemaker'];
        $cellarHand = $this->users['cellar_hand'];
        $roundCount = 0;
        $entryCount = 0;

        // 2025 Estate Cab Block A — active primary fermentation with daily Brix curve
        $cab25A = $this->lots['2025 Estate Cabernet Sauvignon - Block A'];
        $round = $this->createFermentationRound($cab25A, 1, 'primary', '2025-09-20', 'D-254', null, 82.0, 'active', $winemaker);
        $entryCount += $this->createBrixCurve($round, '2025-09-21', [
            25.2, 24.0, 21.5, 18.0, 14.5, 10.0, 6.5, 3.0, 0.5, -0.8,
        ], [80, 82, 84, 85, 83, 81, 79, 77, 76, 75], $cellarHand);
        $roundCount++;

        // 2025 Estate Cab Block C — active primary, slightly behind
        $cab25C = $this->lots['2025 Estate Cabernet Sauvignon - Block C'];
        $round = $this->createFermentationRound($cab25C, 1, 'primary', '2025-09-22', 'EC-1118', null, 80.0, 'active', $winemaker);
        $entryCount += $this->createBrixCurve($round, '2025-09-23', [
            24.8, 23.5, 20.5, 17.0, 13.0, 8.5, 4.0,
        ], [78, 80, 82, 83, 81, 79, 78], $cellarHand);
        $roundCount++;

        // 2025 Estate Syrah — active primary
        $syrah25 = $this->lots['2025 Estate Syrah'];
        $round = $this->createFermentationRound($syrah25, 1, 'primary', '2025-09-18', 'BM45', null, 85.0, 'active', $winemaker);
        $entryCount += $this->createBrixCurve($round, '2025-09-19', [
            26.0, 24.5, 22.0, 18.5, 14.0, 9.5, 5.0, 1.5, -0.5, -1.2,
        ], [82, 85, 87, 88, 86, 84, 82, 80, 78, 77], $cellarHand);
        $roundCount++;

        // 2025 Estate Grenache — active primary (shorter fermentation, lighter)
        $grenache25 = $this->lots['2025 Estate Grenache'];
        $round = $this->createFermentationRound($grenache25, 1, 'primary', '2025-09-25', 'D-254', null, 78.0, 'active', $winemaker);
        $entryCount += $this->createBrixCurve($round, '2025-09-26', [
            23.5, 21.0, 17.5, 13.0, 8.0, 3.5, -0.5,
        ], [76, 78, 80, 79, 77, 76, 75], $cellarHand);
        $roundCount++;

        // 2024 Estate Cab Block A — COMPLETED primary + COMPLETED ML
        $cab24A = $this->lots['2024 Estate Cabernet Sauvignon - Block A'];
        $primaryRound = $this->createFermentationRound($cab24A, 1, 'primary', '2024-09-15', 'EC-1118', null, 82.0, 'completed', $winemaker);
        $primaryRound->update(['completion_date' => '2024-10-05']);
        $entryCount += $this->createBrixCurve($primaryRound, '2024-09-16', [
            25.5, 24.0, 21.0, 17.5, 13.5, 9.0, 5.0, 1.5, -0.5, -1.0, -1.2,
        ], [80, 82, 84, 86, 85, 83, 81, 79, 78, 77, 76], $cellarHand);
        $roundCount++;

        // ML fermentation for 2024 Cab
        $mlRound = $this->createFermentationRound($cab24A, 2, 'malolactic', '2024-10-10', null, 'VP41', 68.0, 'completed', $winemaker);
        $mlRound->update(['completion_date' => '2024-11-25', 'confirmation_date' => '2024-11-28']);
        // ML entries track temperature only (malic acid tracked via lab analyses)
        $mlEntries = [
            ['date' => '2024-10-11', 'temp' => 65.0],
            ['date' => '2024-10-18', 'temp' => 66.0],
            ['date' => '2024-10-25', 'temp' => 67.0],
            ['date' => '2024-11-01', 'temp' => 66.5],
            ['date' => '2024-11-08', 'temp' => 66.0],
            ['date' => '2024-11-15', 'temp' => 65.5],
            ['date' => '2024-11-22', 'temp' => 65.0],
        ];
        foreach ($mlEntries as $entry) {
            FermentationEntry::create([
                'fermentation_round_id' => $mlRound->id,
                'entry_date' => $entry['date'],
                'temperature' => $entry['temp'],
                'performed_by' => $cellarHand->id,
            ]);
            $entryCount++;
        }
        $roundCount++;

        // 2024 Estate Syrah — COMPLETED primary
        $syrah24 = $this->lots['2024 Estate Syrah'];
        $syrahPrimary = $this->createFermentationRound($syrah24, 1, 'primary', '2024-09-18', 'RC-212', null, 85.0, 'completed', $winemaker);
        $syrahPrimary->update(['completion_date' => '2024-10-08']);
        $entryCount += $this->createBrixCurve($syrahPrimary, '2024-09-19', [
            26.5, 25.0, 22.0, 18.0, 13.5, 9.0, 4.5, 1.0, -0.8, -1.5,
        ], [83, 85, 88, 90, 88, 86, 84, 82, 80, 79], $cellarHand);
        $roundCount++;

        // 2025 Estate Chardonnay — white fermentation (cooler temps, slower)
        $chard25 = $this->lots['2025 Estate Chardonnay'];
        $round = $this->createFermentationRound($chard25, 1, 'primary', '2025-09-20', 'CY-3079', null, 58.0, 'active', $winemaker);
        $entryCount += $this->createBrixCurve($round, '2025-09-21', [
            22.5, 22.0, 21.0, 19.5, 17.5, 15.5, 13.0, 10.5, 8.0, 5.5, 3.0, 0.5,
        ], [55, 56, 57, 58, 58, 57, 57, 56, 56, 55, 55, 54], $cellarHand);
        $roundCount++;

        // 2025 Co-Ferment Syrah/Viognier — experimental, stuck fermentation
        $coferment = $this->lots['2025 Co-Ferment Syrah/Viognier'];
        $stuckRound = $this->createFermentationRound($coferment, 1, 'primary', '2025-10-01', 'BM45', null, 82.0, 'stuck', $winemaker);
        $entryCount += $this->createBrixCurve($stuckRound, '2025-10-02', [
            24.0, 22.5, 20.0, 18.0, 16.5, 16.0, 15.8, 15.8,
        ], [80, 82, 83, 82, 78, 75, 74, 74], $cellarHand);
        $roundCount++;

        $this->command?->info("  → Created {$roundCount} fermentation rounds with {$entryCount} daily entries.");
    }

    private function createFermentationRound(
        Lot $lot,
        int $roundNumber,
        string $type,
        string $inoculationDate,
        ?string $yeastStrain,
        ?string $mlBacteria,
        float $targetTemp,
        string $status,
        User $creator
    ): FermentationRound {
        $round = FermentationRound::create([
            'lot_id' => $lot->id,
            'round_number' => $roundNumber,
            'fermentation_type' => $type,
            'inoculation_date' => $inoculationDate,
            'yeast_strain' => $yeastStrain,
            'ml_bacteria' => $mlBacteria,
            'target_temp' => $targetTemp,
            'status' => $status,
            'created_by' => $creator->id,
        ]);

        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $lot->id,
            operationType: 'fermentation_round_created',
            payload: [
                'round_id' => $round->id,
                'lot_name' => $lot->name,
                'lot_variety' => $lot->variety,
                'fermentation_type' => $type,
                'round_number' => $roundNumber,
                'inoculation_date' => $inoculationDate,
                'yeast_strain' => $yeastStrain,
                'ml_bacteria' => $mlBacteria,
            ],
            performedBy: $creator->id,
            performedAt: Carbon::parse($inoculationDate),
        );

        return $round;
    }

    /**
     * Create a realistic Brix decrease curve with daily entries.
     *
     * @param  array<int, float>  $brixValues  Daily Brix readings (decreasing)
     * @param  array<int, float|int>  $tempValues  Daily temperature readings (°F)
     * @return int Number of entries created
     */
    private function createBrixCurve(
        FermentationRound $round,
        string $startDate,
        array $brixValues,
        array $tempValues,
        User $performer
    ): int {
        $count = 0;
        $date = Carbon::parse($startDate);

        foreach ($brixValues as $i => $brix) {
            $temp = $tempValues[$i] ?? $tempValues[count($tempValues) - 1];

            FermentationEntry::create([
                'fermentation_round_id' => $round->id,
                'entry_date' => $date->toDateString(),
                'temperature' => (float) $temp,
                'brix_or_density' => $brix,
                'measurement_type' => 'brix',
                'performed_by' => $performer->id,
            ]);

            $date->addDay();
            $count++;
        }

        return $count;
    }

    // ─── Sensory / Tasting Notes ─────────────────────────────────────

    private function seedSensoryNotes(): void
    {
        $winemaker = $this->users['winemaker'];
        $count = 0;

        // 2024 Estate Cab Block A — multiple tastings during aging
        $cabA = $this->lots['2024 Estate Cabernet Sauvignon - Block A'];
        $this->createSensoryNote($cabA, $winemaker, '2024-11-20', 3.5, 'five_point',
            'Dark cherry, cassis, raw oak, cedar shavings',
            'Medium-full body, grippy tannins, bright acidity, short finish',
            'Young and tight — needs time. Good structure for aging.');
        $this->createSensoryNote($cabA, $winemaker, '2025-01-15', 3.8, 'five_point',
            'Black cherry, blackcurrant, vanilla, hint of chocolate',
            'Full body, tannins softening, better mid-palate integration',
            'Developing nicely. Oak integrating well. Revisit at 6 months.');
        $this->createSensoryNote($cabA, $winemaker, '2025-03-10', 4.0, 'five_point',
            'Ripe blackberry, plum, mocha, graphite',
            'Full body, velvety tannins, balanced acidity, long finish',
            'Excellent development. Reserve quality candidate. Continue barrel program.');
        $count += 3;

        // 2024 Estate Syrah
        $syrah = $this->lots['2024 Estate Syrah'];
        $this->createSensoryNote($syrah, $winemaker, '2024-12-01', 3.7, 'five_point',
            'Blueberry, black pepper, smoked meat, violets',
            'Full body, firm tannins, peppery finish',
            'Varietal character showing well. Dense and concentrated.');
        $this->createSensoryNote($syrah, $winemaker, '2025-02-15', 4.2, 'five_point',
            'Dark plum, cracked pepper, lavender, bacon fat',
            'Full body, round tannins, savory mid-palate, persistent finish',
            'Outstanding. Best Syrah in 3 vintages. Consider single-vineyard bottling.');
        $count += 2;

        // 2024 Estate Chardonnay
        $chard = $this->lots['2024 Estate Chardonnay'];
        $this->createSensoryNote($chard, $winemaker, '2024-12-15', 3.5, 'five_point',
            'Green apple, lemon zest, wet stone, light butter',
            'Medium body, crisp acidity, clean mineral finish',
            'Clean and varietal-correct. Concrete egg portion adds texture.');
        $this->createSensoryNote($chard, $winemaker, '2025-02-01', 3.8, 'five_point',
            'Pear, honeysuckle, toasted almond, brioche',
            'Medium body, creamy texture, balanced oak, refreshing acidity',
            'Good commercial quality. Ready for fining and bottling prep.');
        $count += 2;

        // 2024 Grenache — panel tasting with hundred-point scale
        $grenache = $this->lots['2024 Estate Grenache'];
        $this->createSensoryNote($grenache, $winemaker, '2025-01-20', 88, 'hundred_point',
            'Red cherry, raspberry, white pepper, garrigue',
            'Medium body, soft tannins, bright fruit, elegant finish',
            'Classic Paso Grenache. Will be excellent GSM component.');
        $count++;

        // 2025 lots — early assessments (no rating yet, just qualitative)
        $cab25A = $this->lots['2025 Estate Cabernet Sauvignon - Block A'];
        $this->createSensoryNote($cab25A, $winemaker, '2025-10-01', null, 'five_point',
            'Young fruit, bright berry, no oak influence yet',
            'Light-medium body, raw tannins, high acidity',
            'Too early to rate. Primary fermentation character dominant. Check post-press.');
        $count++;

        // 2024 Petite Sirah — noting VA concern
        $ps = $this->lots['2024 Petite Sirah - Willow Creek'];
        $this->createSensoryNote($ps, $winemaker, '2025-03-01', 3.2, 'five_point',
            'Dark fruit, ink, slight nail polish on the edge',
            'Full body, big tannins, some volatility on the finish',
            'VA creeping up — monitor closely. Consider earlier SO2 addition. Still usable for blending.');
        $count++;

        $this->command?->info("  → Created {$count} sensory/tasting notes.");
    }

    private function createSensoryNote(
        Lot $lot,
        User $taster,
        string $date,
        ?float $rating,
        string $ratingScale,
        ?string $noseNotes,
        ?string $palateNotes,
        ?string $overallNotes
    ): SensoryNote {
        $note = SensoryNote::create([
            'lot_id' => $lot->id,
            'taster_id' => $taster->id,
            'date' => $date,
            'rating' => $rating,
            'rating_scale' => $ratingScale,
            'nose_notes' => $noseNotes,
            'palate_notes' => $palateNotes,
            'overall_notes' => $overallNotes,
        ]);

        $this->eventLogger->log(
            entityType: 'lot',
            entityId: $lot->id,
            operationType: 'sensory_note_recorded',
            payload: [
                'note_id' => $note->id,
                'lot_name' => $lot->name,
                'lot_variety' => $lot->variety,
                'taster_name' => $taster->name,
                'date' => $date,
                'rating' => $rating,
                'rating_scale' => $ratingScale,
                'has_nose_notes' => $noseNotes !== null,
                'has_palate_notes' => $palateNotes !== null,
                'has_overall_notes' => $overallNotes !== null,
            ],
            performedBy: $taster->id,
            performedAt: Carbon::parse($date),
        );

        return $note;
    }
}
