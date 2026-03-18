<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Lot;
use App\Models\LotCostEntry;
use App\Models\RawMaterial;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CostAccumulationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with users and return [tenant, token, winemaker user ID].
 */
function seedAndGetAccountingTenant(string $slug = 'cost-winery', string $role = 'admin'): array
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
    $tenant->run(function () use ($role, &$userId) {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create([
            'name' => 'Test '.ucfirst($role),
            'email' => "{$role}@example.com",
            'password' => 'SecurePass123!',
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $userId = $user->id;
    });

    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => "{$role}@example.com",
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    return [$tenant, $loginResponse->json('data.token'), $userId];
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

// ─── Tier 1: Cost Entry Event Logging ──────────────────────────────

describe('cost entry event logging', function () {
    it('writes cost_entry_created event with accounting source', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-evt-1');

        $tenant->run(function () use ($userId) {
            $lot = Lot::create([
                'name' => 'Test Cab Lot',
                'variety' => 'Cabernet Sauvignon',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 500,
            ]);

            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            $entry = $costService->recordFruitCost(
                lot: $lot,
                amount: '2500.0000',
                quantity: '500.0000',
                unitCost: '5.0000',
                performedBy: $userId,
            );

            expect($entry)->toBeInstanceOf(LotCostEntry::class);
            expect($entry->cost_type)->toBe('fruit');
            expect((string) $entry->amount)->toBe('2500.0000');

            // Verify event was written
            $event = Event::where('operation_type', 'cost_entry_created')
                ->where('entity_id', $lot->id)
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('accounting');
            expect($event->payload['cost_type'])->toBe('fruit');
            expect($event->payload['amount'])->toBe('2500.0000');
        });
    });

    it('writes cost_entry_created event for manual cost entries', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-evt-2');

        $tenant->run(function () use ($userId) {
            $lot = Lot::create([
                'name' => 'Manual Cost Lot',
                'variety' => 'Merlot',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 300,
            ]);

            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            $entry = $costService->recordManualCost(
                lot: $lot,
                costType: 'overhead',
                description: 'Cold stabilization electricity',
                amount: '150.0000',
                performedBy: $userId,
            );

            expect($entry->cost_type)->toBe('overhead');
            expect($entry->reference_type)->toBe('manual');

            $event = Event::where('operation_type', 'cost_entry_created')
                ->where('entity_id', $lot->id)
                ->first();

            expect($event)->not->toBeNull();
            expect($event->payload['description'])->toBe('Cold stabilization electricity');
        });
    });
})->group('accounting');

// ─── Tier 1: Cost Accumulation Math (bcmath precision) ─────────────

describe('cost accumulation math', function () {
    it('accumulates multiple cost entries with bcmath precision', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-math-1');

        $tenant->run(function () use ($userId) {
            $lot = Lot::create([
                'name' => 'Precision Lot',
                'variety' => 'Pinot Noir',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 200,
            ]);

            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            // Fruit cost: $1000
            $costService->recordFruitCost($lot, '1000.0000', '200.0000', '5.0000', $userId);

            // Material cost: $35.50
            $costService->recordManualCost($lot, 'material', 'SO2 addition', '35.5000', $userId);

            // Labor cost: $120.75
            $costService->recordManualCost($lot, 'labor', 'Pump-over labor', '120.7500', $userId);

            $total = $costService->getTotalCost($lot);
            expect($total)->toBe('1156.2500');

            $breakdown = $costService->getCostBreakdown($lot);
            expect($breakdown['fruit'])->toBe('1000.0000');
            expect($breakdown['material'])->toBe('35.5000');
            expect($breakdown['labor'])->toBe('120.7500');
        });
    });

    it('handles negative adjustment entries correctly', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-math-2');

        $tenant->run(function () use ($userId) {
            $lot = Lot::create([
                'name' => 'Adjustment Lot',
                'variety' => 'Chardonnay',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 100,
            ]);

            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            // Original fruit cost: $500
            $costService->recordFruitCost($lot, '500.0000', '100.0000', '5.0000', $userId);

            // Correction: vendor credit of $50
            $costService->recordManualCost($lot, 'fruit', 'Vendor credit adjustment', '-50.0000', $userId);

            $total = $costService->getTotalCost($lot);
            expect($total)->toBe('450.0000');

            // Breakdown should net the two fruit entries
            $breakdown = $costService->getCostBreakdown($lot);
            expect($breakdown['fruit'])->toBe('450.0000');
        });
    });

    it('calculates cost per gallon accurately', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-math-3');

        $tenant->run(function () use ($userId) {
            $lot = Lot::create([
                'name' => 'Per-Gallon Lot',
                'variety' => 'Syrah',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 300,
            ]);

            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            $costService->recordFruitCost($lot, '1500.0000', '300.0000', '5.0000', $userId);
            $costService->recordManualCost($lot, 'material', 'Yeast', '45.0000', $userId);

            $costPerGallon = $costService->getCostPerGallon($lot);
            // (1500 + 45) / 300 = 5.15
            expect($costPerGallon)->toBe('5.1500');
        });
    });

    it('returns null cost per gallon for zero-volume lot', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-math-4');

        $tenant->run(function () use ($userId) {
            $lot = Lot::create([
                'name' => 'Empty Lot',
                'variety' => 'Malbec',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 0,
            ]);

            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            $costService->recordFruitCost($lot, '500.0000', null, null, $userId);

            $costPerGallon = $costService->getCostPerGallon($lot);
            expect($costPerGallon)->toBeNull();
        });
    });
})->group('accounting');

