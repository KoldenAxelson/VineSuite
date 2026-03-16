<?php

declare(strict_types=1);

use App\Models\CaseGoodsSku;
use App\Models\Event;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createLocationTestTenant(string $slug = 'loc-winery', string $role = 'winemaker'): array
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
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

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

describe('location event logging', function () {
    it('writes stock_location_created event with inventory source', function () {
        [$tenant, $token] = createLocationTestTenant('loc-evt-create');

        $response = test()->postJson('/api/v1/locations', [
            'name' => 'Tasting Room Floor',
            'address' => '123 Vine St, Paso Robles, CA',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $locationId = $response->json('data.id');

        $tenant->run(function () use ($locationId) {
            $event = Event::where('entity_id', $locationId)
                ->where('operation_type', 'stock_location_created')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->entity_type)->toBe('location');

            $payload = $event->payload;
            expect($payload['name'])->toBe('Tasting Room Floor');
            expect($payload['address'])->toBe('123 Vine St, Paso Robles, CA');
        });
    });

    it('writes stock_location_updated event with inventory source', function () {
        [$tenant, $token] = createLocationTestTenant('loc-evt-update');

        $locationId = null;
        $tenant->run(function () use (&$locationId) {
            $location = Location::create([
                'name' => 'Back Stock',
                'is_active' => true,
            ]);
            $locationId = $location->id;
        });

        test()->putJson("/api/v1/locations/{$locationId}", [
            'name' => 'Back Stock (Main)',
            'is_active' => false,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertOk();

        $tenant->run(function () use ($locationId) {
            $event = Event::where('entity_id', $locationId)
                ->where('operation_type', 'stock_location_updated')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['name'])->toBe('Back Stock (Main)');
            expect($event->payload['is_active'])->toBeFalse();
        });
    });
})->group('inventory');

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

describe('location tenant isolation', function () {
    it('prevents cross-tenant location and stock level access', function () {
        $tenantA = Tenant::create([
            'name' => 'Winery Alpha',
            'slug' => 'loc-iso-alpha',
            'plan' => 'pro',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Winery Beta',
            'slug' => 'loc-iso-beta',
            'plan' => 'pro',
        ]);

        $tenantA->run(function () {
            $location = Location::create([
                'name' => 'Alpha Tasting Room',
                'is_active' => true,
            ]);

            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Alpha Cab',
                'vintage' => 2024,
                'varietal' => 'Cabernet Sauvignon',
            ]);

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'on_hand' => 100,
                'committed' => 10,
            ]);
        });

        $tenantB->run(function () {
            expect(Location::count())->toBe(0);
            expect(StockLevel::count())->toBe(0);
        });

        $tenantA->run(function () {
            expect(Location::count())->toBe(1);
            expect(StockLevel::count())->toBe(1);
        });
    });
})->group('inventory');

// ─── Tier 1: Stock Level Data Integrity ──────────────────────────

describe('stock level data integrity', function () {
    it('computes available as on_hand minus committed', function () {
        [$tenant, $token] = createLocationTestTenant('loc-avail-calc');

        $tenant->run(function () {
            $location = Location::create(['name' => 'Test Room']);
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Test Wine',
                'vintage' => 2024,
                'varietal' => 'Merlot',
            ]);

            $stockLevel = StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'on_hand' => 200,
                'committed' => 35,
            ]);

            expect($stockLevel->available)->toBe(165);
        });
    });

    it('allows available to go negative when committed exceeds on_hand', function () {
        [$tenant, $token] = createLocationTestTenant('loc-avail-neg');

        $tenant->run(function () {
            $location = Location::create(['name' => 'Floor']);
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Oversold Wine',
                'vintage' => 2024,
                'varietal' => 'Chardonnay',
            ]);

            $stockLevel = StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'on_hand' => 5,
                'committed' => 10,
            ]);

            // Overselling allowed per spec — warn but don't hard-block
            expect($stockLevel->available)->toBe(-5);
        });
    });

    it('enforces unique constraint on sku_id + location_id', function () {
        [$tenant, $token] = createLocationTestTenant('loc-unique');

        $tenant->run(function () {
            $location = Location::create(['name' => 'Stock Room']);
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Unique Test',
                'vintage' => 2024,
                'varietal' => 'Pinot Noir',
            ]);

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'on_hand' => 50,
            ]);

            // Attempting a duplicate should throw a unique constraint violation
            expect(fn () => StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'on_hand' => 25,
            ]))->toThrow(\Illuminate\Database\QueryException::class);
        });
    });
})->group('inventory');

