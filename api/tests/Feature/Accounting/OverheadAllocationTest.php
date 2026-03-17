<?php

declare(strict_types=1);

use App\Models\Lot;
use App\Models\LotCostEntry;
use App\Models\OverheadRate;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OverheadAllocationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create tenant for overhead tests.
 */
function seedAndGetOverheadTenant(string $slug = 'overhead-winery'): array
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
            'name' => 'Test Accountant',
            'email' => 'accountant@example.com',
            'password' => 'SecurePass123!',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $user->assignRole('admin');
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

// ─── Tier 1: Per-Gallon Allocation ──────────────────────────────

describe('overhead per-gallon allocation', function () {
    it('allocates per-gallon overhead to lots by volume', function () {
        [$tenant, $userId] = seedAndGetOverheadTenant('overhead-gal-1');

        $tenant->run(function () use ($userId) {
            $rate = OverheadRate::create([
                'name' => 'Facility Rent',
                'allocation_method' => 'per_gallon',
                'rate' => '0.5000',
                'is_active' => true,
            ]);

            $lotA = Lot::create([
                'name' => 'Lot A',
                'variety' => 'Cabernet Sauvignon',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 200,
                'status' => 'in_progress',
            ]);

            $lotB = Lot::create([
                'name' => 'Lot B',
                'variety' => 'Merlot',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 300,
                'status' => 'in_progress',
            ]);

            /** @var OverheadAllocationService $overheadService */
            $overheadService = app(OverheadAllocationService::class);
            $entries = $overheadService->allocateToLots($rate, [$lotA, $lotB], $userId);

            expect($entries)->toHaveCount(2);

            // Lot A: 200 gal × $0.50 = $100
            $entryA = LotCostEntry::where('lot_id', $lotA->id)
                ->where('cost_type', 'overhead')
                ->first();
            expect($entryA)->not->toBeNull();
            expect((string) $entryA->amount)->toBe('100.0000');

            // Lot B: 300 gal × $0.50 = $150
            $entryB = LotCostEntry::where('lot_id', $lotB->id)
                ->where('cost_type', 'overhead')
                ->first();
            expect($entryB)->not->toBeNull();
            expect((string) $entryB->amount)->toBe('150.0000');
        });
    });

    it('skips lots with zero volume', function () {
        [$tenant, $userId] = seedAndGetOverheadTenant('overhead-gal-2');

        $tenant->run(function () use ($userId) {
            $rate = OverheadRate::create([
                'name' => 'Insurance',
                'allocation_method' => 'per_gallon',
                'rate' => '1.0000',
                'is_active' => true,
            ]);

            $lot = Lot::create([
                'name' => 'Empty Lot',
                'variety' => 'Pinot Noir',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 0,
                'status' => 'in_progress',
            ]);

            /** @var OverheadAllocationService $overheadService */
            $overheadService = app(OverheadAllocationService::class);
            $entries = $overheadService->allocateToLots($rate, [$lot], $userId);

            expect($entries)->toHaveCount(0);
        });
    });
})->group('accounting');

// ─── Tier 1: Per-Labor-Hour Allocation ──────────────────────────

