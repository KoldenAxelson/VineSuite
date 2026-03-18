<?php

declare(strict_types=1);

use App\Models\Barrel;
use App\Models\Event;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createBarrelOpTestTenant(string $slug = 'barrel-op-winery', string $role = 'cellar_hand'): array
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
 * Helper: create a lot, a source tank with volume, and barrels for testing.
 *
 * @return array{lot_id: string, tank_vessel_id: string, barrel_ids: array<int, string>}
 */
function createBarrelOpFixtures(Tenant $tenant, int $barrelCount = 3, float $tankVolume = 500.0): array
{
    $result = ['lot_id' => '', 'tank_vessel_id' => '', 'barrel_ids' => []];

    $tenant->run(function () use (&$result, $barrelCount, $tankVolume) {
        $lot = Lot::create([
            'name' => '2024 Pinot Noir Barrel Aged',
            'variety' => 'Pinot Noir',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 1000,
            'status' => 'in_progress',
        ]);
        $result['lot_id'] = $lot->id;

        // Source tank with wine
        $tank = Vessel::create([
            'name' => 'T-01',
            'type' => 'tank',
            'capacity_gallons' => 1000,
            'material' => 'Stainless steel',
            'location' => 'Cellar A',
            'status' => 'in_use',
        ]);
        $result['tank_vessel_id'] = $tank->id;

        // Put wine in the tank
        DB::table('lot_vessel')->insert([
            'id' => (string) Str::uuid(),
            'lot_id' => $lot->id,
            'vessel_id' => $tank->id,
            'volume_gallons' => $tankVolume,
            'filled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create barrels
        $barrelIds = [];
        for ($i = 1; $i <= $barrelCount; $i++) {
            $vessel = Vessel::create([
                'name' => 'B-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'type' => 'barrel',
                'capacity_gallons' => 60,
                'material' => 'French oak',
                'location' => 'Barrel Room',
                'status' => 'empty',
            ]);

            $barrel = Barrel::create([
                'vessel_id' => $vessel->id,
                'cooperage' => 'François Frères',
                'toast_level' => 'medium',
                'oak_type' => 'french',
                'forest_origin' => 'Allier',
                'volume_gallons' => 60,
                'years_used' => 1,
            ]);

            $barrelIds[] = $barrel->id;
        }
        $result['barrel_ids'] = $barrelIds;
    });

    return $result;
}

// ─── Tier 1: Fill Operations ────────────────────────────────────

it('fills barrels from a lot and writes barrel_filled events', function () {
    [$tenant, $token] = createBarrelOpTestTenant();
    $fixtures = createBarrelOpFixtures($tenant, 3);

    $response = test()->postJson('/api/v1/barrel-operations/fill', [
        'lot_id' => $fixtures['lot_id'],
        'barrels' => [
            ['barrel_id' => $fixtures['barrel_ids'][0], 'volume_gallons' => 55],
            ['barrel_id' => $fixtures['barrel_ids'][1], 'volume_gallons' => 58],
            ['barrel_id' => $fixtures['barrel_ids'][2], 'volume_gallons' => 56],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['operation'])->toBe('fill');
    expect($data['barrels_filled'])->toBe(3);
    expect($data['results'])->toHaveCount(3);

    // Verify barrel_filled events
    $tenant->run(function () use ($fixtures) {
        $events = Event::where('entity_type', 'lot')
            ->where('entity_id', $fixtures['lot_id'])
            ->where('operation_type', 'barrel_filled')
            ->get();

        expect($events)->toHaveCount(3);
        expect((float) $events[0]->payload['volume_gallons'])->toBe(55.0);
    });
});

// ─── Tier 1: Top Operations ─────────────────────────────────────

it('tops barrels from a source vessel and deducts source volume', function () {
    [$tenant, $token] = createBarrelOpTestTenant();
    $fixtures = createBarrelOpFixtures($tenant, 2, 500.0);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // First fill the barrels
    test()->postJson('/api/v1/barrel-operations/fill', [
        'lot_id' => $fixtures['lot_id'],
        'barrels' => [
            ['barrel_id' => $fixtures['barrel_ids'][0], 'volume_gallons' => 55],
            ['barrel_id' => $fixtures['barrel_ids'][1], 'volume_gallons' => 55],
        ],
    ], $headers);

    // Now top them (small volumes from the tank)
    $response = test()->postJson('/api/v1/barrel-operations/top', [
        'source_vessel_id' => $fixtures['tank_vessel_id'],
        'lot_id' => $fixtures['lot_id'],
        'barrels' => [
            ['barrel_id' => $fixtures['barrel_ids'][0], 'volume_gallons' => 0.5],
            ['barrel_id' => $fixtures['barrel_ids'][1], 'volume_gallons' => 0.5],
        ],
    ], $headers);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['operation'])->toBe('top');
    expect($data['barrels_topped'])->toBe(2);

    // Source tank should have lost 1 gallon total
    $tenant->run(function () use ($fixtures) {
        $pivot = DB::table('lot_vessel')
            ->where('vessel_id', $fixtures['tank_vessel_id'])
            ->whereNull('emptied_at')
            ->first();

        // Started at 500, lost 1 gallon for topping
        expect((float) $pivot->volume_gallons)->toBe(499.0);

        // barrel_topped events written
        $events = Event::where('entity_type', 'lot')
            ->where('entity_id', $fixtures['lot_id'])
            ->where('operation_type', 'barrel_topped')
            ->get();

        expect($events)->toHaveCount(2);
        expect($events[0]->payload['source_vessel_id'])->toBe($fixtures['tank_vessel_id']);
    });
});

it('rejects topping when source vessel has insufficient volume', function () {
    [$tenant, $token] = createBarrelOpTestTenant();
    $fixtures = createBarrelOpFixtures($tenant, 2, 0.5); // only 0.5 gal in tank

    $response = test()->postJson('/api/v1/barrel-operations/top', [
        'source_vessel_id' => $fixtures['tank_vessel_id'],
        'lot_id' => $fixtures['lot_id'],
        'barrels' => [
            ['barrel_id' => $fixtures['barrel_ids'][0], 'volume_gallons' => 0.5],
            ['barrel_id' => $fixtures['barrel_ids'][1], 'volume_gallons' => 0.5],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

// ─── Tier 1: Rack Operations ────────────────────────────────────

it('racks barrels to a target vessel with lees weight and writes events', function () {
    [$tenant, $token] = createBarrelOpTestTenant();
    $fixtures = createBarrelOpFixtures($tenant, 2);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Fill barrels first
    test()->postJson('/api/v1/barrel-operations/fill', [
        'lot_id' => $fixtures['lot_id'],
        'barrels' => [
            ['barrel_id' => $fixtures['barrel_ids'][0], 'volume_gallons' => 55],
            ['barrel_id' => $fixtures['barrel_ids'][1], 'volume_gallons' => 55],
        ],
    ], $headers);

    // Rack barrels back to the tank
    $response = test()->postJson('/api/v1/barrel-operations/rack', [
        'target_vessel_id' => $fixtures['tank_vessel_id'],
        'lot_id' => $fixtures['lot_id'],
        'barrels' => [
            ['barrel_id' => $fixtures['barrel_ids'][0], 'volume_gallons' => 53, 'lees_weight_kg' => 0.8],
            ['barrel_id' => $fixtures['barrel_ids'][1], 'volume_gallons' => 54, 'lees_weight_kg' => 0.5],
        ],
    ], $headers);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['operation'])->toBe('rack');
    expect($data['barrels_racked'])->toBe(2);

    // Verify barrel_racked events with lees weight
    $tenant->run(function () use ($fixtures) {
        $events = Event::where('entity_type', 'lot')
            ->where('entity_id', $fixtures['lot_id'])
            ->where('operation_type', 'barrel_racked')
            ->get();

        expect($events)->toHaveCount(2);
        expect((float) $events[0]->payload['lees_weight_kg'])->toBe(0.8);
        expect((float) $events[1]->payload['lees_weight_kg'])->toBe(0.5);
    });
});

// ─── Tier 1: Sample Operations ──────────────────────────────────

it('records a barrel sample and writes barrel_sampled event', function () {
    [$tenant, $token] = createBarrelOpTestTenant();
    $fixtures = createBarrelOpFixtures($tenant, 1);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Fill barrel first
    test()->postJson('/api/v1/barrel-operations/fill', [
        'lot_id' => $fixtures['lot_id'],
        'barrels' => [
            ['barrel_id' => $fixtures['barrel_ids'][0], 'volume_gallons' => 55],
        ],
    ], $headers);

    // Take a sample
    $response = test()->postJson('/api/v1/barrel-operations/sample', [
        'barrel_id' => $fixtures['barrel_ids'][0],
        'lot_id' => $fixtures['lot_id'],
        'volume_ml' => 250,
        'notes' => 'Pre-blend evaluation sample',
    ], $headers);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['operation'])->toBe('sample');
    expect((float) $data['result']['volume_ml'])->toBe(250.0);

    // Verify barrel_sampled event
    $tenant->run(function () use ($fixtures) {
        $event = Event::where('entity_type', 'lot')
            ->where('entity_id', $fixtures['lot_id'])
            ->where('operation_type', 'barrel_sampled')
            ->first();

        expect($event)->not->toBeNull();
        expect((float) $event->payload['volume_ml'])->toBe(250.0);
        expect($event->payload['notes'])->toBe('Pre-blend evaluation sample');
    });
});

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

it('prevents cross-tenant barrel operation data access', function () {
    $tenantA = Tenant::create([
        'name' => 'BarrelOp Alpha',
        'slug' => 'barrelop-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'BarrelOp Beta',
        'slug' => 'barrelop-iso-beta',
        'plan' => 'pro',
    ]);

    $tenantA->run(function () {
        Lot::create([
            'name' => 'Alpha Lot',
            'variety' => 'Pinot Noir',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
    });

    $tenantB->run(function () {
        expect(Lot::count())->toBe(0);
        expect(Event::count())->toBe(0);
    });
});

// ─── Tier 2: Validation ─────────────────────────────────────────

it('rejects fill with missing required fields', function () {
    [$tenant, $token] = createBarrelOpTestTenant();

    $response = test()->postJson('/api/v1/barrel-operations/fill', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('lot_id');
    expect($fields)->toContain('barrels');
});

it('rejects top with invalid source vessel', function () {
    [$tenant, $token] = createBarrelOpTestTenant();

    $response = test()->postJson('/api/v1/barrel-operations/top', [
        'source_vessel_id' => '00000000-0000-0000-0000-000000000000',
        'lot_id' => '00000000-0000-0000-0000-000000000000',
        'barrels' => [
            ['barrel_id' => '00000000-0000-0000-0000-000000000000', 'volume_gallons' => 0.5],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('source_vessel_id');
});

it('rejects sample with volume exceeding max', function () {
    [$tenant, $token] = createBarrelOpTestTenant();
    $fixtures = createBarrelOpFixtures($tenant, 1);

    $response = test()->postJson('/api/v1/barrel-operations/sample', [
        'barrel_id' => $fixtures['barrel_ids'][0],
        'lot_id' => $fixtures['lot_id'],
        'volume_ml' => 6000, // max is 5000
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('volume_ml');
});

// ─── Tier 2: RBAC ───────────────────────────────────────────────

it('cellar_hand can perform barrel operations', function () {
    [$tenant, $token] = createBarrelOpTestTenant('rbac-ch-op', 'cellar_hand');
    $fixtures = createBarrelOpFixtures($tenant, 1);

    $response = test()->postJson('/api/v1/barrel-operations/fill', [
        'lot_id' => $fixtures['lot_id'],
        'barrels' => [
            ['barrel_id' => $fixtures['barrel_ids'][0], 'volume_gallons' => 55],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
});

it('read_only users cannot perform barrel operations', function () {
    [$tenant, $token] = createBarrelOpTestTenant('rbac-ro-op', 'read_only');
    $fixtures = createBarrelOpFixtures($tenant, 1);

    $response = test()->postJson('/api/v1/barrel-operations/fill', [
        'lot_id' => $fixtures['lot_id'],
        'barrels' => [
            ['barrel_id' => $fixtures['barrel_ids'][0], 'volume_gallons' => 55],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(403);
});

// ─── Tier 2: API Format ─────────────────────────────────────────

it('returns barrel operation responses in the standard API envelope format', function () {
    [$tenant, $token] = createBarrelOpTestTenant();
    $fixtures = createBarrelOpFixtures($tenant, 1);

    $response = test()->postJson('/api/v1/barrel-operations/fill', [
        'lot_id' => $fixtures['lot_id'],
        'barrels' => [
            ['barrel_id' => $fixtures['barrel_ids'][0], 'volume_gallons' => 55],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'operation',
                'lot_id',
                'barrels_filled',
                'results',
            ],
            'meta',
            'errors',
        ]);
});

it('rejects unauthenticated access to barrel operations', function () {
    $response = test()->postJson('/api/v1/barrel-operations/fill', [
        'lot_id' => '00000000-0000-0000-0000-000000000000',
        'barrels' => [],
    ]);

    $response->assertStatus(401);
});
