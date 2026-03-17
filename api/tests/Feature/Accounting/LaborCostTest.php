<?php

declare(strict_types=1);

use App\Models\LaborRate;
use App\Models\Lot;
use App\Models\LotCostEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create tenant with admin + cellar_hand users, plus a labor rate.
 */
function seedAndGetLaborCostTenant(string $slug = 'labor-winery'): array
{
    if (function_exists('tenancy') && tenancy()->initialized) {
        tenancy()->end();
    }

    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'pro',
    ]);

    $adminId = null;
    $cellarHandId = null;

    $tenant->run(function () use (&$adminId, &$cellarHandId) {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => 'SecurePass123!',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');
        $adminId = $admin->id;

        $cellarHand = User::create([
            'name' => 'Test Cellar Hand',
            'email' => 'cellar@example.com',
            'password' => 'SecurePass123!',
            'role' => 'cellar_hand',
            'is_active' => true,
        ]);
        $cellarHand->assignRole('cellar_hand');
        $cellarHandId = $cellarHand->id;

        // Set up labor rates
        LaborRate::create([
            'role' => 'cellar_hand',
            'hourly_rate' => 25.0000,
            'is_active' => true,
        ]);

        LaborRate::create([
            'role' => 'admin',
            'hourly_rate' => 50.0000,
            'is_active' => true,
        ]);
    });

    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    return [$tenant, $loginResponse->json('data.token'), $adminId, $cellarHandId];
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

// ─── Tier 1: Labor Cost Calculation ────────────────────────────────

describe('labor cost on work order completion', function () {
    it('calculates labor cost as hours × role rate on completion', function () {
        [$tenant, $token, $adminId, $cellarHandId] = seedAndGetLaborCostTenant('labor-calc-1');

        $tenant->run(function () use ($cellarHandId) {
            $lot = Lot::create([
                'name' => 'Labor Test Lot',
                'variety' => 'Cabernet Sauvignon',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 500,
            ]);

            $workOrder = WorkOrder::create([
                'operation_type' => 'Pump Over',
                'lot_id' => $lot->id,
                'status' => 'pending',
            ]);

            /** @var WorkOrderService $service */
            $service = app(WorkOrderService::class);

            $completed = $service->completeWorkOrder($workOrder, [
                'completion_notes' => 'Standard pump over',
                'hours' => 2.5,
            ], $cellarHandId);

            // Work order should have hours and labor_cost recorded
            expect((string) $completed->hours)->toBe('2.50');
            // 2.5 hours × $25/hr = $62.50
            expect((string) $completed->labor_cost)->toBe('62.5000');

            // Cost entry should be on the lot
            $costEntry = LotCostEntry::where('lot_id', $lot->id)
                ->where('cost_type', 'labor')
                ->where('reference_type', 'work_order')
                ->first();

            expect($costEntry)->not->toBeNull();
            expect((string) $costEntry->amount)->toBe('62.5000');
            expect((string) $costEntry->quantity)->toBe('2.5000');
            expect((string) $costEntry->unit_cost)->toBe('25.0000');
            expect($costEntry->reference_id)->toBe($workOrder->id);
        });
    });

    it('records zero labor cost when hours is 0', function () {
        [$tenant, $token, $adminId, $cellarHandId] = seedAndGetLaborCostTenant('labor-calc-2');

        $tenant->run(function () use ($cellarHandId) {
            $lot = Lot::create([
                'name' => 'Quick Task Lot',
                'variety' => 'Merlot',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 300,
            ]);

            $workOrder = WorkOrder::create([
                'operation_type' => 'Visual Check',
                'lot_id' => $lot->id,
                'status' => 'pending',
            ]);

            /** @var WorkOrderService $service */
            $service = app(WorkOrderService::class);

            $completed = $service->completeWorkOrder($workOrder, [
                'completion_notes' => 'Quick check',
            ], $cellarHandId);

            expect((string) $completed->hours)->toBe('0.00');
            expect($completed->labor_cost)->toBeNull();

            // No cost entry should be created
            $costCount = LotCostEntry::where('lot_id', $lot->id)->count();
            expect($costCount)->toBe(0);
        });
    });

    it('skips labor cost entry when work order has no lot_id', function () {
        [$tenant, $token, $adminId, $cellarHandId] = seedAndGetLaborCostTenant('labor-calc-3');

        $tenant->run(function () use ($cellarHandId) {
            $workOrder = WorkOrder::create([
                'operation_type' => 'Clean Tank',
                'lot_id' => null,
                'status' => 'pending',
            ]);

            /** @var WorkOrderService $service */
            $service = app(WorkOrderService::class);

            $completed = $service->completeWorkOrder($workOrder, [
                'hours' => 1.0,
            ], $cellarHandId);

            // Labor cost calculated but no cost entry created (no lot)
            expect((string) $completed->hours)->toBe('1.00');
            expect((string) $completed->labor_cost)->toBe('25.0000');

            // No cost entries at all
            $costCount = LotCostEntry::count();
            expect($costCount)->toBe(0);
        });
    });

    it('skips labor cost when no labor rate configured for role', function () {
        [$tenant, $token, $adminId, $cellarHandId] = seedAndGetLaborCostTenant('labor-calc-4');

        $tenant->run(function () use ($cellarHandId) {
            // Deactivate all rates
            LaborRate::query()->update(['is_active' => false]);

            $lot = Lot::create([
                'name' => 'No Rate Lot',
                'variety' => 'Pinot Noir',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 200,
            ]);

            $workOrder = WorkOrder::create([
                'operation_type' => 'Barrel Top',
                'lot_id' => $lot->id,
                'status' => 'pending',
            ]);

            /** @var WorkOrderService $service */
            $service = app(WorkOrderService::class);

            $completed = $service->completeWorkOrder($workOrder, [
                'hours' => 3.0,
            ], $cellarHandId);

            expect((string) $completed->hours)->toBe('3.00');
            expect($completed->labor_cost)->toBeNull();

            // No cost entry created
            $costCount = LotCostEntry::where('lot_id', $lot->id)->count();
            expect($costCount)->toBe(0);
        });
    });
})->group('accounting');

// ─── Tier 2: Labor Rate Model ──────────────────────────────────────

describe('labor rate model', function () {
    it('looks up active rate by role', function () {
        [$tenant, $token, $adminId] = seedAndGetLaborCostTenant('labor-rate-1');

        $tenant->run(function () {
            $rate = LaborRate::getActiveRate('cellar_hand');
            expect($rate)->not->toBeNull();
            expect((string) $rate->hourly_rate)->toBe('25.0000');

            $noRate = LaborRate::getActiveRate('nonexistent_role');
            expect($noRate)->toBeNull();
        });
    });

    it('ignores inactive rates', function () {
        [$tenant, $token, $adminId] = seedAndGetLaborCostTenant('labor-rate-2');

        $tenant->run(function () {
            LaborRate::where('role', 'cellar_hand')->update(['is_active' => false]);

            $rate = LaborRate::getActiveRate('cellar_hand');
            expect($rate)->toBeNull();
        });
    });
})->group('accounting');