// ─── Tier 1: Immutability ──────────────────────────────────────────

describe('cost entry immutability', function () {
    it('has no updated_at column — entries are append-only', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-immut-1');

        $tenant->run(function () use ($userId) {
            $lot = Lot::create([
                'name' => 'Immutable Lot',
                'variety' => 'Tempranillo',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 100,
            ]);

            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            $entry = $costService->recordFruitCost($lot, '250.0000', null, null, $userId);

            // Verify UPDATED_AT is null constant
            expect(LotCostEntry::UPDATED_AT)->toBeNull();

            // Entry should have created_at but no updated_at
            expect($entry->created_at)->not->toBeNull();
        });
    });
})->group('accounting');

// ─── Tier 1: Auto-Cost from Additions ──────────────────────────────

describe('auto-cost from additions', function () {
    it('creates material cost entry when addition uses a raw material with cost_per_unit', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-add-1');

        $tenant->run(function () {
            $lot = Lot::create([
                'name' => 'Addition Cost Lot',
                'variety' => 'Cabernet Franc',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 200,
            ]);

            $material = RawMaterial::create([
                'name' => 'Potassium Metabisulfite',
                'category' => 'additive',
                'unit_of_measure' => 'g',
                'on_hand' => 5000,
                'cost_per_unit' => 0.025, // $0.025 per gram
                'is_active' => true,
            ]);

            // Create addition via API (triggers AdditionService)
            $response = test()->postJson('/api/v1/additions', [
                'lot_id' => $lot->id,
                'addition_type' => 'sulfite',
                'product_name' => 'Potassium Metabisulfite',
                'rate' => 25,
                'rate_unit' => 'ppm',
                'total_amount' => 50,
                'total_unit' => 'g',
                'inventory_item_id' => $material->id,
            ], [
                'Authorization' => 'Bearer '.test()->postJson('/api/v1/auth/login', [
                    'email' => 'admin@example.com',
                    'password' => 'SecurePass123!',
                    'client_type' => 'portal',
                    'device_name' => 'Test Browser',
                ], ['X-Tenant-ID' => tenant('id')])->json('data.token'),
                'X-Tenant-ID' => tenant('id'),
            ]);

            $response->assertStatus(201);

            // Cost entry should have been auto-created
            $costEntry = LotCostEntry::where('lot_id', $lot->id)
                ->where('cost_type', 'material')
                ->where('reference_type', 'addition')
                ->first();

            expect($costEntry)->not->toBeNull();
            // 50g × $0.025/g = $1.25
            expect((string) $costEntry->amount)->toBe('1.2500');
            expect((string) $costEntry->quantity)->toBe('50.0000');
            expect((string) $costEntry->unit_cost)->toBe('0.0250');
        });
    });

    it('skips cost entry when addition has no linked raw material', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-add-2');

        $tenant->run(function () {
            $lot = Lot::create([
                'name' => 'No Cost Lot',
                'variety' => 'Zinfandel',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 150,
            ]);

            // Create addition without inventory_item_id
            $response = test()->postJson('/api/v1/additions', [
                'lot_id' => $lot->id,
                'addition_type' => 'nutrient',
                'product_name' => 'Go-Ferm Protect',
                'total_amount' => 100,
                'total_unit' => 'g',
            ], [
                'Authorization' => 'Bearer '.test()->postJson('/api/v1/auth/login', [
                    'email' => 'admin@example.com',
                    'password' => 'SecurePass123!',
                    'client_type' => 'portal',
                    'device_name' => 'Test Browser',
                ], ['X-Tenant-ID' => tenant('id')])->json('data.token'),
                'X-Tenant-ID' => tenant('id'),
            ]);

            $response->assertStatus(201);

            // No cost entry should have been created
            $costEntryCount = LotCostEntry::where('lot_id', $lot->id)->count();
            expect($costEntryCount)->toBe(0);
        });
    });
})->group('accounting');

