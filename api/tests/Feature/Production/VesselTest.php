<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 * Uses a unique function name to avoid collision with LotTest helper.
 */
function createVesselTestTenant(string $slug = 'vessel-winery', string $role = 'winemaker'): array
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

it('writes a vessel_created event when a vessel is created', function () {
    [$tenant, $token] = createVesselTestTenant();

    $response = test()->postJson('/api/v1/vessels', [
        'name' => 'T-001',
        'type' => 'tank',
        'capacity_gallons' => 2000,
        'material' => 'stainless steel',
        'location' => 'Tank Hall',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $vesselId = $response->json('data.id');

    $tenant->run(function () use ($vesselId) {
        $event = Event::where('entity_type', 'vessel')
            ->where('entity_id', $vesselId)
            ->where('operation_type', 'vessel_created')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['name'])->toBe('T-001');
        expect($event->payload['type'])->toBe('tank');
        expect((float) $event->payload['capacity_gallons'])->toBe(2000.0);
        expect($event->payload['material'])->toBe('stainless steel');
        expect($event->payload['location'])->toBe('Tank Hall');
        expect($event->performed_by)->not->toBeNull();
    });
});

it('writes a vessel_status_changed event when status is updated', function () {
    [$tenant, $token] = createVesselTestTenant();

    // Create a vessel
    $createResponse = test()->postJson('/api/v1/vessels', [
        'name' => 'T-002',
        'type' => 'tank',
        'capacity_gallons' => 1500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $vesselId = $createResponse->json('data.id');

    // Update status
    test()->putJson("/api/v1/vessels/{$vesselId}", [
        'status' => 'in_use',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();

    $tenant->run(function () use ($vesselId) {
        $event = Event::where('entity_type', 'vessel')
            ->where('entity_id', $vesselId)
            ->where('operation_type', 'vessel_status_changed')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['old_status'])->toBe('empty');
        expect($event->payload['new_status'])->toBe('in_use');
    });
});

// ─── Tier 2: CRUD Operations ─────────────────────────────────────

it('creates a vessel with all required fields', function () {
    [$tenant, $token] = createVesselTestTenant();

    $response = test()->postJson('/api/v1/vessels', [
        'name' => 'T-010',
        'type' => 'tank',
        'capacity_gallons' => 3000.5000,
        'material' => 'stainless steel (jacketed)',
        'location' => 'Tank Hall - North',
        'purchase_date' => '2024-06-15',
        'notes' => 'Variable capacity lid installed',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'T-010')
        ->assertJsonPath('data.type', 'tank')
        ->assertJsonPath('data.material', 'stainless steel (jacketed)')
        ->assertJsonPath('data.location', 'Tank Hall - North')
        ->assertJsonPath('data.status', 'empty')
        ->assertJsonPath('data.purchase_date', '2024-06-15')
        ->assertJsonPath('data.notes', 'Variable capacity lid installed')
        ->assertJsonPath('data.current_volume', 0)
        ->assertJsonPath('data.fill_percent', 0)
        ->assertJsonPath('data.current_lot', null)
        ->assertJsonStructure([
            'data' => [
                'id', 'name', 'type', 'capacity_gallons', 'material',
                'location', 'status', 'purchase_date', 'notes',
                'current_volume', 'fill_percent', 'current_lot',
                'created_at', 'updated_at',
            ],
        ]);

    // Volume comparison tolerant of int/float JSON encoding
    expect((float) $response->json('data.capacity_gallons'))->toBe(3000.5);
});

it('lists vessels with pagination', function () {
    [$tenant, $token] = createVesselTestTenant();

    // Create 3 vessels
    foreach (range(1, 3) as $i) {
        test()->postJson('/api/v1/vessels', [
            'name' => "T-00{$i}",
            'type' => 'tank',
            'capacity_gallons' => 1000 * $i,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    }

    $response = test()->getJson('/api/v1/vessels', [
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

it('filters vessels by type', function () {
    [$tenant, $token] = createVesselTestTenant();

    // Create vessels with different types
    test()->postJson('/api/v1/vessels', [
        'name' => 'T-001',
        'type' => 'tank',
        'capacity_gallons' => 2000,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/vessels', [
        'name' => 'B-001',
        'type' => 'barrel',
        'capacity_gallons' => 60,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/vessels', [
        'name' => 'T-002',
        'type' => 'tank',
        'capacity_gallons' => 1500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/vessels?type=tank', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters vessels by status', function () {
    [$tenant, $token] = createVesselTestTenant();

    // Create a vessel and change its status
    $createResponse = test()->postJson('/api/v1/vessels', [
        'name' => 'T-001',
        'type' => 'tank',
        'capacity_gallons' => 2000,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $vesselId = $createResponse->json('data.id');
    test()->putJson("/api/v1/vessels/{$vesselId}", ['status' => 'in_use'], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    // Create another vessel (stays empty)
    test()->postJson('/api/v1/vessels', [
        'name' => 'T-002',
        'type' => 'tank',
        'capacity_gallons' => 1500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/vessels?status=in_use', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters vessels by location', function () {
    [$tenant, $token] = createVesselTestTenant();

    test()->postJson('/api/v1/vessels', [
        'name' => 'T-001',
        'type' => 'tank',
        'capacity_gallons' => 2000,
        'location' => 'Tank Hall - North',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/vessels', [
        'name' => 'B-001',
        'type' => 'barrel',
        'capacity_gallons' => 60,
        'location' => 'Barrel Room A',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/vessels?location=Tank+Hall', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('searches vessels by name', function () {
    [$tenant, $token] = createVesselTestTenant();

    test()->postJson('/api/v1/vessels', [
        'name' => 'FT-001',
        'type' => 'flexitank',
        'capacity_gallons' => 300,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/vessels', [
        'name' => 'T-001',
        'type' => 'tank',
        'capacity_gallons' => 2000,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/vessels?search=FT-001', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'FT-001');
});

it('shows vessel detail', function () {
    [$tenant, $token] = createVesselTestTenant();

    $createResponse = test()->postJson('/api/v1/vessels', [
        'name' => 'CE-001',
        'type' => 'concrete_egg',
        'capacity_gallons' => 250,
        'material' => 'concrete (wax-lined)',
        'location' => 'Cave',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $vesselId = $createResponse->json('data.id');

    $response = test()->getJson("/api/v1/vessels/{$vesselId}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.id', $vesselId)
        ->assertJsonPath('data.name', 'CE-001')
        ->assertJsonPath('data.type', 'concrete_egg')
        ->assertJsonPath('data.material', 'concrete (wax-lined)')
        ->assertJsonPath('data.location', 'Cave');
});

it('updates vessel status and location', function () {
    [$tenant, $token] = createVesselTestTenant();

    $createResponse = test()->postJson('/api/v1/vessels', [
        'name' => 'T-005',
        'type' => 'tank',
        'capacity_gallons' => 1000,
        'location' => 'Tank Hall',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $vesselId = $createResponse->json('data.id');

    $response = test()->putJson("/api/v1/vessels/{$vesselId}", [
        'status' => 'cleaning',
        'location' => 'Wash Bay',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'cleaning')
        ->assertJsonPath('data.location', 'Wash Bay');
});

// ─── Tier 2: Current Contents & Fill % ──────────────────────────

it('shows current lot and fill percentage when vessel has contents', function () {
    [$tenant, $token] = createVesselTestTenant();

    // Create a vessel
    $vesselResponse = test()->postJson('/api/v1/vessels', [
        'name' => 'T-020',
        'type' => 'tank',
        'capacity_gallons' => 2000,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $vesselId = $vesselResponse->json('data.id');

    // Create a lot
    $lotResponse = test()->postJson('/api/v1/lots', [
        'name' => '2024 Cab Sauv Lot 1',
        'variety' => 'Cabernet Sauvignon',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 1500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $lotId = $lotResponse->json('data.id');

    // Manually insert a lot_vessel pivot record (simulating a fill operation)
    $tenant->run(function () use ($vesselId, $lotId) {
        DB::table('lot_vessel')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'lot_id' => $lotId,
            'vessel_id' => $vesselId,
            'volume_gallons' => 1500,
            'filled_at' => now(),
            'emptied_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    // Get vessel detail — should show current lot and fill %
    $response = test()->getJson("/api/v1/vessels/{$vesselId}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.current_lot.id', $lotId)
        ->assertJsonPath('data.current_lot.name', '2024 Cab Sauv Lot 1')
        ->assertJsonPath('data.current_lot.variety', 'Cabernet Sauvignon');

    expect((float) $response->json('data.current_volume'))->toBe(1500.0);
    expect((float) $response->json('data.fill_percent'))->toBe(75.0);
});

// ─── Tier 2: Validation ──────────────────────────────────────────

it('rejects vessel creation with missing required fields', function () {
    [$tenant, $token] = createVesselTestTenant();

    $response = test()->postJson('/api/v1/vessels', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('name');
    expect($fields)->toContain('type');
    expect($fields)->toContain('capacity_gallons');
});

it('rejects invalid vessel type', function () {
    [$tenant, $token] = createVesselTestTenant();

    $response = test()->postJson('/api/v1/vessels', [
        'name' => 'X-001',
        'type' => 'swimming_pool',
        'capacity_gallons' => 50000,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('type');
});

it('rejects invalid status on update', function () {
    [$tenant, $token] = createVesselTestTenant();

    $createResponse = test()->postJson('/api/v1/vessels', [
        'name' => 'T-001',
        'type' => 'tank',
        'capacity_gallons' => 1000,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $vesselId = $createResponse->json('data.id');

    $response = test()->putJson("/api/v1/vessels/{$vesselId}", [
        'status' => 'exploded',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

it('rejects negative capacity', function () {
    [$tenant, $token] = createVesselTestTenant();

    $response = test()->postJson('/api/v1/vessels', [
        'name' => 'T-001',
        'type' => 'tank',
        'capacity_gallons' => -500,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('capacity_gallons');
});

// ─── Tier 2: RBAC ────────────────────────────────────────────────

it('read-only users cannot create vessels', function () {
    [$tenant, $token] = createVesselTestTenant('rbac-ro-vessel', 'read_only');

    test()->postJson('/api/v1/vessels', [
        'name' => 'Unauthorized Tank',
        'type' => 'tank',
        'capacity_gallons' => 1000,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('read-only users can list and view vessels', function () {
    [$tenant, $wmToken] = createVesselTestTenant('rbac-view-vessel', 'winemaker');

    // Create a vessel as winemaker
    $createResponse = test()->postJson('/api/v1/vessels', [
        'name' => 'T-100',
        'type' => 'tank',
        'capacity_gallons' => 1000,
    ], [
        'Authorization' => "Bearer {$wmToken}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $vesselId = $createResponse->json('data.id');

    // Create a read-only user
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

    // Read-only CAN list vessels
    test()->getJson('/api/v1/vessels', [
        'Authorization' => "Bearer {$readerToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();

    // Read-only CAN view a vessel
    test()->getJson("/api/v1/vessels/{$vesselId}", [
        'Authorization' => "Bearer {$readerToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();
});

// ─── Tier 2: API Envelope ────────────────────────────────────────

it('returns vessel responses in the standard API envelope format', function () {
    [$tenant, $token] = createVesselTestTenant();

    $response = test()->postJson('/api/v1/vessels', [
        'name' => 'T-099',
        'type' => 'tank',
        'capacity_gallons' => 500,
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

it('rejects unauthenticated access to vessels', function () {
    [$tenant, $token] = createVesselTestTenant();

    test()->getJson('/api/v1/vessels', [
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(401);
});

// ─── Tier 1: Tenant Isolation ───────────────────────────────────
// These tests verify schema-level isolation using direct DB access,
// matching the pattern from TenantCreationTest. Spatie's permission
// cache doesn't reliably reset across multiple tenants in a single
// test, so we bypass the HTTP + role stack and test the data layer
// directly — which is where isolation actually matters.

it('prevents cross-tenant vessel data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Winery Alpha',
        'slug' => 'vessel-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Winery Beta',
        'slug' => 'vessel-iso-beta',
        'plan' => 'pro',
    ]);

    // Insert a vessel directly into Tenant A's schema
    $tenantA->run(function () {
        Vessel::create([
            'name' => 'T-001-Alpha',
            'type' => 'tank',
            'capacity_gallons' => 2000,
            'location' => 'Winery A Tank Hall',
        ]);
    });

    // Insert a vessel directly into Tenant B's schema
    $tenantB->run(function () {
        Vessel::create([
            'name' => 'B-001-Beta',
            'type' => 'barrel',
            'capacity_gallons' => 60,
            'location' => 'Winery B Barrel Room',
        ]);
    });

    // Tenant A can only see its own vessel
    $tenantA->run(function () {
        $vessels = Vessel::all();
        expect($vessels)->toHaveCount(1);
        expect($vessels->first()->name)->toBe('T-001-Alpha');
    });

    // Tenant B can only see its own vessel
    $tenantB->run(function () {
        $vessels = Vessel::all();
        expect($vessels)->toHaveCount(1);
        expect($vessels->first()->name)->toBe('B-001-Beta');
    });

    // Grab the vessel ID from Tenant A
    $alphaVesselId = null;
    $tenantA->run(function () use (&$alphaVesselId) {
        $alphaVesselId = Vessel::first()->id;
    });

    // Tenant B cannot find Tenant A's vessel by ID
    $tenantB->run(function () use ($alphaVesselId) {
        expect(Vessel::find($alphaVesselId))->toBeNull();
    });
});
