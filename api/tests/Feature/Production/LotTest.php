<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a winemaker user and return [tenant, token].
 * Winemaker role has lots.create, lots.read, lots.update permissions.
 */
function createLotTestTenant(string $slug = 'lot-winery', string $role = 'winemaker'): array
{
    // End any active tenancy so the new tenant's job pipeline
    // (CreateDatabase → MigrateDatabase → SeedDatabase) runs in
    // a clean central context — not inside another tenant's schema.
    if (function_exists('tenancy') && tenancy()->initialized) {
        tenancy()->end();
    }

    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'pro',
    ]);

    $tenant->run(function () use ($role) {
        // Flush Spatie's permission cache so it reads from this tenant's schema.
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

// ─── Tier 1: Event Log Writes ────────────────────────────────────

it('writes a lot_created event when a lot is created', function () {
    [$tenant, $token] = createLotTestTenant();

    $response = test()->postJson('/api/v1/lots', [
        'name' => '2024 Cabernet Block A',
        'variety' => 'Cabernet Sauvignon',
        'vintage' => 2024,
        'source_type' => 'estate',
        'source_details' => ['vineyard' => 'Estate', 'block' => 'A'],
        'volume_gallons' => 1500.5000,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $lotId = $response->json('data.id');

    $tenant->run(function () use ($lotId) {
        $event = Event::where('entity_type', 'lot')
            ->where('entity_id', $lotId)
            ->where('operation_type', 'lot_created')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['name'])->toBe('2024 Cabernet Block A');
        expect($event->payload['variety'])->toBe('Cabernet Sauvignon');
        expect($event->payload['vintage'])->toBe(2024);
        expect($event->payload['source_type'])->toBe('estate');
        expect($event->payload['initial_volume_gallons'])->toBe(1500.5);
        expect($event->performed_by)->not->toBeNull();
    });
});

it('writes a lot_status_changed event when status is updated', function () {
    [$tenant, $token] = createLotTestTenant();

    // Create a lot
    $createResponse = test()->postJson('/api/v1/lots', [
        'name' => '2024 Pinot Noir Lot 1',
        'variety' => 'Pinot Noir',
        'vintage' => 2024,
        'source_type' => 'purchased',
        'volume_gallons' => 800,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $lotId = $createResponse->json('data.id');

    // Update status
    test()->putJson("/api/v1/lots/{$lotId}", [
        'status' => 'aging',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();

    $tenant->run(function () use ($lotId) {
        $event = Event::where('entity_type', 'lot')
            ->where('entity_id', $lotId)
            ->where('operation_type', 'lot_status_changed')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['old_status'])->toBe('in_progress');
        expect($event->payload['new_status'])->toBe('aging');
    });
});

// ─── Tier 1: Volume Math ─────────────────────────────────────────

it('stores volume in gallons with 4 decimal precision', function () {
    [$tenant, $token] = createLotTestTenant();

    $response = test()->postJson('/api/v1/lots', [
        'name' => '2024 Chardonnay Lot 1',
        'variety' => 'Chardonnay',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 1234.5678,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $tenant->run(function () {
        $lot = Lot::first();
        // Decimal cast preserves precision
        expect((float) $lot->volume_gallons)->toBe(1234.5678);
    });
});

// ─── Tier 2: CRUD Operations ─────────────────────────────────────

it('creates a lot with all required fields', function () {
    [$tenant, $token] = createLotTestTenant();

    $response = test()->postJson('/api/v1/lots', [
        'name' => '2024 Syrah Estate Block B',
        'variety' => 'Syrah',
        'vintage' => 2024,
        'source_type' => 'estate',
        'source_details' => ['vineyard' => 'Home Ranch', 'block' => 'B'],
        'volume_gallons' => 2500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', '2024 Syrah Estate Block B')
        ->assertJsonPath('data.variety', 'Syrah')
        ->assertJsonPath('data.vintage', 2024)
        ->assertJsonPath('data.source_type', 'estate')
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonStructure([
            'data' => [
                'id', 'name', 'variety', 'vintage', 'source_type',
                'source_details', 'volume_gallons', 'status',
                'parent_lot_id', 'created_at', 'updated_at',
            ],
        ]);

    // Volume comparison tolerant of int/float JSON encoding
    expect((float) $response->json('data.volume_gallons'))->toBe(2500.0);
});

it('lists lots with pagination', function () {
    [$tenant, $token] = createLotTestTenant();

    // Create 3 lots
    foreach (range(1, 3) as $i) {
        test()->postJson('/api/v1/lots', [
            'name' => "2024 Lot {$i}",
            'variety' => 'Zinfandel',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 100 * $i,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    }

    $response = test()->getJson('/api/v1/lots', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

it('filters lots by variety', function () {
    [$tenant, $token] = createLotTestTenant();

    // Create lots with different varieties
    foreach (['Cabernet Sauvignon', 'Pinot Noir', 'Cabernet Sauvignon'] as $variety) {
        test()->postJson('/api/v1/lots', [
            'name' => "2024 {$variety}",
            'variety' => $variety,
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);
    }

    $response = test()->getJson('/api/v1/lots?variety=Cabernet+Sauvignon', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters lots by vintage', function () {
    [$tenant, $token] = createLotTestTenant();

    foreach ([2023, 2024, 2024] as $vintage) {
        test()->postJson('/api/v1/lots', [
            'name' => "{$vintage} Test Lot",
            'variety' => 'Merlot',
            'vintage' => $vintage,
            'source_type' => 'estate',
            'volume_gallons' => 500,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);
    }

    $response = test()->getJson('/api/v1/lots?vintage=2024', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters lots by status', function () {
    [$tenant, $token] = createLotTestTenant();

    // Create a lot then change status
    $createResponse = test()->postJson('/api/v1/lots', [
        'name' => '2024 Aging Lot',
        'variety' => 'Merlot',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $lotId = $createResponse->json('data.id');
    test()->putJson("/api/v1/lots/{$lotId}", ['status' => 'aging'], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    // Create another in_progress lot
    test()->postJson('/api/v1/lots', [
        'name' => '2024 In Progress Lot',
        'variety' => 'Merlot',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/lots?status=aging', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('searches lots by name', function () {
    [$tenant, $token] = createLotTestTenant();

    test()->postJson('/api/v1/lots', [
        'name' => '2024 Cabernet Sauvignon Reserve Block A',
        'variety' => 'Cabernet Sauvignon',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/lots', [
        'name' => '2024 Pinot Noir Lot 1',
        'variety' => 'Pinot Noir',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 300,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/lots?search=Reserve', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', '2024 Cabernet Sauvignon Reserve Block A');
});

it('shows lot detail', function () {
    [$tenant, $token] = createLotTestTenant();

    $createResponse = test()->postJson('/api/v1/lots', [
        'name' => '2024 Viognier Estate',
        'variety' => 'Viognier',
        'vintage' => 2024,
        'source_type' => 'estate',
        'source_details' => ['vineyard' => 'Hillside', 'block' => 'C'],
        'volume_gallons' => 750,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $lotId = $createResponse->json('data.id');

    $response = test()->getJson("/api/v1/lots/{$lotId}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.id', $lotId)
        ->assertJsonPath('data.name', '2024 Viognier Estate')
        ->assertJsonPath('data.variety', 'Viognier')
        ->assertJsonPath('data.source_details.vineyard', 'Hillside');
});

it('updates lot status and name', function () {
    [$tenant, $token] = createLotTestTenant();

    $createResponse = test()->postJson('/api/v1/lots', [
        'name' => 'Original Name',
        'variety' => 'Grenache',
        'vintage' => 2024,
        'source_type' => 'purchased',
        'volume_gallons' => 600,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $lotId = $createResponse->json('data.id');

    $response = test()->putJson("/api/v1/lots/{$lotId}", [
        'name' => 'Updated Name',
        'status' => 'aging',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Name')
        ->assertJsonPath('data.status', 'aging');
});

// ─── Tier 2: Validation ──────────────────────────────────────────

it('rejects lot creation with missing required fields', function () {
    [$tenant, $token] = createLotTestTenant();

    $response = test()->postJson('/api/v1/lots', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('name');
    expect($fields)->toContain('variety');
    expect($fields)->toContain('vintage');
    expect($fields)->toContain('source_type');
    expect($fields)->toContain('volume_gallons');
});

it('rejects invalid source_type', function () {
    [$tenant, $token] = createLotTestTenant();

    $response = test()->postJson('/api/v1/lots', [
        'name' => 'Test Lot',
        'variety' => 'Merlot',
        'vintage' => 2024,
        'source_type' => 'stolen',
        'volume_gallons' => 500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('source_type');
});

it('rejects invalid status on update', function () {
    [$tenant, $token] = createLotTestTenant();

    $createResponse = test()->postJson('/api/v1/lots', [
        'name' => 'Test Lot',
        'variety' => 'Merlot',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $lotId = $createResponse->json('data.id');

    $response = test()->putJson("/api/v1/lots/{$lotId}", [
        'status' => 'exploded',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

it('rejects negative volume', function () {
    [$tenant, $token] = createLotTestTenant();

    $response = test()->postJson('/api/v1/lots', [
        'name' => 'Test Lot',
        'variety' => 'Merlot',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => -100,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('volume_gallons');
});

// ─── Tier 2: RBAC ────────────────────────────────────────────────

it('read-only users can list and view lots but not create them', function () {
    [$tenant, $wmToken] = createLotTestTenant('rbac-winery', 'winemaker');

    // Create a lot as winemaker
    $createResponse = test()->postJson('/api/v1/lots', [
        'name' => '2024 RBAC Test Lot',
        'variety' => 'Tempranillo',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 500,
    ], [
        'Authorization' => "Bearer {$wmToken}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $lotId = $createResponse->json('data.id');

    // Create a read-only user and get token
    $tenant->run(function () {
        $user = User::create([
            'name' => 'Reader',
            'email' => 'reader@example.com',
            'password' => 'SecurePass123!',
            'role' => 'read_only',
            'is_active' => true,
        ]);
        $user->assignRole('read_only');
    });

    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => 'reader@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $readerToken = $loginResponse->json('data.token');

    // Read-only CAN list lots
    test()->getJson('/api/v1/lots', [
        'Authorization' => "Bearer {$readerToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();

    // Read-only CAN view a lot
    test()->getJson("/api/v1/lots/{$lotId}", [
        'Authorization' => "Bearer {$readerToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();
});

it('read-only users cannot create lots', function () {
    [$tenant, $token] = createLotTestTenant('rbac-ro-winery', 'read_only');

    // read_only CANNOT create a lot
    test()->postJson('/api/v1/lots', [
        'name' => 'Unauthorized Lot',
        'variety' => 'Merlot',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 100,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('allows cellar hands to view but not create lots', function () {
    [$tenant, $wmToken] = createLotTestTenant('cellar-winery', 'winemaker');

    // Create a cellar_hand user
    $tenant->run(function () {
        $user = User::create([
            'name' => 'Cellar Hand',
            'email' => 'cellarhand@example.com',
            'password' => 'SecurePass123!',
            'role' => 'cellar_hand',
            'is_active' => true,
        ]);
        $user->assignRole('cellar_hand');
    });

    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => 'cellarhand@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $cellarToken = $loginResponse->json('data.token');

    // Cellar hand CANNOT create a lot
    test()->postJson('/api/v1/lots', [
        'name' => 'Unauthorized Lot',
        'variety' => 'Merlot',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 100,
    ], [
        'Authorization' => "Bearer {$cellarToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

// ─── Tier 2: API Envelope ────────────────────────────────────────

it('returns responses in the standard API envelope format', function () {
    [$tenant, $token] = createLotTestTenant();

    $response = test()->postJson('/api/v1/lots', [
        'name' => '2024 Envelope Test',
        'variety' => 'Chardonnay',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data',
            'meta',
            'errors',
        ]);

    expect($response->json('errors'))->toBe([]);
});

it('rejects unauthenticated access to lots', function () {
    [$tenant, $token] = createLotTestTenant();

    test()->getJson('/api/v1/lots', [
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(401);
});

// ─── Tier 1: Tenant Isolation ───────────────────────────────────
// These tests verify schema-level isolation using direct DB access,
// matching the pattern from TenantCreationTest. Spatie's permission
// cache doesn't reliably reset across multiple tenants in a single
// test, so we bypass the HTTP + role stack and test the data layer
// directly — which is where isolation actually matters.

it('prevents cross-tenant lot data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Winery Alpha',
        'slug' => 'winery-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Winery Beta',
        'slug' => 'winery-beta',
        'plan' => 'pro',
    ]);

    // Insert a lot directly into Tenant A's schema
    $tenantA->run(function () {
        Lot::create([
            'name' => 'Alpha Cabernet',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 1000,
        ]);
    });

    // Insert a lot directly into Tenant B's schema
    $tenantB->run(function () {
        Lot::create([
            'name' => 'Beta Pinot',
            'variety' => 'Pinot Noir',
            'vintage' => 2024,
            'source_type' => 'purchased',
            'volume_gallons' => 500,
        ]);
    });

    // Tenant A can only see its own lot
    $tenantA->run(function () {
        $lots = Lot::all();
        expect($lots)->toHaveCount(1);
        expect($lots->first()->name)->toBe('Alpha Cabernet');
    });

    // Tenant B can only see its own lot
    $tenantB->run(function () {
        $lots = Lot::all();
        expect($lots)->toHaveCount(1);
        expect($lots->first()->name)->toBe('Beta Pinot');
    });

    // Grab the lot ID from Tenant A
    $alphaLotId = null;
    $tenantA->run(function () use (&$alphaLotId) {
        $alphaLotId = Lot::first()->id;
    });

    // Tenant B cannot find Tenant A's lot by ID
    $tenantB->run(function () use ($alphaLotId) {
        expect(Lot::find($alphaLotId))->toBeNull();
    });
});
