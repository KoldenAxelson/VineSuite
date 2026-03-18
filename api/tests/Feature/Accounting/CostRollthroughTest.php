<?php

declare(strict_types=1);

use App\Models\Lot;
use App\Models\LotCostEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BlendService;
use App\Services\CostAccumulationService;
use App\Services\LotSplitService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create tenant for cost rollthrough tests.
 */
function seedAndGetRollthroughTenant(string $slug = 'roll-winery'): array
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
        app(PermissionRegistrar::class)->forgetCachedPermissions();

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

// ─── Tier 1: Blend Cost Rollthrough Math ───────────────────────────

describe('blend cost rollthrough', function () {
    it('rolls costs proportionally from source lots to blended lot', function () {
        [$tenant, $userId] = seedAndGetRollthroughTenant('blend-cost-1');

        $tenant->run(function () use ($userId) {
            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            // Lot A: $10/gal, 100 gal → total $1000
            $lotA = Lot::create([
                'name' => 'Lot A - Cab',
                'variety' => 'Cabernet Sauvignon',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 100,
            ]);
            $costService->recordFruitCost($lotA, '1000.0000', '100.0000', '10.0000', $userId);

            // Lot B: $15/gal, 50 gal → total $750
            $lotB = Lot::create([
                'name' => 'Lot B - Merlot',
                'variety' => 'Merlot',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 50,
            ]);
            $costService->recordFruitCost($lotB, '750.0000', '50.0000', '15.0000', $userId);

            // Create and finalize blend: 100 gal from A + 50 gal from B
            /** @var BlendService $blendService */
            $blendService = app(BlendService::class);

            $trial = $blendService->createTrial([
                'name' => 'Cab-Merlot Blend',
                'components' => [
                    ['source_lot_id' => $lotA->id, 'percentage' => 66.67, 'volume_gallons' => 100],
                    ['source_lot_id' => $lotB->id, 'percentage' => 33.33, 'volume_gallons' => 50],
                ],
            ], $userId);

            $finalizedTrial = $blendService->finalizeTrial($trial, $userId);

            // Get the blended lot
            $blendedLot = Lot::find($finalizedTrial->resulting_lot_id);
            expect($blendedLot)->not->toBeNull();

            // Blended lot should have transfer_in cost entries
            $transferEntries = LotCostEntry::where('lot_id', $blendedLot->id)
                ->where('cost_type', 'transfer_in')
                ->get();

            expect($transferEntries)->toHaveCount(2);

            // Cost from Lot A: 100/100 * 1000 = $1000 (100% of A's volume contributed)
            $fromA = $transferEntries->first(fn ($e) => str_contains($e->description, 'Lot A'));
            expect($fromA)->not->toBeNull();
            expect((string) $fromA->amount)->toBe('1000.0000');

            // Cost from Lot B: 50/50 * 750 = $750 (100% of B's volume contributed)
            $fromB = $transferEntries->first(fn ($e) => str_contains($e->description, 'Lot B'));
            expect($fromB)->not->toBeNull();
            expect((string) $fromB->amount)->toBe('750.0000');

            // Total blended lot cost: $1000 + $750 = $1750
            $totalCost = $costService->getTotalCost($blendedLot);
            expect($totalCost)->toBe('1750.0000');

            // Cost per gallon: $1750 / 150 gal = $11.6666
            $costPerGallon = $costService->getCostPerGallon($blendedLot);
            expect($costPerGallon)->toBe('11.6666');
        });
    });

    it('handles partial volume contribution from source lots', function () {
        [$tenant, $userId] = seedAndGetRollthroughTenant('blend-cost-2');

        $tenant->run(function () use ($userId) {
            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            // Lot A: $10/gal, 200 gal total, but only 100 gal goes to blend
            $lotA = Lot::create([
                'name' => 'Big Lot A',
                'variety' => 'Cabernet Sauvignon',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 200,
            ]);
            $costService->recordFruitCost($lotA, '2000.0000', '200.0000', '10.0000', $userId);

            /** @var BlendService $blendService */
            $blendService = app(BlendService::class);

            $trial = $blendService->createTrial([
                'name' => 'Partial Blend',
                'components' => [
                    ['source_lot_id' => $lotA->id, 'percentage' => 100, 'volume_gallons' => 100],
                ],
            ], $userId);

            $finalizedTrial = $blendService->finalizeTrial($trial, $userId);
            $blendedLot = Lot::find($finalizedTrial->resulting_lot_id);

            // Only 100/200 = 50% of cost should roll through
            $totalCost = $costService->getTotalCost($blendedLot);
            expect($totalCost)->toBe('1000.0000');

            // Source lot A should still have 100 gal remaining
            $lotA->refresh();
            expect((string) $lotA->volume_gallons)->toBe('100.0000');
        });
    });
})->group('accounting');

// ─── Tier 1: Split Cost Rollthrough Math ───────────────────────────

describe('split cost rollthrough', function () {
    it('splits costs proportionally to child lots by volume', function () {
        [$tenant, $userId] = seedAndGetRollthroughTenant('split-cost-1');

        $tenant->run(function () use ($userId) {
            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            // Parent lot: $10/gal, 300 gal → total $3000
            $parent = Lot::create([
                'name' => 'Parent Lot',
                'variety' => 'Pinot Noir',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 300,
            ]);
            $costService->recordFruitCost($parent, '3000.0000', '300.0000', '10.0000', $userId);

            /** @var LotSplitService $splitService */
            $splitService = app(LotSplitService::class);

            $result = $splitService->splitLot($parent, [
                ['name' => 'Child A', 'volume_gallons' => 200],
                ['name' => 'Child B', 'volume_gallons' => 100],
            ], $userId);

            $childA = $result['children'][0];
            $childB = $result['children'][1];

            // Child A: 200/300 * 3000 = $2000
            $costA = $costService->getTotalCost($childA);
            expect($costA)->toBe('2000.0000');

            // Child B: 100/300 * 3000 = $1000
            $costB = $costService->getTotalCost($childB);
            expect($costB)->toBe('1000.0000');

            // Cost per gallon should be identical: $10/gal
            $cpgA = $costService->getCostPerGallon($childA);
            $cpgB = $costService->getCostPerGallon($childB);
            expect($cpgA)->toBe('10.0000');
            expect($cpgB)->toBe('10.0000');
        });
    });

    it('handles split with uneven volumes', function () {
        [$tenant, $userId] = seedAndGetRollthroughTenant('split-cost-2');

        $tenant->run(function () use ($userId) {
            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            // Parent: $7.50/gal, 400 gal → $3000
            $parent = Lot::create([
                'name' => 'Syrah Parent',
                'variety' => 'Syrah',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 400,
            ]);
            $costService->recordFruitCost($parent, '3000.0000', '400.0000', '7.5000', $userId);

            /** @var LotSplitService $splitService */
            $splitService = app(LotSplitService::class);

            // Split into 3 children: 150, 150, 100 (total 400)
            $result = $splitService->splitLot($parent, [
                ['name' => 'Syrah A', 'volume_gallons' => 150],
                ['name' => 'Syrah B', 'volume_gallons' => 150],
                ['name' => 'Syrah C', 'volume_gallons' => 100],
            ], $userId);

            // All children should have $7.50/gal
            foreach ($result['children'] as $child) {
                $cpg = $costService->getCostPerGallon($child);
                expect($cpg)->toBe('7.5000');
            }

            // Syrah C: 100/400 * 3000 = $750
            $costC = $costService->getTotalCost($result['children'][2]);
            expect($costC)->toBe('750.0000');
        });
    });
})->group('accounting');