// ─── Tier 2: API Endpoints ─────────────────────────────────────────

describe('cost API endpoints', function () {
    it('returns cost breakdown for a lot via GET /lots/{lot}/costs', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-api-1');

        $tenant->run(function () use ($token, $userId) {
            $lot = Lot::create([
                'name' => 'API Cost Lot',
                'variety' => 'Grenache',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 400,
            ]);

            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            $costService->recordFruitCost($lot, '2000.0000', '400.0000', '5.0000', $userId);
            $costService->recordManualCost($lot, 'material', 'Oak chips', '85.0000', $userId);

            $response = test()->getJson("/api/v1/lots/{$lot->id}/costs", [
                'Authorization' => "Bearer {$token}",
                'X-Tenant-ID' => tenant('id'),
            ]);

            $response->assertOk();
            $response->assertJsonPath('data.lot_id', $lot->id);
            $response->assertJsonPath('data.summary.total_cost', '2085.0000');
            $response->assertJsonPath('data.summary.cost_per_gallon', '5.2125');
            expect($response->json('data.entries'))->toHaveCount(2);
        });
    });

    it('creates manual cost entry via POST /lots/{lot}/costs', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-api-2');

        $tenant->run(function () use ($token) {
            $lot = Lot::create([
                'name' => 'Post Cost Lot',
                'variety' => 'Viognier',
                'vintage' => 2024,
                'source_type' => 'purchased',
                'volume_gallons' => 250,
            ]);

            $response = test()->postJson("/api/v1/lots/{$lot->id}/costs", [
                'cost_type' => 'fruit',
                'description' => 'Grape purchase from Vineyard X',
                'amount' => 3750.00,
                'quantity' => 250,
                'unit_cost' => 15.00,
            ], [
                'Authorization' => "Bearer {$token}",
                'X-Tenant-ID' => tenant('id'),
            ]);

            $response->assertStatus(201);

            // Verify entry was created
            $entry = LotCostEntry::where('lot_id', $lot->id)->first();
            expect($entry)->not->toBeNull();
            expect((string) $entry->amount)->toBe('3750.0000');
            expect($entry->reference_type)->toBe('manual');
        });
    });

    it('validates cost type on POST /lots/{lot}/costs', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-api-3');

        $tenant->run(function () use ($token) {
            $lot = Lot::create([
                'name' => 'Validation Lot',
                'variety' => 'Riesling',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 100,
            ]);

            $response = test()->postJson("/api/v1/lots/{$lot->id}/costs", [
                'cost_type' => 'invalid_type',
                'description' => 'Test',
                'amount' => 100,
            ], [
                'Authorization' => "Bearer {$token}",
                'X-Tenant-ID' => tenant('id'),
            ]);

            $response->assertStatus(422);
        });
    });

    it('filters cost entries by cost_type', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-api-4');

        $tenant->run(function () use ($token, $userId) {
            $lot = Lot::create([
                'name' => 'Filter Lot',
                'variety' => 'Sauvignon Blanc',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 200,
            ]);

            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            $costService->recordFruitCost($lot, '1000.0000', null, null, $userId);
            $costService->recordManualCost($lot, 'material', 'Yeast', '30.0000', $userId);
            $costService->recordManualCost($lot, 'labor', 'Pressing', '75.0000', $userId);

            $response = test()->getJson("/api/v1/lots/{$lot->id}/costs?cost_type=material", [
                'Authorization' => "Bearer {$token}",
                'X-Tenant-ID' => tenant('id'),
            ]);

            $response->assertOk();
            expect($response->json('data.entries'))->toHaveCount(1);
            expect($response->json('data.entries.0.cost_type'))->toBe('material');
        });
    });
})->group('accounting');

// ─── Tier 2: Model Relationships ───────────────────────────────────

describe('lot cost entry relationships', function () {
    it('links cost entries to lots via costEntries relationship', function () {
        [$tenant, $token, $userId] = seedAndGetAccountingTenant('cost-rel-1');

        $tenant->run(function () use ($userId) {
            $lot = Lot::create([
                'name' => 'Relationship Lot',
                'variety' => 'Mourvèdre',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 150,
            ]);

            /** @var CostAccumulationService $costService */
            $costService = app(CostAccumulationService::class);

            $costService->recordFruitCost($lot, '750.0000', null, null, $userId);
            $costService->recordManualCost($lot, 'material', 'Tartaric acid', '22.0000', $userId);

            $lot->refresh();
            expect($lot->costEntries)->toHaveCount(2);
            expect($lot->costEntries->first()->cost_type)->toBe('fruit');
        });
    });
})->group('accounting');
