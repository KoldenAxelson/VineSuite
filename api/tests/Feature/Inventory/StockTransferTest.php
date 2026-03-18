<?php

declare(strict_types=1);

use App\Models\CaseGoodsSku;
use App\Models\Event;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createStockTransferTestTenant(string $slug = 'xfer-winery', string $role = 'winemaker'): array
{
    if (function_exists('tenancy') && tenancy()->initialized) {
        tenancy()->end();
    }

    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'pro',
    ]);

    $tenant->run(function () use ($role) {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create([
            'name' => 'Test '.ucfirst($role),
            'email' => "{$role}@example.com",
            'password' => 'SecurePass123!',
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);
    });

    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => "{$role}@example.com",
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    return [$tenant, $loginResponse->json('data.token')];
}

/**
 * Helper: create a SKU with stock at a source location.
 *
 * @return array{sku: CaseGoodsSku, from: Location, to: Location}
 */
function createStockTransferFixtures(int $onHand = 100, int $committed = 10): array
{
    $sku = CaseGoodsSku::create([
        'wine_name' => '2024 Transfer Cab',
        'vintage' => 2024,
        'varietal' => 'Cabernet Sauvignon',
    ]);

    $from = Location::create(['name' => 'Back Stock', 'is_active' => true]);
    $to = Location::create(['name' => 'Tasting Room', 'is_active' => true]);

    StockLevel::create([
        'sku_id' => $sku->id,
        'location_id' => $from->id,
        'on_hand' => $onHand,
        'committed' => $committed,
    ]);

    return ['sku' => $sku, 'from' => $from, 'to' => $to];
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

// ─── Tier 1: Event Logging ──────────────────────────────────────

describe('stock transfer event logging', function () {
    it('writes stock_transferred event with inventory source', function () {
        [$tenant, $token] = createStockTransferTestTenant('xfer-evt');

        $skuId = null;
        $fromId = null;
        $toId = null;
        $tenant->run(function () use (&$skuId, &$fromId, &$toId) {
            $fixtures = createStockTransferFixtures();
            $skuId = $fixtures['sku']->id;
            $fromId = $fixtures['from']->id;
            $toId = $fixtures['to']->id;
        });

        $response = test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => $skuId,
            'from_location_id' => $fromId,
            'to_location_id' => $toId,
            'quantity' => 24,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $tenant->run(function () {
            $event = Event::where('operation_type', 'stock_transferred')->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['from_location_name'])->toBe('Back Stock');
            expect($event->payload['to_location_name'])->toBe('Tasting Room');
            expect($event->payload['quantity'])->toBe(24);
            expect($event->payload['wine_name'])->toBe('2024 Transfer Cab');
        });
    });
})->group('inventory');

// ─── Tier 1: Data Integrity ─────────────────────────────────────

