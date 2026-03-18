<?php

declare(strict_types=1);

use App\Models\Barrel;
use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createBarrelTestTenant(string $slug = 'barrel-winery', string $role = 'winemaker'): array
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

it('writes a barrel_created event when a barrel is created', function () {
    [$tenant, $token] = createBarrelTestTenant();

    $response = test()->postJson('/api/v1/barrels', [
        'name' => 'B-001',
        'cooperage' => 'François Frères',
        'toast_level' => 'medium',
        'oak_type' => 'french',
        'forest_origin' => 'Allier',
        'volume_gallons' => 59.43,
        'years_used' => 0,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $barrelId = $response->json('data.id');

    $tenant->run(function () use ($barrelId) {
        $event = Event::where('entity_type', 'barrel')
            ->where('entity_id', $barrelId)
            ->where('operation_type', 'barrel_created')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['cooperage'])->toBe('François Frères');
        expect($event->payload['toast_level'])->toBe('medium');
        expect($event->payload['oak_type'])->toBe('french');
        expect($event->payload['forest_origin'])->toBe('Allier');
        expect((float) $event->payload['volume_gallons'])->toBe(59.43);
        expect($event->payload['years_used'])->toBe(0);
        expect($event->payload['vessel_id'])->not->toBeNull();
        expect($event->payload['name'])->toBe('B-001');
        expect($event->performed_by)->not->toBeNull();
    });
});

it('writes a barrel_status_changed event when barrel is retired', function () {
    [$tenant, $token] = createBarrelTestTenant();

    $createResponse = test()->postJson('/api/v1/barrels', [
        'name' => 'B-002',
        'cooperage' => 'Demptos',
        'toast_level' => 'heavy',
        'oak_type' => 'french',
        'volume_gallons' => 60,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $barrelId = $createResponse->json('data.id');

    // Retire the barrel (status → out_of_service)
    test()->putJson("/api/v1/barrels/{$barrelId}", [
        'status' => 'out_of_service',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();

    $tenant->run(function () use ($barrelId) {
        $event = Event::where('entity_type', 'barrel')
            ->where('entity_id', $barrelId)
            ->where('operation_type', 'barrel_status_changed')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['old_status'])->toBe('empty');
        expect($event->payload['new_status'])->toBe('out_of_service');
    });
});

// ─── Tier 2: CRUD Operations ─────────────────────────────────────

it('creates a barrel with vessel and barrel metadata', function () {
    [$tenant, $token] = createBarrelTestTenant();

    $response = test()->postJson('/api/v1/barrels', [
        'name' => 'B-010',
        'cooperage' => 'Seguin Moreau',
        'toast_level' => 'medium_plus',
        'oak_type' => 'french',
        'forest_origin' => 'Tronçais',
        'volume_gallons' => 59.43,
        'years_used' => 2,
        'location' => 'Barrel Room A - Row 3',
        'qr_code' => 'BRL-0010',
        'purchase_date' => '2024-01-15',
        'notes' => 'Excellent stave quality',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'B-010')
        ->assertJsonPath('data.cooperage', 'Seguin Moreau')
        ->assertJsonPath('data.toast_level', 'medium_plus')
        ->assertJsonPath('data.oak_type', 'french')
        ->assertJsonPath('data.forest_origin', 'Tronçais')
        ->assertJsonPath('data.years_used', 2)
        ->assertJsonPath('data.location', 'Barrel Room A - Row 3')
        ->assertJsonPath('data.qr_code', 'BRL-0010')
        ->assertJsonPath('data.purchase_date', '2024-01-15')
        ->assertJsonPath('data.notes', 'Excellent stave quality')
        ->assertJsonPath('data.status', 'empty')
        ->assertJsonStructure([
            'data' => [
                'id', 'vessel_id', 'name', 'cooperage', 'toast_level',
                'oak_type', 'forest_origin', 'volume_gallons', 'years_used',
                'qr_code', 'location', 'status', 'purchase_date', 'notes',
                'current_volume', 'fill_percent',
                'created_at', 'updated_at',
            ],
        ]);

    expect((float) $response->json('data.volume_gallons'))->toBe(59.43);

    // Verify a vessel was created with type=barrel
    $tenant->run(function () use ($response) {
        $vessel = Vessel::find($response->json('data.vessel_id'));
        expect($vessel)->not->toBeNull();
        expect($vessel->type)->toBe('barrel');
        expect($vessel->name)->toBe('B-010');
    });
});

it('lists barrels with pagination', function () {
    [$tenant, $token] = createBarrelTestTenant();

    foreach (range(1, 3) as $i) {
        test()->postJson('/api/v1/barrels', [
            'name' => sprintf('B-%03d', $i),
            'cooperage' => 'Demptos',
            'toast_level' => 'medium',
            'oak_type' => 'french',
            'volume_gallons' => 59.43,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    }

    $response = test()->getJson('/api/v1/barrels', [
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

it('filters barrels by cooperage', function () {
    [$tenant, $token] = createBarrelTestTenant();

    test()->postJson('/api/v1/barrels', [
        'name' => 'B-001',
        'cooperage' => 'François Frères',
        'oak_type' => 'french',
        'volume_gallons' => 59.43,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/barrels', [
        'name' => 'B-002',
        'cooperage' => 'World Cooperage',
        'oak_type' => 'american',
        'volume_gallons' => 60,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/barrels?cooperage=François', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters barrels by oak type', function () {
    [$tenant, $token] = createBarrelTestTenant();

    test()->postJson('/api/v1/barrels', [
        'name' => 'B-001',
        'oak_type' => 'french',
        'volume_gallons' => 59.43,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/barrels', [
        'name' => 'B-002',
        'oak_type' => 'american',
        'volume_gallons' => 60,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/barrels', [
        'name' => 'B-003',
        'oak_type' => 'french',
        'volume_gallons' => 59.43,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/barrels?oak_type=french', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters barrels by toast level', function () {
    [$tenant, $token] = createBarrelTestTenant();

    test()->postJson('/api/v1/barrels', [
        'name' => 'B-001',
        'toast_level' => 'heavy',
        'oak_type' => 'french',
        'volume_gallons' => 59.43,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/barrels', [
        'name' => 'B-002',
        'toast_level' => 'light',
        'oak_type' => 'french',
        'volume_gallons' => 59.43,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/barrels?toast_level=heavy', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters barrels by years used', function () {
    [$tenant, $token] = createBarrelTestTenant();

    test()->postJson('/api/v1/barrels', [
        'name' => 'B-001',
        'oak_type' => 'french',
        'volume_gallons' => 59.43,
        'years_used' => 0,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    test()->postJson('/api/v1/barrels', [
        'name' => 'B-002',
        'oak_type' => 'french',
        'volume_gallons' => 59.43,
        'years_used' => 3,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response = test()->getJson('/api/v1/barrels?years_used=0', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('shows barrel detail with vessel info', function () {
    [$tenant, $token] = createBarrelTestTenant();

    $createResponse = test()->postJson('/api/v1/barrels', [
        'name' => 'B-050',
        'cooperage' => 'Taransaud',
        'toast_level' => 'medium',
        'oak_type' => 'french',
        'forest_origin' => 'Vosges',
        'volume_gallons' => 59.43,
        'years_used' => 1,
        'location' => 'Cave',
        'qr_code' => 'BRL-0050',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $barrelId = $createResponse->json('data.id');

    $response = test()->getJson("/api/v1/barrels/{$barrelId}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.id', $barrelId)
        ->assertJsonPath('data.name', 'B-050')
        ->assertJsonPath('data.cooperage', 'Taransaud')
        ->assertJsonPath('data.toast_level', 'medium')
        ->assertJsonPath('data.oak_type', 'french')
        ->assertJsonPath('data.forest_origin', 'Vosges')
        ->assertJsonPath('data.years_used', 1)
        ->assertJsonPath('data.location', 'Cave')
        ->assertJsonPath('data.qr_code', 'BRL-0050')
        ->assertJsonPath('data.status', 'empty');
});

it('updates barrel metadata and vessel fields', function () {
    [$tenant, $token] = createBarrelTestTenant();

    $createResponse = test()->postJson('/api/v1/barrels', [
        'name' => 'B-060',
        'cooperage' => 'Demptos',
        'toast_level' => 'light',
        'oak_type' => 'american',
        'volume_gallons' => 60,
        'years_used' => 0,
        'location' => 'Barrel Room A',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $barrelId = $createResponse->json('data.id');

    $response = test()->putJson("/api/v1/barrels/{$barrelId}", [
        'years_used' => 1,
        'location' => 'Barrel Room B',
        'notes' => 'Moved after vintage rollover',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.years_used', 1)
        ->assertJsonPath('data.location', 'Barrel Room B')
        ->assertJsonPath('data.notes', 'Moved after vintage rollover');
});

// ─── Tier 2: Vessel + Barrel 1:1 Consistency ───────────────────

it('includes barrel metadata in vessel show endpoint', function () {
    [$tenant, $token] = createBarrelTestTenant();

    $createResponse = test()->postJson('/api/v1/barrels', [
        'name' => 'B-070',
        'cooperage' => 'Radoux',
        'toast_level' => 'medium_plus',
        'oak_type' => 'hungarian',
        'volume_gallons' => 59.43,
        'years_used' => 4,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $vesselId = $createResponse->json('data.vessel_id');

    $response = test()->getJson("/api/v1/vessels/{$vesselId}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.type', 'barrel')
        ->assertJsonPath('data.barrel.cooperage', 'Radoux')
        ->assertJsonPath('data.barrel.toast_level', 'medium_plus')
        ->assertJsonPath('data.barrel.oak_type', 'hungarian')
        ->assertJsonPath('data.barrel.years_used', 4);
});

// ─── Tier 2: Validation ──────────────────────────────────────────

it('rejects barrel creation with missing name', function () {
    [$tenant, $token] = createBarrelTestTenant();

    $response = test()->postJson('/api/v1/barrels', [
        'cooperage' => 'Demptos',
        'toast_level' => 'medium',
        'oak_type' => 'french',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('name');
});

it('rejects invalid toast level', function () {
    [$tenant, $token] = createBarrelTestTenant();

    $response = test()->postJson('/api/v1/barrels', [
        'name' => 'B-ERR',
        'toast_level' => 'charcoal',
        'oak_type' => 'french',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('toast_level');
});

it('rejects invalid oak type', function () {
    [$tenant, $token] = createBarrelTestTenant();

    $response = test()->postJson('/api/v1/barrels', [
        'name' => 'B-ERR',
        'oak_type' => 'plastic',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('oak_type');
});

// ─── Tier 2: RBAC ────────────────────────────────────────────────

it('read-only users cannot create barrels', function () {
    [$tenant, $token] = createBarrelTestTenant('rbac-ro-barrel', 'read_only');

    test()->postJson('/api/v1/barrels', [
        'name' => 'Unauthorized Barrel',
        'oak_type' => 'french',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('read-only users can list and view barrels', function () {
    [$tenant, $wmToken] = createBarrelTestTenant('rbac-view-barrel', 'winemaker');

    // Create a barrel as winemaker
    $createResponse = test()->postJson('/api/v1/barrels', [
        'name' => 'B-100',
        'cooperage' => 'Demptos',
        'toast_level' => 'medium',
        'oak_type' => 'french',
        'volume_gallons' => 59.43,
    ], [
        'Authorization' => "Bearer {$wmToken}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $barrelId = $createResponse->json('data.id');

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

    // Read-only CAN list barrels
    test()->getJson('/api/v1/barrels', [
        'Authorization' => "Bearer {$readerToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();

    // Read-only CAN view a barrel
    test()->getJson("/api/v1/barrels/{$barrelId}", [
        'Authorization' => "Bearer {$readerToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();
});

// ─── Tier 2: API Envelope ────────────────────────────────────────

it('returns barrel responses in the standard API envelope format', function () {
    [$tenant, $token] = createBarrelTestTenant();

    $response = test()->postJson('/api/v1/barrels', [
        'name' => 'B-099',
        'cooperage' => 'Demptos',
        'oak_type' => 'french',
        'volume_gallons' => 59.43,
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

it('rejects unauthenticated access to barrels', function () {
    [$tenant, $token] = createBarrelTestTenant();

    test()->getJson('/api/v1/barrels', [
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(401);
});

// ─── Tier 1: Tenant Isolation ───────────────────────────────────

it('prevents cross-tenant barrel data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Winery Alpha',
        'slug' => 'barrel-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Winery Beta',
        'slug' => 'barrel-iso-beta',
        'plan' => 'pro',
    ]);

    // Create a barrel in Tenant A
    $tenantA->run(function () {
        $vessel = Vessel::create([
            'name' => 'B-001-Alpha',
            'type' => 'barrel',
            'capacity_gallons' => 59.43,
            'location' => 'Winery A Barrel Room',
        ]);

        Barrel::create([
            'vessel_id' => $vessel->id,
            'cooperage' => 'François Frères',
            'oak_type' => 'french',
            'volume_gallons' => 59.43,
            'years_used' => 0,
        ]);
    });

    // Create a barrel in Tenant B
    $tenantB->run(function () {
        $vessel = Vessel::create([
            'name' => 'B-001-Beta',
            'type' => 'barrel',
            'capacity_gallons' => 60,
            'location' => 'Winery B Barrel Room',
        ]);

        Barrel::create([
            'vessel_id' => $vessel->id,
            'cooperage' => 'World Cooperage',
            'oak_type' => 'american',
            'volume_gallons' => 60,
            'years_used' => 2,
        ]);
    });

    // Tenant A can only see its own barrel
    $tenantA->run(function () {
        $barrels = Barrel::all();
        expect($barrels)->toHaveCount(1);
        expect($barrels->first()->cooperage)->toBe('François Frères');
    });

    // Tenant B can only see its own barrel
    $tenantB->run(function () {
        $barrels = Barrel::all();
        expect($barrels)->toHaveCount(1);
        expect($barrels->first()->cooperage)->toBe('World Cooperage');
    });

    // Grab Tenant A's barrel ID
    $alphaBarrelId = null;
    $tenantA->run(function () use (&$alphaBarrelId) {
        $alphaBarrelId = Barrel::first()->id;
    });

    // Tenant B cannot find Tenant A's barrel by ID
    $tenantB->run(function () use ($alphaBarrelId) {
        expect(Barrel::find($alphaBarrelId))->toBeNull();
    });
});