// ─── Tier 2: Location CRUD ──────────────────────────────────────

describe('location CRUD', function () {
    it('creates a location with all fields', function () {
        [$tenant, $token] = createLocationTestTenant('loc-crud-create');

        $response = test()->postJson('/api/v1/locations', [
            'name' => 'Offsite Warehouse',
            'address' => '456 Storage Blvd, Templeton, CA 93465',
            'is_active' => true,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        expect($data['name'])->toBe('Offsite Warehouse');
        expect($data['address'])->toBe('456 Storage Blvd, Templeton, CA 93465');
        expect($data['is_active'])->toBeTrue();
    });

    it('creates a location with minimal fields', function () {
        [$tenant, $token] = createLocationTestTenant('loc-crud-minimal');

        $response = test()->postJson('/api/v1/locations', [
            'name' => '3PL Center',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        expect($data['name'])->toBe('3PL Center');
        expect($data['address'])->toBeNull();
        expect($data['is_active'])->toBeTrue(); // default
    });

    it('lists locations with pagination', function () {
        [$tenant, $token] = createLocationTestTenant('loc-crud-list');

        $tenant->run(function () {
            Location::create(['name' => 'Tasting Room']);
            Location::create(['name' => 'Back Stock']);
            Location::create(['name' => 'Offsite']);
        });

        $response = test()->getJson('/api/v1/locations', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);
        expect($response->json('meta.total'))->toBe(3);
    });

    it('filters locations by active status', function () {
        [$tenant, $token] = createLocationTestTenant('loc-filter-active');

        $tenant->run(function () {
            Location::create(['name' => 'Active Room', 'is_active' => true]);
            Location::create(['name' => 'Closed Room', 'is_active' => false]);
        });

        $response = test()->getJson('/api/v1/locations?is_active=1', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.name'))->toBe('Active Room');
    });

    it('shows location detail with stock levels', function () {
        [$tenant, $token] = createLocationTestTenant('loc-show');

        $locationId = null;
        $tenant->run(function () use (&$locationId) {
            $location = Location::create([
                'name' => 'Tasting Room Floor',
                'address' => '100 Wine Lane',
            ]);
            $locationId = $location->id;

            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Show Cab',
                'vintage' => 2024,
                'varietal' => 'Cabernet Sauvignon',
            ]);

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'on_hand' => 120,
                'committed' => 15,
            ]);
        });

        $response = test()->getJson("/api/v1/locations/{$locationId}", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();

        $data = $response->json('data');
        expect($data['id'])->toBe($locationId);
        expect($data['name'])->toBe('Tasting Room Floor');
        expect($data['stock_levels'])->toHaveCount(1);
        expect($data['stock_levels'][0]['on_hand'])->toBe(120);
        expect($data['stock_levels'][0]['committed'])->toBe(15);
        expect($data['stock_levels'][0]['available'])->toBe(105);
        expect($data['stock_levels'][0]['sku']['wine_name'])->toBe('2024 Show Cab');
    });

    it('updates an existing location', function () {
        [$tenant, $token] = createLocationTestTenant('loc-update');

        $locationId = null;
        $tenant->run(function () use (&$locationId) {
            $location = Location::create([
                'name' => 'Old Warehouse',
                'address' => '789 Old Rd',
                'is_active' => true,
            ]);
            $locationId = $location->id;
        });

        $response = test()->putJson("/api/v1/locations/{$locationId}", [
            'name' => 'New Warehouse',
            'is_active' => false,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();

        $data = $response->json('data');
        expect($data['name'])->toBe('New Warehouse');
        expect($data['is_active'])->toBeFalse();
        // Unchanged fields persist
        expect($data['address'])->toBe('789 Old Rd');
    });
})->group('inventory');

// ─── Tier 2: Validation ──────────────────────────────────────────

describe('location validation', function () {
    it('rejects location creation with missing name', function () {
        [$tenant, $token] = createLocationTestTenant('loc-val-missing');

        $response = test()->postJson('/api/v1/locations', [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('name');
    });

    it('rejects name exceeding max length', function () {
        [$tenant, $token] = createLocationTestTenant('loc-val-maxlen');

        $response = test()->postJson('/api/v1/locations', [
            'name' => str_repeat('A', 101),
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('name');
    });
})->group('inventory');

// ─── Tier 2: RBAC ────────────────────────────────────────────────

describe('location RBAC', function () {
    it('winemaker can create locations', function () {
        [$tenant, $token] = createLocationTestTenant('loc-rbac-wm', 'winemaker');

        test()->postJson('/api/v1/locations', [
            'name' => 'Winemaker Location',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    });

    it('read-only users cannot create locations', function () {
        [$tenant, $token] = createLocationTestTenant('loc-rbac-ro', 'read_only');

        test()->postJson('/api/v1/locations', [
            'name' => 'Read Only Location',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });

    it('cellar_hand cannot create locations', function () {
        [$tenant, $token] = createLocationTestTenant('loc-rbac-ch', 'cellar_hand');

        test()->postJson('/api/v1/locations', [
            'name' => 'Cellar Hand Location',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });

    it('read-only users can list and view locations', function () {
        [$tenant, $token] = createLocationTestTenant('loc-rbac-ro-view', 'read_only');

        $locationId = null;
        $tenant->run(function () use (&$locationId) {
            $location = Location::create([
                'name' => 'Viewable Location',
            ]);
            $locationId = $location->id;
        });

        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ];

        test()->getJson('/api/v1/locations', $headers)->assertOk();
        test()->getJson("/api/v1/locations/{$locationId}", $headers)->assertOk();
    });

    it('read-only users cannot update locations', function () {
        [$tenant, $token] = createLocationTestTenant('loc-rbac-ro-upd', 'read_only');

        $locationId = null;
        $tenant->run(function () use (&$locationId) {
            $location = Location::create(['name' => 'No Update']);
            $locationId = $location->id;
        });

        test()->putJson("/api/v1/locations/{$locationId}", [
            'name' => 'Updated Name',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });
})->group('inventory');

// ─── Tier 2: Stock Level Relationships ──────────────────────────

describe('stock level relationships', function () {
    it('tracks per-SKU per-location stock levels', function () {
        [$tenant, $token] = createLocationTestTenant('loc-stock-multi');

        $tenant->run(function () {
            $location1 = Location::create(['name' => 'Tasting Room']);
            $location2 = Location::create(['name' => 'Back Stock']);

            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Multi-Location Wine',
                'vintage' => 2024,
                'varietal' => 'Pinot Noir',
            ]);

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location1->id,
                'on_hand' => 24,
                'committed' => 6,
            ]);

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location2->id,
                'on_hand' => 200,
                'committed' => 0,
            ]);

            // SKU has stock at 2 locations
            expect($sku->stockLevels()->count())->toBe(2);

            // Location 1 has 1 SKU stocked
            expect($location1->stockLevels()->count())->toBe(1);

            // Verify available calculations
            $tastingRoomStock = StockLevel::where('location_id', $location1->id)->first();
            expect($tastingRoomStock->available)->toBe(18);

            $backStock = StockLevel::where('location_id', $location2->id)->first();
            expect($backStock->available)->toBe(200);
        });
    });

    it('cascades delete when SKU is deleted', function () {
        [$tenant, $token] = createLocationTestTenant('loc-stock-cascade-sku');

        $tenant->run(function () {
            $location = Location::create(['name' => 'Cascade Test Room']);
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Cascade Wine',
                'vintage' => 2024,
                'varietal' => 'Syrah',
            ]);

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'on_hand' => 50,
            ]);

            expect(StockLevel::count())->toBe(1);

            $sku->delete();

            expect(StockLevel::count())->toBe(0);
        });
    });

    it('cascades delete when Location is deleted', function () {
        [$tenant, $token] = createLocationTestTenant('loc-stock-cascade-loc');

        $tenant->run(function () {
            $location = Location::create(['name' => 'Doomed Location']);
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Orphan Wine',
                'vintage' => 2024,
                'varietal' => 'Merlot',
            ]);

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'on_hand' => 30,
            ]);

            expect(StockLevel::count())->toBe(1);

            $location->delete();

            expect(StockLevel::count())->toBe(0);
        });
    });
})->group('inventory');

// ─── Tier 2: API Envelope ────────────────────────────────────────

describe('location API envelope', function () {
    it('returns responses in the standard API envelope format', function () {
        [$tenant, $token] = createLocationTestTenant('loc-envelope');

        $response = test()->postJson('/api/v1/locations', [
            'name' => 'Envelope Test Location',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'name', 'address', 'is_active'],
            'meta',
            'errors',
        ]);
        expect($response->json('errors'))->toBe([]);
    });

    it('rejects unauthenticated access', function () {
        test()->getJson('/api/v1/locations', [
            'X-Tenant-ID' => 'fake-tenant',
        ])->assertStatus(401);
    });
})->group('inventory');
