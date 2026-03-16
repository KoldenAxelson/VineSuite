<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BottlingRun;
use App\Models\CaseGoodsSku;
use App\Models\DryGoodsItem;
use App\Models\Equipment;
use App\Models\Location;
use App\Models\MaintenanceLog;
use App\Models\PhysicalCount;
use App\Models\PhysicalCountLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\RawMaterial;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\EventLogger;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds realistic inventory data for the demo winery.
 *
 * Creates: locations, case goods SKUs (from bottling runs + library/purchased wines),
 * stock levels, dry goods, raw materials, equipment with maintenance history,
 * and purchase orders.
 *
 * Run after ProductionSeeder (depends on lots, vessels, and bottling runs).
 */
class InventorySeeder extends Seeder
{
    private EventLogger $eventLogger;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Location> */
    private array $locations = [];

    /** @var array<string, CaseGoodsSku> */
    private array $skus = [];

    public function run(): void
    {
        $this->eventLogger = app(EventLogger::class);
        $this->loadUsers();

        $this->seedLocations();
        $this->seedCaseGoodsSkus();
        $this->seedStockLevels();
        $this->seedDryGoods();
        $this->seedRawMaterials();
        $this->seedEquipment();
        $this->seedPurchaseOrders();
        $this->seedPhysicalCounts();

        $this->command?->info(sprintf(
            'InventorySeeder: %d SKUs, %d locations, %d dry goods, %d raw materials, %d equipment, %d POs, %d physical counts',
            count($this->skus),
            count($this->locations),
            DryGoodsItem::count(),
            RawMaterial::count(),
            Equipment::count(),
            PurchaseOrder::count(),
            PhysicalCount::count(),
        ));
    }

    private function loadUsers(): void
    {
        $this->users['winemaker'] = User::where('role', 'winemaker')->first();
        $this->users['admin'] = User::where('email', 'admin@vine.com')->first();
        $this->users['cellar_hand'] = User::where('role', 'cellar_hand')->first();
    }

    // ─── Locations ──────────────────────────────────────────────────

    private function seedLocations(): void
    {
        $locationData = [
            ['name' => 'Tasting Room Floor', 'address' => '4825 Vineyard Drive, Paso Robles, CA 93446'],
            ['name' => 'Back Stock', 'address' => '4825 Vineyard Drive, Paso Robles, CA 93446'],
            ['name' => 'Offsite Warehouse', 'address' => '1200 Commerce Way, Paso Robles, CA 93446'],
        ];

        foreach ($locationData as $data) {
            $location = Location::create([
                'name' => $data['name'],
                'address' => $data['address'],
                'is_active' => true,
            ]);
            $this->locations[$data['name']] = $location;
        }

        $this->command?->info('  → 3 locations created');
    }

    // ─── Case Goods SKUs ────────────────────────────────────────────

    private function seedCaseGoodsSkus(): void
    {
        $winemaker = $this->users['winemaker'];
        $count = 0;

        // SKUs from completed bottling runs
        $completedRuns = BottlingRun::where('status', 'completed')->get();
        foreach ($completedRuns as $run) {
            $sku = CaseGoodsSku::create([
                'wine_name' => $this->wineNameFromSku($run->sku),
                'vintage' => $run->lot ? (int) $run->lot->vintage : 2024,
                'varietal' => $run->lot ? $run->lot->variety : 'Blend',
                'format' => '750ml',
                'case_size' => $run->bottles_per_case ?? 12,
                'upc_barcode' => $this->generateUpc(),
                'price' => $this->priceForSku($run->sku),
                'cost_per_bottle' => round($this->priceForSku($run->sku) * 0.35, 2),
                'is_active' => true,
                'lot_id' => $run->lot_id,
                'bottling_run_id' => $run->id,
                'tasting_notes' => $this->tastingNotesForSku($run->sku),
            ]);
            $this->skus[$run->sku] = $sku;
            $count++;
        }

        // Additional library and purchased wines to reach ~47 SKUs
        $additionalWines = $this->getAdditionalWineData();
        foreach ($additionalWines as $wine) {
            $sku = CaseGoodsSku::create($wine);
            $this->skus[$wine['wine_name']] = $sku;
            $count++;
        }

        $this->command?->info("  → {$count} case goods SKUs created");
    }

    private function wineNameFromSku(string $skuCode): string
    {
        $names = [
            'PRC-2024-ROSE-750' => '2024 Rosé of Grenache',
            'PRC-2024-WHT-750' => '2024 White Blend',
            'PRC-2023-RCAB-750' => '2023 Reserve Cabernet Sauvignon',
            'PRC-2024-CHRD-750' => '2024 Estate Chardonnay',
        ];

        return $names[$skuCode] ?? $skuCode;
    }

