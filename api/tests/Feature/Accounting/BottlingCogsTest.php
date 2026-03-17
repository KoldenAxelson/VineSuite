<?php

declare(strict_types=1);

use App\Models\BottlingComponent;
use App\Models\BottlingRun;
use App\Models\CaseGoodsSku;
use App\Models\DryGoodsItem;
use App\Models\Lot;
use App\Models\LotCogsSummary;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CostAccumulationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create tenant for COGS tests.
 */
function seedAndGetCogsTenant(string $slug = 'cogs-winery'): array
{
    if (function_exists('tenancy') && tenancy()->initialized) {
        tenancy()->end();
    }

    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'pro',
    ]);

    $userId = null;
    $tenant->run(function () use (&$userId) {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create([
            'name' => 'Test Winemaker',
            'email' => 'winemaker@example.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);
        $user->assignRole('winemaker');
        $userId = $user->id;
    });

    return [$tenant, $userId];
}

afterEach(function () {
    if (function_exists('tenancy') && tenancy()->initialized) {
        tenancy()->end();
    }

    $schemas = DB::select(
        "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'"
    );
    foreach ($schemas as $schema) {
        DB::statement("DROP SCHEMA IF EXISTS \"{$schema->schema_name}\" CASCADE");
    }
});

// ─── Tier 1: COGS Calculation Math ──────────────────────────────

describe('bottling COGS calculation', function () {
    it('calculates per-bottle COGS from accumulated costs and packaging', function () {
        [$tenant, $userId] = seedAndGetCogsTenant('cogs-calc-1');

        $tenant->run(function () use ($userId) {
            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            // Lot with accumulated costs
            $lot = Lot::create([
                'name' => 'Cab Sauv 2024',
                'variety' => 'Cabernet Sauvignon',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 200,
            ]);

            // Fruit cost: $10/gal × 200 gal = $2000
            $costService->recordFruitCost($lot, '2000.0000', '200.0000', '10.0000', $userId);

            // Material cost: $150 for SO2
            $costService->recordManualCost($lot, 'material', 'SO2 addition', '150.0000', $userId);

            // Labor cost: $300
            $costService->recordManualCost($lot, 'labor', 'Cellar labor', '300.0000', $userId);

            // Create dry goods items with cost_per_unit for packaging
            $bottle = DryGoodsItem::create([
                'name' => '750ml Bordeaux Bottle',
                'item_type' => 'bottle',
                'unit_of_measure' => 'each',
                'cost_per_unit' => '0.8500',
                'on_hand' => 5000,
                'reorder_point' => 500,
            ]);

            $cork = DryGoodsItem::create([
                'name' => 'Natural Cork #9',
                'item_type' => 'cork',
                'unit_of_measure' => 'each',
                'cost_per_unit' => '0.4500',
                'on_hand' => 5000,
                'reorder_point' => 500,
            ]);

            $capsule = DryGoodsItem::create([
                'name' => 'Black Capsule',
                'item_type' => 'capsule',
                'unit_of_measure' => 'each',
                'cost_per_unit' => '0.1500',
                'on_hand' => 5000,
                'reorder_point' => 500,
            ]);

            // Bottling run: 200 gal → 1000 bottles (750ml, ~5/gal)
            $bottlingRun = BottlingRun::create([
                'lot_id' => $lot->id,
                'bottle_format' => '750ml',
                'bottles_filled' => 1000,
                'bottles_breakage' => 10,
                'waste_percent' => '1.00',
                'volume_bottled_gallons' => '200.0000',
                'status' => 'completed',
                'cases_produced' => 84,
                'bottles_per_case' => 12,
                'performed_by' => $userId,
                'bottled_at' => now(),
                'completed_at' => now(),
            ]);

            // Add bottling components
            BottlingComponent::create([
                'bottling_run_id' => $bottlingRun->id,
                'component_type' => 'bottle',
                'product_name' => '750ml Bordeaux Bottle',
                'quantity_used' => 1010,
                'quantity_wasted' => 10,
                'unit' => 'each',
            ]);

            BottlingComponent::create([
                'bottling_run_id' => $bottlingRun->id,
                'component_type' => 'cork',
                'product_name' => 'Natural Cork #9',
                'quantity_used' => 1000,
                'quantity_wasted' => 0,
                'unit' => 'each',
            ]);

            BottlingComponent::create([
                'bottling_run_id' => $bottlingRun->id,
                'component_type' => 'capsule',
                'product_name' => 'Black Capsule',
                'quantity_used' => 1000,
                'quantity_wasted' => 0,
                'unit' => 'each',
            ]);

            // Calculate COGS
            $summary = $costService->calculateBottlingCogs($lot, $bottlingRun, $userId);

            // Verify COGS summary was created
            expect($summary)->toBeInstanceOf(LotCogsSummary::class);
            expect($summary->lot_id)->toBe($lot->id);

            // Bulk wine cost: $2000 + $150 + $300 = $2450
            expect((string) $summary->total_fruit_cost)->toBe('2000.0000');
            expect((string) $summary->total_material_cost)->toBe('150.0000');
            expect((string) $summary->total_labor_cost)->toBe('300.0000');

            // Packaging: bottles 1010 × $0.85 = $858.50, corks 1000 × $0.45 = $450, capsules 1000 × $0.15 = $150
            // Total packaging = $1458.50
            // Total COGS = $2450 + $1458.50 = $3908.50
            $totalCost = (string) $summary->total_cost;

            // Bottles produced: 1000
            expect($summary->bottles_produced)->toBe(1000);

            // Per-bottle cost = total / 1000
            $costPerBottle = (string) $summary->cost_per_bottle;
            expect($costPerBottle)->not->toBeNull();

            // Per-case cost = per-bottle × 12
            $costPerCase = (string) $summary->cost_per_case;
            expect($costPerCase)->not->toBeNull();

            // The per-case should be 12× per-bottle
            $expectedPerCase = bcmul($costPerBottle, '12', 4);
            expect($costPerCase)->toBe($expectedPerCase);

            // Volume at calc
            expect((string) $summary->volume_gallons_at_calc)->toBe('200.0000');
        });
    });

    it('handles bottling with no packaging components', function () {
        [$tenant, $userId] = seedAndGetCogsTenant('cogs-calc-2');

        $tenant->run(function () use ($userId) {
            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            $lot = Lot::create([
                'name' => 'Simple Lot',
                'variety' => 'Chardonnay',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 100,
            ]);

            // Only fruit cost: $5/gal × 100 gal = $500
            $costService->recordFruitCost($lot, '500.0000', '100.0000', '5.0000', $userId);

            $bottlingRun = BottlingRun::create([
                'lot_id' => $lot->id,
                'bottle_format' => '750ml',
                'bottles_filled' => 500,
                'bottles_breakage' => 0,
                'waste_percent' => '0.00',
                'volume_bottled_gallons' => '100.0000',
                'status' => 'completed',
                'cases_produced' => 42,
                'bottles_per_case' => 12,
                'performed_by' => $userId,
                'bottled_at' => now(),
                'completed_at' => now(),
            ]);

            $summary = $costService->calculateBottlingCogs($lot, $bottlingRun, $userId);

            // Total cost = bulk wine only ($500)
            expect((string) $summary->total_cost)->toBe('500.0000');
            expect((string) $summary->total_fruit_cost)->toBe('500.0000');
            expect($summary->bottles_produced)->toBe(500);

            // Per-bottle: $500 / 500 = $1.00
            expect((string) $summary->cost_per_bottle)->toBe('1.0000');

            // Per-case: $1.00 × 12 = $12.00
            expect((string) $summary->cost_per_case)->toBe('12.0000');
        });
    });

    it('updates CaseGoodsSku.cost_per_bottle after COGS calculation', function () {
        [$tenant, $userId] = seedAndGetCogsTenant('cogs-calc-3');

        $tenant->run(function () use ($userId) {
            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            $lot = Lot::create([
                'name' => 'SKU Cost Lot',
                'variety' => 'Merlot',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 50,
            ]);

            $costService->recordFruitCost($lot, '750.0000', '50.0000', '15.0000', $userId);

            // Create a SKU linked to this lot
            $sku = CaseGoodsSku::create([
                'wine_name' => 'Merlot Reserve 2024',
                'vintage' => 2024,
                'varietal' => 'Merlot',
                'format' => '750ml',
                'case_size' => 12,
                'lot_id' => $lot->id,
                'is_active' => true,
            ]);

            $bottlingRun = BottlingRun::create([
                'lot_id' => $lot->id,
                'bottle_format' => '750ml',
                'bottles_filled' => 250,
                'bottles_breakage' => 0,
                'waste_percent' => '0.00',
                'volume_bottled_gallons' => '50.0000',
                'status' => 'completed',
                'sku' => $sku->id,
                'cases_produced' => 21,
                'bottles_per_case' => 12,
                'performed_by' => $userId,
                'bottled_at' => now(),
                'completed_at' => now(),
            ]);

            $summary = $costService->calculateBottlingCogs($lot, $bottlingRun, $userId);

            // Per-bottle: $750 / 250 = $3.00
            expect((string) $summary->cost_per_bottle)->toBe('3.0000');

            // SKU should have been updated
            $sku->refresh();
            expect((string) $sku->cost_per_bottle)->toBe('3.00');
        });
    });

    it('creates immutable COGS summary (no updated_at)', function () {
        [$tenant, $userId] = seedAndGetCogsTenant('cogs-calc-4');

        $tenant->run(function () {
            $summary = LotCogsSummary::create([
                'lot_id' => Lot::create([
                    'name' => 'Immutable Test',
                    'variety' => 'Pinot Noir',
                    'vintage' => 2024,
                    'source_type' => 'estate',
                    'volume_gallons' => 50,
                ])->id,
                'total_fruit_cost' => '500.0000',
                'total_material_cost' => '0.0000',
                'total_labor_cost' => '0.0000',
                'total_overhead_cost' => '0.0000',
                'total_transfer_in_cost' => '0.0000',
                'total_cost' => '500.0000',
                'volume_gallons_at_calc' => '50.0000',
                'cost_per_gallon' => '10.0000',
                'bottles_produced' => 250,
                'cost_per_bottle' => '2.0000',
                'cost_per_case' => '24.0000',
                'packaging_cost_per_bottle' => '0.0000',
                'bottling_labor_cost' => '0.0000',
                'calculated_at' => now(),
            ]);

            expect($summary->created_at)->not->toBeNull();
            // UPDATED_AT is null constant — immutable record
            expect(LotCogsSummary::UPDATED_AT)->toBeNull();
        });
    });
})->group('accounting');

// ─── Tier 1: COGS Event Logging ──────────────────────────────────

describe('COGS event logging', function () {
    it('writes cogs_calculated event with accounting source', function () {
        [$tenant, $userId] = seedAndGetCogsTenant('cogs-event-1');

        $tenant->run(function () use ($userId) {
            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            $lot = Lot::create([
                'name' => 'Event Test Lot',
                'variety' => 'Zinfandel',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 100,
            ]);

            $costService->recordFruitCost($lot, '1000.0000', '100.0000', '10.0000', $userId);

            $bottlingRun = BottlingRun::create([
                'lot_id' => $lot->id,
                'bottle_format' => '750ml',
                'bottles_filled' => 500,
                'bottles_breakage' => 0,
                'waste_percent' => '0.00',
                'volume_bottled_gallons' => '100.0000',
                'status' => 'completed',
                'cases_produced' => 42,
                'bottles_per_case' => 12,
                'performed_by' => $userId,
                'bottled_at' => now(),
                'completed_at' => now(),
            ]);

            $costService->calculateBottlingCogs($lot, $bottlingRun, $userId);

            // Verify cogs_calculated event was written
            $event = \App\Models\Event::where('entity_type', 'lot')
                ->where('entity_id', $lot->id)
                ->where('operation_type', 'cogs_calculated')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('accounting');
            expect($event->payload)->toHaveKey('total_cost');
            expect($event->payload)->toHaveKey('cost_per_bottle');
            expect($event->payload)->toHaveKey('bottles_produced');
        });
    });
})->group('accounting');

// ─── Tier 2: Lot Relationships ───────────────────────────────────

describe('lot COGS relationships', function () {
    it('accesses COGS summaries via lot relationship', function () {
        [$tenant, $userId] = seedAndGetCogsTenant('cogs-rel-1');

        $tenant->run(function () {
            $lot = Lot::create([
                'name' => 'Relationship Test',
                'variety' => 'Syrah',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 100,
            ]);

            LotCogsSummary::create([
                'lot_id' => $lot->id,
                'total_fruit_cost' => '1000.0000',
                'total_material_cost' => '0.0000',
                'total_labor_cost' => '0.0000',
                'total_overhead_cost' => '0.0000',
                'total_transfer_in_cost' => '0.0000',
                'total_cost' => '1000.0000',
                'volume_gallons_at_calc' => '100.0000',
                'cost_per_gallon' => '10.0000',
                'bottles_produced' => 500,
                'cost_per_bottle' => '2.0000',
                'cost_per_case' => '24.0000',
                'packaging_cost_per_bottle' => '0.0000',
                'bottling_labor_cost' => '0.0000',
                'calculated_at' => now(),
            ]);

            $lot->refresh();
            expect($lot->cogsSummaries)->toHaveCount(1);
            expect((string) $lot->cogsSummaries->first()->total_cost)->toBe('1000.0000');
        });
    });
})->group('accounting');