describe('stock transfer data integrity', function () {
    it('decreases source and increases destination stock levels', function () {
        [$tenant, $token] = createStockTransferTestTenant('xfer-math');

        $skuId = null;
        $fromId = null;
        $toId = null;
        $tenant->run(function () use (&$skuId, &$fromId, &$toId) {
            $fixtures = createStockTransferFixtures(200, 0);
            $skuId = $fixtures['sku']->id;
            $fromId = $fixtures['from']->id;
            $toId = $fixtures['to']->id;
        });

        test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => $skuId,
            'from_location_id' => $fromId,
            'to_location_id' => $toId,
            'quantity' => 48,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);

        $tenant->run(function () use ($skuId, $fromId, $toId) {
            $fromLevel = StockLevel::where('sku_id', $skuId)
                ->where('location_id', $fromId)->first();
            $toLevel = StockLevel::where('sku_id', $skuId)
                ->where('location_id', $toId)->first();

            expect($fromLevel->on_hand)->toBe(152);
            expect($toLevel->on_hand)->toBe(48);
        });
    });

    it('creates paired movements with shared reference_id', function () {
        [$tenant, $token] = createStockTransferTestTenant('xfer-pair');

        $skuId = null;
        $fromId = null;
        $toId = null;
        $tenant->run(function () use (&$skuId, &$fromId, &$toId) {
            $fixtures = createStockTransferFixtures(100, 0);
            $skuId = $fixtures['sku']->id;
            $fromId = $fixtures['from']->id;
            $toId = $fixtures['to']->id;
        });

        $response = test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => $skuId,
            'from_location_id' => $fromId,
            'to_location_id' => $toId,
            'quantity' => 12,
            'notes' => 'Restock floor',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        expect($data['transfer_id'])->not->toBeNull();
        expect($data['from_movement']['quantity'])->toBe(-12);
        expect($data['to_movement']['quantity'])->toBe(12);
        expect($data['from_movement']['reference_id'])->toBe($data['to_movement']['reference_id']);
        expect($data['from_movement']['notes'])->toBe('Restock floor');

        $tenant->run(function () {
            expect(StockMovement::where('movement_type', 'transferred')->count())->toBe(2);
        });
    });

    it('rejects transfer exceeding available stock at source', function () {
        [$tenant, $token] = createStockTransferTestTenant('xfer-exceed');

        $skuId = null;
        $fromId = null;
        $toId = null;
        $tenant->run(function () use (&$skuId, &$fromId, &$toId) {
            // on_hand=100, committed=10 → available=90
            $fixtures = createStockTransferFixtures(100, 10);
            $skuId = $fixtures['sku']->id;
            $fromId = $fixtures['from']->id;
            $toId = $fixtures['to']->id;
        });

        $response = test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => $skuId,
            'from_location_id' => $fromId,
            'to_location_id' => $toId,
            'quantity' => 91, // exceeds available (90)
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        // No movements should have been created
        $tenant->run(function () {
            expect(StockMovement::count())->toBe(0);
        });
    });

    it('rejects transfer when source has no stock', function () {
        [$tenant, $token] = createStockTransferTestTenant('xfer-no-stock');

        $skuId = null;
        $fromId = null;
        $toId = null;
        $tenant->run(function () use (&$skuId, &$fromId, &$toId) {
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 No Stock Wine',
                'vintage' => 2024,
                'varietal' => 'Merlot',
            ]);
            $from = Location::create(['name' => 'Empty Room']);
            $to = Location::create(['name' => 'Target Room']);
            $skuId = $sku->id;
            $fromId = $from->id;
            $toId = $to->id;
            // No StockLevel row exists
        });

        $response = test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => $skuId,
            'from_location_id' => $fromId,
            'to_location_id' => $toId,
            'quantity' => 1,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
    });
})->group('inventory');

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

describe('stock transfer tenant isolation', function () {
    it('cannot transfer using another tenants location or SKU IDs', function () {
        [$tenantA, $tokenA] = createStockTransferTestTenant('xfer-iso-a');

        $foreignFromId = null;
        $foreignToId = null;
        $foreignSkuId = null;

        // Create fixtures in tenant A
        $localSkuId = null;
        $localFromId = null;
        $localToId = null;
        $tenantA->run(function () use (&$localSkuId, &$localFromId, &$localToId) {
            $fixtures = createStockTransferFixtures(100, 0);
            $localSkuId = $fixtures['sku']->id;
            $localFromId = $fixtures['from']->id;
            $localToId = $fixtures['to']->id;
        });

        // Create a separate tenant with its own data
        $tenantB = Tenant::create([
            'name' => 'Winery Beta',
            'slug' => 'xfer-iso-b',
            'plan' => 'pro',
        ]);

        $tenantB->run(function () use (&$foreignFromId, &$foreignToId, &$foreignSkuId) {
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Foreign Wine',
                'vintage' => 2024,
                'varietal' => 'Zinfandel',
            ]);
            $from = Location::create(['name' => 'Foreign Room A']);
            $to = Location::create(['name' => 'Foreign Room B']);
            $foreignSkuId = $sku->id;
            $foreignFromId = $from->id;
            $foreignToId = $to->id;
        });

        // Try to transfer using tenant B's location IDs — should fail validation (exists check)
        $response = test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => $localSkuId,
            'from_location_id' => $foreignFromId,
            'to_location_id' => $localToId,
            'quantity' => 1,
        ], [
            'Authorization' => "Bearer {$tokenA}",
            'X-Tenant-ID' => $tenantA->id,
        ]);

        $response->assertStatus(422);
    });
})->group('inventory');

// ─── Tier 2: Validation ──────────────────────────────────────────