    private function priceForSku(string $skuCode): float
    {
        $prices = [
            'PRC-2024-ROSE-750' => 28.00,
            'PRC-2024-WHT-750' => 32.00,
            'PRC-2023-RCAB-750' => 65.00,
            'PRC-2024-CHRD-750' => 35.00,
        ];

        return $prices[$skuCode] ?? 30.00;
    }

    private function tastingNotesForSku(string $skuCode): ?string
    {
        $notes = [
            'PRC-2024-ROSE-750' => 'Bright salmon color with aromas of fresh strawberry and white peach. Crisp acidity with a refreshing minerality on the finish.',
            'PRC-2024-WHT-750' => 'A Rhône-style white blend of Viognier, Roussanne, and Marsanne. Stone fruit, honeysuckle, and a touch of baking spice.',
            'PRC-2023-RCAB-750' => 'Deep garnet with concentrated cassis, dark chocolate, and cedar. Full-bodied with firm tannins and a long, complex finish. 22 months in 60% new French oak.',
        ];

        return $notes[$skuCode] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAdditionalWineData(): array
    {
        $wines = [];
        $baseWines = [
            // 2024 vintage current releases
            ['2024 Estate Syrah', 2024, 'Syrah', 45.00, '750ml', 12],
            ['2024 Grenache', 2024, 'Grenache', 38.00, '750ml', 12],
            ['2024 Mourvèdre', 2024, 'Mourvèdre', 42.00, '750ml', 12],
            ['2024 GSM Blend', 2024, 'Rhône Blend', 48.00, '750ml', 12],
            ['2024 Viognier', 2024, 'Viognier', 30.00, '750ml', 12],
            ['2024 Roussanne', 2024, 'Roussanne', 32.00, '750ml', 12],
            ['2024 Zinfandel', 2024, 'Zinfandel', 36.00, '750ml', 12],
            ['2024 Petite Sirah', 2024, 'Petite Sirah', 40.00, '750ml', 12],
            ['2024 Tempranillo', 2024, 'Tempranillo', 38.00, '750ml', 12],
            ['2024 Malbec', 2024, 'Malbec', 42.00, '750ml', 12],

            // 2023 library wines
            ['2023 Estate Syrah', 2023, 'Syrah', 48.00, '750ml', 12],
            ['2023 Grenache', 2023, 'Grenache', 40.00, '750ml', 12],
            ['2023 GSM Blend', 2023, 'Rhône Blend', 52.00, '750ml', 12],
            ['2023 Viognier', 2023, 'Viognier', 32.00, '750ml', 12],
            ['2023 Zinfandel', 2023, 'Zinfandel', 38.00, '750ml', 12],
            ['2023 Petite Sirah', 2023, 'Petite Sirah', 44.00, '750ml', 12],
            ['2023 Cabernet Sauvignon', 2023, 'Cabernet Sauvignon', 55.00, '750ml', 12],
            ['2023 Merlot', 2023, 'Merlot', 36.00, '750ml', 12],

            // 2022 library/collector wines
            ['2022 Reserve Syrah', 2022, 'Syrah', 65.00, '750ml', 12],
            ['2022 Reserve GSM', 2022, 'Rhône Blend', 68.00, '750ml', 12],
            ['2022 Cabernet Sauvignon', 2022, 'Cabernet Sauvignon', 58.00, '750ml', 12],
            ['2022 Estate Red Blend', 2022, 'Red Blend', 50.00, '750ml', 12],
            ['2022 Late Harvest Viognier', 2022, 'Viognier', 45.00, '375ml', 12],

            // Large format
            ['2023 Reserve Cab Magnum', 2023, 'Cabernet Sauvignon', 140.00, '1.5L', 6],
            ['2022 Reserve Syrah Magnum', 2022, 'Syrah', 135.00, '1.5L', 6],
            ['2024 GSM Blend Magnum', 2024, 'Rhône Blend', 100.00, '1.5L', 6],

            // Canned wine / tasting room exclusives
            ['2024 Rosé Cans (4-pack)', 2024, 'Grenache', 18.00, '250ml', 6],
            ['2024 White Blend Cans (4-pack)', 2024, 'White Blend', 18.00, '250ml', 6],

            // Club exclusive
            ['2023 Winemaker Select Syrah', 2023, 'Syrah', 75.00, '750ml', 12],
            ['2022 Founders Reserve', 2022, 'Red Blend', 95.00, '750ml', 6],

            // Sparkling / specialty
            ['NV Brut Sparkling', 0, 'Sparkling', 38.00, '750ml', 12],
            ['2024 Pét-Nat Rosé', 2024, 'Pétillant Naturel', 28.00, '750ml', 12],

            // Recently discontinued but still in stock
            ['2021 Estate Syrah', 2021, 'Syrah', 52.00, '750ml', 12],
            ['2021 Zinfandel', 2021, 'Zinfandel', 40.00, '750ml', 12],

            // Olive oil (non-wine, but common winery product)
            ['Estate Olive Oil 500ml', 0, 'Olive Oil', 24.00, '500ml', 12],
            ['Estate Olive Oil Gift Set', 0, 'Olive Oil', 45.00, 'Gift', 6],

            // Merchandise/food pairing
            ['Wine & Cheese Board Set', 0, 'Merchandise', 65.00, 'Gift', 1],
            ['Tasting Flight Voucher (6-pack)', 0, 'Voucher', 90.00, 'Gift', 1],
        ];

        foreach ($baseWines as $i => [$name, $vintage, $varietal, $price, $format, $caseSize]) {
            $wines[] = [
                'wine_name' => $name,
                'vintage' => $vintage > 0 ? $vintage : 0, // 0 = non-vintage / merchandise
                'varietal' => $varietal,
                'format' => $format,
                'case_size' => $caseSize,
                'upc_barcode' => $this->generateUpc(),
                'price' => $price,
                'cost_per_bottle' => round($price * 0.35, 2),
                'is_active' => ! str_contains($name, '2021'), // 2021s are discontinued
                'tasting_notes' => null,
            ];
        }

        return $wines;
    }

    private function generateUpc(): string
    {
        // Generate a realistic-looking 12-digit UPC-A
        return '8'.str_pad((string) random_int(10000000000, 99999999999), 11, '0', STR_PAD_LEFT);
    }

    // ─── Stock Levels ───────────────────────────────────────────────

    private function seedStockLevels(): void
    {
        $winemaker = $this->users['winemaker'];
        $tastingRoom = $this->locations['Tasting Room Floor'];
        $backStock = $this->locations['Back Stock'];
        $warehouse = $this->locations['Offsite Warehouse'];
        $count = 0;

        foreach ($this->skus as $key => $sku) {
            // Distribute stock realistically
            $isActive = $sku->is_active;
            $price = (float) $sku->price;

            // Higher-priced wines have less tasting room stock
            $tastingRoomQty = $isActive ? max(2, (int) round(24 - ($price / 5))) : 0;
            $backStockQty = $isActive ? (int) round(rand(12, 120)) : rand(0, 6);
            $warehouseQty = $isActive ? (int) round(rand(24, 480)) : rand(0, 24);

            // Tasting room
            if ($tastingRoomQty > 0) {
                StockLevel::create([
                    'sku_id' => $sku->id,
                    'location_id' => $tastingRoom->id,
                    'on_hand' => $tastingRoomQty,
                    'committed' => rand(0, min(3, $tastingRoomQty)),
                ]);

                StockMovement::create([
                    'sku_id' => $sku->id,
                    'location_id' => $tastingRoom->id,
                    'movement_type' => 'received',
                    'quantity' => $tastingRoomQty,
                    'reference_type' => 'transfer',
                    'performed_by' => $winemaker->id,
                    'performed_at' => now()->subDays(rand(7, 60)),
                    'notes' => 'Restocked tasting room',
                ]);
                $count++;
            }

            // Back stock
            if ($backStockQty > 0) {
                StockLevel::create([
                    'sku_id' => $sku->id,
                    'location_id' => $backStock->id,
                    'on_hand' => $backStockQty,
                    'committed' => 0,
                ]);

                StockMovement::create([
                    'sku_id' => $sku->id,
                    'location_id' => $backStock->id,
                    'movement_type' => 'received',
                    'quantity' => $backStockQty,
                    'reference_type' => 'bottling_run',
                    'performed_by' => $winemaker->id,
                    'performed_at' => now()->subDays(rand(30, 180)),
                    'notes' => 'From bottling',
                ]);
                $count++;
            }

            // Warehouse (only for wines with larger production)
            if ($warehouseQty > 0) {
                StockLevel::create([
                    'sku_id' => $sku->id,
                    'location_id' => $warehouse->id,
                    'on_hand' => $warehouseQty,
                    'committed' => rand(0, min(24, $warehouseQty)),
                ]);

                StockMovement::create([
                    'sku_id' => $sku->id,
                    'location_id' => $warehouse->id,
                    'movement_type' => 'received',
                    'quantity' => $warehouseQty,
                    'reference_type' => 'bottling_run',
                    'performed_by' => $winemaker->id,
                    'performed_at' => now()->subDays(rand(30, 180)),
                    'notes' => 'From bottling — warehouse allocation',
                ]);
                $count++;
            }
        }

        $this->command?->info("  → {$count} stock level records created");
    }

    // ─── Dry Goods ──────────────────────────────────────────────────

    private function seedDryGoods(): void
    {
        $items = [
            ['750ml Burgundy Bottle (Antique Green)', 'bottle', 'each', 12500, 8000, 0.42, 'Pacific Coast Bottles'],
            ['750ml Burgundy Bottle (Clear)', 'bottle', 'each', 3200, 2000, 0.38, 'Pacific Coast Bottles'],
            ['750ml Bordeaux Bottle (Dark Green)', 'bottle', 'each', 8000, 5000, 0.45, 'Pacific Coast Bottles'],
            ['375ml Half Bottle (Clear)', 'bottle', 'each', 1200, 500, 0.55, 'Pacific Coast Bottles'],
            ['1.5L Magnum Bottle', 'bottle', 'each', 180, 100, 1.85, 'Pacific Coast Bottles'],
            ['Natural Cork #9x45mm', 'cork', 'each', 15000, 5000, 0.18, 'Amorim Cork America'],
            ['DIAM 5 Technical Cork', 'cork', 'each', 8000, 3000, 0.32, 'Diam Bouchage'],
            ['Stelvin Screw Cap (White)', 'screw_cap', 'each', 4000, 2000, 0.12, 'Amcor'],
            ['Tin Capsule (Gold)', 'capsule', 'each', 10000, 4000, 0.08, 'Rivercap'],
            ['Tin Capsule (Black)', 'capsule', 'each', 8000, 3000, 0.08, 'Rivercap'],
            ['PVC Capsule (Burgundy)', 'capsule', 'each', 5000, 2000, 0.04, 'Rivercap'],
            ['Front Label — Cab Sauv 2023', 'label_front', 'each', 2400, 500, 0.15, 'Collotype Labels'],
            ['Front Label — Rosé 2024', 'label_front', 'each', 1200, 500, 0.15, 'Collotype Labels'],
            ['Front Label — White Blend 2024', 'label_front', 'each', 1500, 500, 0.15, 'Collotype Labels'],
            ['Front Label — Syrah 2024', 'label_front', 'each', 1800, 500, 0.15, 'Collotype Labels'],
            ['Front Label — GSM 2024', 'label_front', 'each', 1200, 500, 0.15, 'Collotype Labels'],
            ['Back Label — Generic (TTB Approved)', 'label_back', 'each', 8000, 3000, 0.08, 'Collotype Labels'],
            ['Neck Label — Estate Seal', 'label_neck', 'each', 6000, 2000, 0.06, 'Collotype Labels'],
            ['12-Bottle Shipper Carton', 'carton', 'each', 800, 300, 1.25, 'Berlin Packaging'],
            ['6-Bottle Shipper Carton', 'carton', 'each', 400, 200, 1.45, 'Berlin Packaging'],
            ['Cardboard Divider (12-cell)', 'divider', 'each', 900, 300, 0.35, 'Berlin Packaging'],
            ['Tissue Wrap (Burgundy)', 'tissue', 'each', 3000, 1000, 0.03, 'Berlin Packaging'],
        ];

        foreach ($items as [$name, $type, $uom, $onHand, $reorderPoint, $cost, $vendor]) {
            DryGoodsItem::create([
                'name' => $name,
                'item_type' => $type,
                'unit_of_measure' => $uom,
                'on_hand' => $onHand,
                'reorder_point' => $reorderPoint,
                'cost_per_unit' => $cost,
                'vendor_name' => $vendor,
                'is_active' => true,
            ]);
        }

        $this->command?->info('  → '.count($items).' dry goods items created');
    }

    // ─── Raw Materials ──────────────────────────────────────────────

    private function seedRawMaterials(): void
    {
        $items = [
            ['Potassium Metabisulfite (KMBS)', 'additive', 'g', 4500, 1000, 0.025, '2027-06-15', 'Scott Laboratories'],
            ['Tartaric Acid', 'acid', 'g', 8000, 2000, 0.018, '2027-12-31', 'Enartis Vinquiry'],
            ['Citric Acid (Anhydrous)', 'acid', 'g', 2000, 500, 0.022, '2027-09-30', 'Enartis Vinquiry'],
            ['Malic Acid', 'acid', 'g', 1500, 500, 0.028, '2027-08-15', 'Enartis Vinquiry'],
            ['EC-1118 Yeast (Lalvin)', 'yeast', 'g', 500, 200, 0.85, '2026-09-30', 'Lallemand'],
            ['BM45 Yeast (Lalvin)', 'yeast', 'g', 300, 100, 1.20, '2026-08-15', 'Lallemand'],
            ['D254 Yeast (Lalvin)', 'yeast', 'g', 250, 100, 1.10, '2026-10-31', 'Lallemand'],
            ['Fermaid O (Organic Nutrient)', 'nutrient', 'g', 1200, 400, 0.45, '2027-03-15', 'Lallemand'],
            ['Fermaid K (Complete Nutrient)', 'nutrient', 'g', 800, 300, 0.38, '2027-04-30', 'Lallemand'],
            ['DAP (Diammonium Phosphate)', 'nutrient', 'g', 2000, 500, 0.012, '2028-01-01', 'Scott Laboratories'],
            ['Bentonite (Volclay KWK)', 'fining_agent', 'g', 5000, 1500, 0.008, '2028-06-30', 'Scott Laboratories'],
            ['Isinglass', 'fining_agent', 'g', 200, 100, 2.50, '2026-12-31', 'Scott Laboratories'],
            ['Egg White Powder', 'fining_agent', 'g', 500, 200, 1.80, '2026-11-30', 'Enartis Vinquiry'],
            ['Lallzyme EX-V (Enzyme)', 'enzyme', 'g', 150, 50, 4.50, '2026-07-31', 'Lallemand'],
            ['Lallzyme Cuvée Blanc', 'enzyme', 'g', 100, 50, 5.20, '2026-09-15', 'Lallemand'],
            ['French Oak Chips (Medium Toast)', 'oak_alternative', 'g', 3000, 1000, 0.035, null, 'StaVin Inc.'],
            ['American Oak Spirals (Medium+)', 'oak_alternative', 'each', 24, 10, 3.50, null, 'StaVin Inc.'],
            ['French Oak Staves (Heavy Toast)', 'oak_alternative', 'each', 12, 5, 8.75, null, 'StaVin Inc.'],
        ];

        foreach ($items as [$name, $category, $uom, $onHand, $reorderPoint, $cost, $expiration, $vendor]) {
            RawMaterial::create([
                'name' => $name,
                'category' => $category,
                'unit_of_measure' => $uom,
                'on_hand' => $onHand,
                'reorder_point' => $reorderPoint,
                'cost_per_unit' => $cost,
                'expiration_date' => $expiration,
                'vendor_name' => $vendor,
                'is_active' => true,
            ]);
        }

        $this->command?->info('  → '.count($items).' raw materials created');
    }

    // ─── Equipment ──────────────────────────────────────────────────

    private function seedEquipment(): void
    {
        $cellarHand = $this->users['cellar_hand'];
        $winemaker = $this->users['winemaker'];

        $equipmentData = [
            [
                'name' => 'Peristaltic Pump P-1',
                'type' => 'pump',
                'serial' => 'WP-2020-0042',
                'manufacturer' => 'Waukesha Cherry-Burrell',
                'model' => 'Universal 1 Series',
                'purchased' => '2020-03-15',
                'value' => 4500.00,
                'location' => 'Crush Pad',
                'maintenance' => [
                    ['cleaning', '2026-01-15', 'Monthly CIP cycle — pump head and lines', 'CIP with 2% caustic, 15 min hot rinse', null, true, '2026-02-15'],
                    ['cleaning', '2026-02-14', 'Monthly CIP cycle', 'Standard CIP protocol', null, true, '2026-03-15'],
                    ['inspection', '2025-12-01', 'Annual pump inspection', 'Rotor and stator wear within tolerance. Seals good.', null, true, '2026-12-01'],
                ],
            ],
            [
                'name' => 'Bladder Press BP-1',
                'type' => 'press',
                'serial' => 'BP-2019-1138',
                'manufacturer' => 'Bucher Vaslin',
                'model' => 'XPlus 20',
                'purchased' => '2019-06-20',
                'value' => 28000.00,
                'location' => 'Crush Pad',
                'maintenance' => [
                    ['cleaning', '2025-10-30', 'Post-harvest deep clean', 'Full CIP + manual scrub of membrane', null, true, '2026-08-01'],
                    ['repair', '2025-09-18', 'Replaced bladder membrane', 'Old membrane had micro-tears. Replaced with OEM part.', 850.00, null, null],
                    ['inspection', '2025-11-15', 'Annual safety inspection', 'Hydraulics, frame, and bladder — all passed', null, true, '2026-11-15'],
                ],
            ],
            [
                'name' => 'Crossflow Filter CF-1',
                'type' => 'filter',
                'serial' => 'CF-2022-0297',
                'manufacturer' => 'Pall',
                'model' => 'Oenoflow XL-A',
                'purchased' => '2022-01-10',
                'value' => 35000.00,
                'location' => 'Barrel Room',
                'maintenance' => [
                    ['cleaning', '2026-02-28', 'Quarterly membrane cleaning', 'Enzyme soak + forward flush + integrity test passed', null, true, '2026-05-31'],
                    ['calibration', '2025-12-10', 'Flow meter calibration', 'Calibrated against reference — 0.3% deviation, within spec', null, true, '2026-06-10'],
                ],
            ],
            [
                'name' => 'pH Meter Hanna HI2020',
                'type' => 'lab_instrument',
                'serial' => 'HI-2020-88431',
                'manufacturer' => 'Hanna Instruments',
                'model' => 'HI2020-01',
                'purchased' => '2023-04-01',
                'value' => 650.00,
                'location' => 'Lab',
                'maintenance' => [
                    ['calibration', '2026-03-01', '2-point calibration pH 4.01 / 7.00', 'Slope 98.2%, offset +3mV. Within spec.', null, true, '2026-03-15'],
                    ['calibration', '2026-02-15', '2-point calibration pH 4.01 / 7.00', 'Slope 97.8%, offset +5mV. Within spec.', null, true, '2026-03-01'],
                    ['calibration', '2026-02-01', '2-point calibration pH 4.01 / 7.00', 'Slope 96.1%, offset +8mV. Probe nearing replacement.', null, true, '2026-02-15'],
                ],
            ],
            [
                'name' => 'SO2 Analyzer (Aeration-Oxidation)',
                'type' => 'lab_instrument',
                'serial' => 'AO-2021-0053',
                'manufacturer' => 'Vinmetrica',
                'model' => 'SC-300',
                'purchased' => '2021-08-15',
                'value' => 1200.00,
                'location' => 'Lab',
                'maintenance' => [
                    ['calibration', '2026-02-20', 'Monthly calibration with standard', 'Recovery 99.1% on 25ppm standard. Passed.', null, true, '2026-03-20'],
                ],
            ],
            [
                'name' => 'Bottling Line BL-1',
                'type' => 'bottling_line',
                'serial' => 'BL-2018-4421',
                'manufacturer' => 'GAI',
                'model' => '2504',
                'purchased' => '2018-11-01',
                'value' => 65000.00,
                'location' => 'Bottling Hall',
                'maintenance' => [
                    ['preventive', '2026-01-10', 'Annual pre-season maintenance', 'Replaced filler gaskets, calibrated fill heads, lubricated corker', 1200.00, null, '2027-01-10'],
                    ['cleaning', '2025-03-05', 'Post-bottling CIP', 'Full CIP of filler, corker, and conveyor', null, true, null],
                    ['repair', '2025-02-20', 'Label applicator alignment', 'Front label applicator was drifting 2mm. Realigned and tested.', 150.00, null, null],
                ],
            ],
        ];

        foreach ($equipmentData as $eq) {
            $nextDue = null;
            if (! empty($eq['maintenance'])) {
                // Find the nearest future next_due_date
                foreach ($eq['maintenance'] as $m) {
                    if ($m[6] !== null && Carbon::parse($m[6])->isFuture()) {
                        if ($nextDue === null || Carbon::parse($m[6])->lt(Carbon::parse($nextDue))) {
                            $nextDue = $m[6];
                        }
                    }
                }
            }

            $equipment = Equipment::create([
                'name' => $eq['name'],
                'equipment_type' => $eq['type'],
                'serial_number' => $eq['serial'],
                'manufacturer' => $eq['manufacturer'],
                'model_number' => $eq['model'],
                'purchase_date' => $eq['purchased'],
                'purchase_value' => $eq['value'],
                'location' => $eq['location'],
                'status' => 'operational',
                'next_maintenance_due' => $nextDue,
                'is_active' => true,
            ]);

            foreach ($eq['maintenance'] as [$mType, $date, $desc, $findings, $cost, $passed, $nextDueDate]) {
                MaintenanceLog::create([
                    'equipment_id' => $equipment->id,
                    'maintenance_type' => $mType,
                    'performed_date' => $date,
                    'performed_by' => $mType === 'calibration' ? $winemaker->id : $cellarHand->id,
                    'description' => $desc,
                    'findings' => $findings,
                    'cost' => $cost,
                    'passed' => $passed,
                    'next_due_date' => $nextDueDate,
                ]);
            }
        }

        $this->command?->info('  → '.count($equipmentData).' equipment items with maintenance history created');
    }

    // ─── Purchase Orders ────────────────────────────────────────────

    private function seedPurchaseOrders(): void
    {
        $admin = $this->users['admin'];

        // PO 1: Received — bottles order
        $po1 = PurchaseOrder::create([
            'vendor_name' => 'Pacific Coast Bottles',
            'order_date' => '2026-02-01',
            'expected_date' => '2026-02-15',
            'status' => 'received',
            'total_cost' => 4200.00,
            'notes' => 'Spring bottling run prep — bottles for 2024 vintage wines',
        ]);

        $bottles = DryGoodsItem::where('name', 'like', '750ml Burgundy%Antique%')->first();
        $bottlesClear = DryGoodsItem::where('name', 'like', '750ml Burgundy%Clear%')->first();

        if ($bottles) {
            PurchaseOrderLine::create([
                'purchase_order_id' => $po1->id,
                'item_type' => 'dry_goods',
                'item_id' => $bottles->id,
                'item_name' => $bottles->name,
                'quantity_ordered' => 8000,
                'quantity_received' => 8000,
                'cost_per_unit' => 0.42,
            ]);
        }

        if ($bottlesClear) {
            PurchaseOrderLine::create([
                'purchase_order_id' => $po1->id,
                'item_type' => 'dry_goods',
                'item_id' => $bottlesClear->id,
                'item_name' => $bottlesClear->name,
                'quantity_ordered' => 2000,
                'quantity_received' => 2000,
                'cost_per_unit' => 0.38,
            ]);
        }

        // PO 2: Partial — corks and capsules
        $po2 = PurchaseOrder::create([
            'vendor_name' => 'Amorim Cork America',
            'order_date' => '2026-02-10',
            'expected_date' => '2026-03-01',
            'status' => 'partial',
            'total_cost' => 5360.00,
            'notes' => 'Cork and capsule order — partial shipment received',
        ]);

        $corks = DryGoodsItem::where('name', 'like', 'Natural Cork%')->first();
        if ($corks) {
            PurchaseOrderLine::create([
                'purchase_order_id' => $po2->id,
                'item_type' => 'dry_goods',
                'item_id' => $corks->id,
                'item_name' => $corks->name,
                'quantity_ordered' => 20000,
                'quantity_received' => 15000,
                'cost_per_unit' => 0.18,
            ]);
        }

        $capsules = DryGoodsItem::where('name', 'like', 'Tin Capsule (Gold)%')->first();
        if ($capsules) {
            PurchaseOrderLine::create([
                'purchase_order_id' => $po2->id,
                'item_type' => 'dry_goods',
                'item_id' => $capsules->id,
                'item_name' => $capsules->name,
                'quantity_ordered' => 10000,
                'quantity_received' => 10000,
                'cost_per_unit' => 0.08,
            ]);
        }

        // PO 3: Ordered — raw materials for upcoming harvest
        $po3 = PurchaseOrder::create([
            'vendor_name' => 'Scott Laboratories',
            'order_date' => '2026-03-10',
            'expected_date' => '2026-07-15',
            'status' => 'ordered',
            'total_cost' => 892.50,
            'notes' => 'Harvest prep — yeast, nutrients, and SO2 for 2026 vintage',
        ]);

        $kmbs = RawMaterial::where('name', 'like', 'Potassium Meta%')->first();
        $fermaidO = RawMaterial::where('name', 'like', 'Fermaid O%')->first();
        $ec1118 = RawMaterial::where('name', 'like', 'EC-1118%')->first();

        if ($kmbs) {
            PurchaseOrderLine::create([
                'purchase_order_id' => $po3->id,
                'item_type' => 'raw_material',
                'item_id' => $kmbs->id,
                'item_name' => $kmbs->name,
                'quantity_ordered' => 5000,
                'cost_per_unit' => 0.025,
            ]);
        }

        if ($fermaidO) {
            PurchaseOrderLine::create([
                'purchase_order_id' => $po3->id,
                'item_type' => 'raw_material',
                'item_id' => $fermaidO->id,
                'item_name' => $fermaidO->name,
                'quantity_ordered' => 1000,
                'cost_per_unit' => 0.45,
            ]);
        }

        if ($ec1118) {
            PurchaseOrderLine::create([
                'purchase_order_id' => $po3->id,
                'item_type' => 'raw_material',
                'item_id' => $ec1118->id,
                'item_name' => $ec1118->name,
                'quantity_ordered' => 500,
                'cost_per_unit' => 0.85,
            ]);
        }

        // PO 4: Cancelled
        PurchaseOrder::create([
            'vendor_name' => 'StaVin Inc.',
            'order_date' => '2026-01-20',
            'expected_date' => '2026-02-10',
            'status' => 'cancelled',
            'total_cost' => 0,
            'notes' => 'Cancelled — decided to use barrel aging instead of oak alternatives for 2024 Reserve',
        ]);

        $this->command?->info('  → 4 purchase orders created');
    }

    // ─── Physical Counts ─────────────────────────────────────────────

    private function seedPhysicalCounts(): void
    {
        $winemaker = $this->users['winemaker'];
        $cellarHand = $this->users['cellar_hand'];
        $tastingRoom = $this->locations['Tasting Room Floor'];
        $backStock = $this->locations['Back Stock'];

        // ── Count 1: Completed tasting room count from last month ────
        // Typical quarterly count — found a few discrepancies (breakage, tastings not logged)
        $count1 = PhysicalCount::create([
            'location_id' => $tastingRoom->id,
            'status' => 'completed',
            'started_by' => $cellarHand->id,
            'started_at' => now()->subDays(32)->setTime(8, 0),
            'completed_by' => $winemaker->id,
            'completed_at' => now()->subDays(32)->setTime(11, 30),
            'notes' => 'Q1 2026 tasting room count — 3 variances found, mostly tasting pours not logged.',
        ]);

        // Grab a sample of SKUs that have tasting room stock
        $tastingRoomStockLevels = StockLevel::where('location_id', $tastingRoom->id)
            ->with('sku')
            ->limit(10)
            ->get();

        foreach ($tastingRoomStockLevels as $i => $sl) {
            $systemQty = (int) $sl->on_hand;

            // Most lines match; a few have variances (short from unlogged tastings/breakage)
            if ($i === 2) {
                $countedQty = $systemQty - 2; // 2 bottles short — tasting pours
                $notes = 'Likely unrecorded tasting pours';
            } elseif ($i === 5) {
                $countedQty = $systemQty - 1; // 1 bottle short — breakage
                $notes = 'Broken bottle found in recycling';
            } elseif ($i === 8 && $tastingRoomStockLevels->count() > 8) {
                $countedQty = $systemQty + 1; // 1 extra — transfer not logged
                $notes = 'Extra bottle — may have been restocked without logging';
            } else {
                $countedQty = $systemQty;
                $notes = null;
            }

            PhysicalCountLine::create([
                'physical_count_id' => $count1->id,
                'sku_id' => $sl->sku_id,
                'system_quantity' => $systemQty,
                'counted_quantity' => $countedQty,
                'variance' => $countedQty - $systemQty,
                'notes' => $notes,
            ]);
        }

        // ── Count 2: In-progress back stock count (started today) ────
        // Partial count — some lines counted, some still pending
        $count2 = PhysicalCount::create([
            'location_id' => $backStock->id,
            'status' => 'in_progress',
            'started_by' => $cellarHand->id,
            'started_at' => now()->setTime(9, 0),
            'notes' => 'Spring back stock audit — in progress.',
        ]);

        $backStockLevels = StockLevel::where('location_id', $backStock->id)
            ->with('sku')
            ->limit(8)
            ->get();

        foreach ($backStockLevels as $i => $sl) {
            $systemQty = (int) $sl->on_hand;

            // First 5 have been counted, rest are still pending (null counted_quantity)
            if ($i < 5) {
                $countedQty = $systemQty; // All matching so far
                $variance = 0;
            } else {
                $countedQty = null;
                $variance = null;
            }

            PhysicalCountLine::create([
                'physical_count_id' => $count2->id,
                'sku_id' => $sl->sku_id,
                'system_quantity' => $systemQty,
                'counted_quantity' => $countedQty,
                'variance' => $variance,
            ]);
        }

        $this->command?->info('  → 2 physical counts created');
    }
}