describe('overhead per-labor-hour allocation', function () {
    it('allocates based on completed work order hours', function () {
        [$tenant, $userId] = seedAndGetOverheadTenant('overhead-labor-1');

        $tenant->run(function () use ($userId) {
            $rate = OverheadRate::create([
                'name' => 'Supervision Overhead',
                'allocation_method' => 'per_labor_hour',
                'rate' => '25.0000',
                'is_active' => true,
            ]);

            $lot = Lot::create([
                'name' => 'Labor Lot',
                'variety' => 'Chardonnay',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 100,
                'status' => 'in_progress',
            ]);

            // Create completed work orders with hours
            WorkOrder::create([
                'lot_id' => $lot->id,
                'operation_type' => 'punch_down',
                'status' => 'completed',
                'hours' => 4.00,
                'priority' => 'medium',
                'scheduled_date' => now(),
                'assigned_to' => $userId,
                'created_by' => $userId,
            ]);

            WorkOrder::create([
                'lot_id' => $lot->id,
                'operation_type' => 'pump_over',
                'status' => 'completed',
                'hours' => 2.00,
                'priority' => 'medium',
                'scheduled_date' => now(),
                'assigned_to' => $userId,
                'created_by' => $userId,
            ]);

            /** @var OverheadAllocationService $overheadService */
            $overheadService = app(OverheadAllocationService::class);
            $entries = $overheadService->allocateToLots($rate, [$lot], $userId);

            expect($entries)->toHaveCount(1);

            // 6 hours × $25/hr = $150
            $entry = LotCostEntry::where('lot_id', $lot->id)
                ->where('cost_type', 'overhead')
                ->first();
            expect((string) $entry->amount)->toBe('150.0000');
        });
    });
})->group('accounting');

// ─── Tier 1: Batch Allocation ────────────────────────────────────

describe('overhead batch allocation', function () {
    it('allocates all active rates to in-progress and aging lots', function () {
        [$tenant, $userId] = seedAndGetOverheadTenant('overhead-batch-1');

        $tenant->run(function () use ($userId) {
            OverheadRate::create([
                'name' => 'Rent',
                'allocation_method' => 'per_gallon',
                'rate' => '0.2500',
                'is_active' => true,
            ]);

            OverheadRate::create([
                'name' => 'Inactive Rate',
                'allocation_method' => 'per_gallon',
                'rate' => '99.0000',
                'is_active' => false,
            ]);

            Lot::create([
                'name' => 'Active Lot',
                'variety' => 'Syrah',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 400,
                'status' => 'in_progress',
            ]);

            Lot::create([
                'name' => 'Aging Lot',
                'variety' => 'Cabernet Franc',
                'vintage' => 2023,
                'source_type' => 'estate',
                'volume_gallons' => 200,
                'status' => 'aging',
            ]);

            Lot::create([
                'name' => 'Archived Lot',
                'variety' => 'Merlot',
                'vintage' => 2022,
                'source_type' => 'estate',
                'volume_gallons' => 100,
                'status' => 'archived',
            ]);

            /** @var OverheadAllocationService $overheadService */
            $overheadService = app(OverheadAllocationService::class);
            $results = $overheadService->allocateAllActive($userId);

            // Only the active rate should be applied
            expect($results)->toHaveKey('Rent');
            expect($results)->not->toHaveKey('Inactive Rate');

            // Only in_progress and aging lots (not archived)
            expect($results['Rent'])->toHaveCount(2);

            // Verify totals
            $totalOverhead = LotCostEntry::where('cost_type', 'overhead')->count();
            expect($totalOverhead)->toBe(2);
        });
    });
})->group('accounting');

// ─── Tier 1: OverheadRate Model ──────────────────────────────────

describe('OverheadRate model', function () {
    it('supports active scope', function () {
        [$tenant] = seedAndGetOverheadTenant('overhead-model-1');

        $tenant->run(function () {
            OverheadRate::create([
                'name' => 'Active One',
                'allocation_method' => 'per_gallon',
                'rate' => '1.0000',
                'is_active' => true,
            ]);

            OverheadRate::create([
                'name' => 'Inactive One',
                'allocation_method' => 'per_case',
                'rate' => '2.0000',
                'is_active' => false,
            ]);

            expect(OverheadRate::active()->count())->toBe(1);
            expect(OverheadRate::active()->first()->name)->toBe('Active One');
        });
    });

    it('supports allocation method constants', function () {
        expect(OverheadRate::ALLOCATION_METHODS)->toContain('per_gallon');
        expect(OverheadRate::ALLOCATION_METHODS)->toContain('per_case');
        expect(OverheadRate::ALLOCATION_METHODS)->toContain('per_labor_hour');
    });
})->group('accounting');