describe('stock transfer validation', function () {
    it('rejects missing required fields', function () {
        [$tenant, $token] = createStockTransferTestTenant('xfer-val-missing');

        $response = test()->postJson('/api/v1/stock/transfer', [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('sku_id');
        expect($fields)->toContain('from_location_id');
        expect($fields)->toContain('to_location_id');
        expect($fields)->toContain('quantity');
    });

    it('rejects transfer to the same location', function () {
        [$tenant, $token] = createStockTransferTestTenant('xfer-val-same');

        $locationId = null;
        $skuId = null;
        $tenant->run(function () use (&$locationId, &$skuId) {
            $fixtures = createStockTransferFixtures(100, 0);
            $skuId = $fixtures['sku']->id;
            $locationId = $fixtures['from']->id;
        });

        $response = test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => $skuId,
            'from_location_id' => $locationId,
            'to_location_id' => $locationId,
            'quantity' => 10,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('to_location_id');
    });

    it('rejects zero or negative quantity', function () {
        [$tenant, $token] = createStockTransferTestTenant('xfer-val-qty');

        $skuId = null;
        $fromId = null;
        $toId = null;
        $tenant->run(function () use (&$skuId, &$fromId, &$toId) {
            $fixtures = createStockTransferFixtures(100, 0);
            $skuId = $fixtures['sku']->id;
            $fromId = $fixtures['from']->id;
            $toId = $fixtures['to']->id;
        });

        test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => $skuId,
            'from_location_id' => $fromId,
            'to_location_id' => $toId,
            'quantity' => 0,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(422);

        test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => $skuId,
            'from_location_id' => $fromId,
            'to_location_id' => $toId,
            'quantity' => -5,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(422);
    });

    it('rejects non-existent SKU or location', function () {
        [$tenant, $token] = createStockTransferTestTenant('xfer-val-notfound');

        $response = test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => '00000000-0000-0000-0000-000000000000',
            'from_location_id' => '00000000-0000-0000-0000-000000000001',
            'to_location_id' => '00000000-0000-0000-0000-000000000002',
            'quantity' => 10,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
    });
})->group('inventory');

// ─── Tier 2: RBAC ────────────────────────────────────────────────

describe('stock transfer RBAC', function () {
    it('any authenticated user can transfer stock', function () {
        [$tenant, $token] = createStockTransferTestTenant('xfer-rbac-ch', 'cellar_hand');

        $skuId = null;
        $fromId = null;
        $toId = null;
        $tenant->run(function () use (&$skuId, &$fromId, &$toId) {
            $fixtures = createStockTransferFixtures(100, 0);
            $skuId = $fixtures['sku']->id;
            $fromId = $fixtures['from']->id;
            $toId = $fixtures['to']->id;
        });

        test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => $skuId,
            'from_location_id' => $fromId,
            'to_location_id' => $toId,
            'quantity' => 5,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    });

    it('read_only users can transfer stock', function () {
        [$tenant, $token] = createStockTransferTestTenant('xfer-rbac-ro', 'read_only');

        $skuId = null;
        $fromId = null;
        $toId = null;
        $tenant->run(function () use (&$skuId, &$fromId, &$toId) {
            $fixtures = createStockTransferFixtures(100, 0);
            $skuId = $fixtures['sku']->id;
            $fromId = $fixtures['from']->id;
            $toId = $fixtures['to']->id;
        });

        test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => $skuId,
            'from_location_id' => $fromId,
            'to_location_id' => $toId,
            'quantity' => 5,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    });

    it('rejects unauthenticated transfer', function () {
        test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => '00000000-0000-0000-0000-000000000000',
            'from_location_id' => '00000000-0000-0000-0000-000000000001',
            'to_location_id' => '00000000-0000-0000-0000-000000000002',
            'quantity' => 10,
        ], [
            'X-Tenant-ID' => 'fake-tenant',
        ])->assertStatus(401);
    });
})->group('inventory');

// ─── Tier 2: API Envelope ────────────────────────────────────────

describe('stock transfer API envelope', function () {
    it('returns response in the standard API envelope format', function () {
        [$tenant, $token] = createStockTransferTestTenant('xfer-envelope');

        $skuId = null;
        $fromId = null;
        $toId = null;
        $tenant->run(function () use (&$skuId, &$fromId, &$toId) {
            $fixtures = createStockTransferFixtures(100, 0);
            $skuId = $fixtures['sku']->id;
            $fromId = $fixtures['from']->id;
            $toId = $fixtures['to']->id;
        });

        $response = test()->postJson('/api/v1/stock/transfer', [
            'sku_id' => $skuId,
            'from_location_id' => $fromId,
            'to_location_id' => $toId,
            'quantity' => 10,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'transfer_id',
                'from_movement' => ['id', 'sku_id', 'location_id', 'movement_type', 'quantity'],
                'to_movement' => ['id', 'sku_id', 'location_id', 'movement_type', 'quantity'],
            ],
            'meta',
            'errors',
        ]);
        expect($response->json('errors'))->toBe([]);

        // Movements include eager-loaded relationships
        expect($response->json('data.from_movement.sku.wine_name'))->toBe('2024 Transfer Cab');
        expect($response->json('data.from_movement.location.name'))->toBe('Back Stock');
        expect($response->json('data.to_movement.location.name'))->toBe('Tasting Room');
    });
})->group('inventory');
