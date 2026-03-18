<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createSplitTestTenant(string $slug = 'split-winery', string $role = 'winemaker'): array
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
 * Helper: create a parent lot with a given volume.
 */
function createParentLot(Tenant $tenant, float $volume = 1000.0): string
{
    $lotId = '';
    $tenant->run(function () use ($volume, &$lotId) {
        $lot = Lot::create([
            'name' => '2024 Cabernet Sauvignon Barrel Reserve',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'source_details' => ['vineyard' => 'Block A', 'clone' => '337'],
            'volume_gallons' => $volume,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    return $lotId;
}

// ─── Tier 1: Core Logic ─────────────────────────────────────────

it('splits a lot into child lots and deducts parent volume', function () {
    [$tenant, $token] = createSplitTestTenant();
    $parentLotId = createParentLot($tenant, 1000.0);

    $response = test()->postJson('/api/v1/lots/split', [
        'lot_id' => $parentLotId,
        'children' => [
            ['name' => '2024 CS — Barrel A', 'volume_gallons' => 400],
            ['name' => '2024 CS — Barrel B', 'volume_gallons' => 350],
            ['name' => '2024 CS — Experimental', 'volume_gallons' => 250],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');

    // Parent volume should decrease by total child volume (1000 - 1000 = 0)
    expect((float) $data['parent']['volume_gallons'])->toBe(0.0);

    // 3 child lots created
    expect($data['children'])->toHaveCount(3);
    expect($data['children'][0]['name'])->toBe('2024 CS — Barrel A');
    expect((float) $data['children'][0]['volume_gallons'])->toBe(400.0);
    expect($data['children'][1]['name'])->toBe('2024 CS — Barrel B');
    expect((float) $data['children'][1]['volume_gallons'])->toBe(350.0);
    expect($data['children'][2]['name'])->toBe('2024 CS — Experimental');
    expect((float) $data['children'][2]['volume_gallons'])->toBe(250.0);
});

it('child lots inherit parent variety, vintage, and source details', function () {
    [$tenant, $token] = createSplitTestTenant();
    $parentLotId = createParentLot($tenant, 500.0);

    $response = test()->postJson('/api/v1/lots/split', [
        'lot_id' => $parentLotId,
        'children' => [
            ['name' => 'Child A', 'volume_gallons' => 250],
            ['name' => 'Child B', 'volume_gallons' => 250],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $children = $response->json('data.children');
    foreach ($children as $child) {
        expect($child['variety'])->toBe('Cabernet Sauvignon');
        expect($child['vintage'])->toBe(2024);
        expect($child['source_type'])->toBe('estate');
        expect($child['parent_lot_id'])->toBe($parentLotId);
    }
});

it('writes lot_split event on parent with child lot references', function () {
    [$tenant, $token] = createSplitTestTenant();
    $parentLotId = createParentLot($tenant, 600.0);

    test()->postJson('/api/v1/lots/split', [
        'lot_id' => $parentLotId,
        'children' => [
            ['name' => 'Split A', 'volume_gallons' => 300],
            ['name' => 'Split B', 'volume_gallons' => 200],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    // Check lot_split event on parent
    $tenant->run(function () use ($parentLotId) {
        $event = Event::where('entity_type', 'lot')
            ->where('entity_id', $parentLotId)
            ->where('operation_type', 'lot_split')
            ->first();

        expect($event)->not->toBeNull();
        expect((float) $event->payload['old_volume_gallons'])->toBe(600.0);
        expect((float) $event->payload['new_volume_gallons'])->toBe(100.0);
        expect((float) $event->payload['total_split_volume_gallons'])->toBe(500.0);
        expect($event->payload['child_count'])->toBe(2);
        expect($event->payload['children'])->toHaveCount(2);
        expect((float) $event->payload['children'][0]['volume_gallons'])->toBe(300.0);
        expect((float) $event->payload['children'][1]['volume_gallons'])->toBe(200.0);
    });
});

it('writes lot_created events on each child lot', function () {
    [$tenant, $token] = createSplitTestTenant();
    $parentLotId = createParentLot($tenant, 400.0);

    test()->postJson('/api/v1/lots/split', [
        'lot_id' => $parentLotId,
        'children' => [
            ['name' => 'Child X', 'volume_gallons' => 200],
            ['name' => 'Child Y', 'volume_gallons' => 200],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $tenant->run(function () use ($parentLotId) {
        $childLots = Lot::where('parent_lot_id', $parentLotId)->get();
        expect($childLots)->toHaveCount(2);

        foreach ($childLots as $childLot) {
            $event = Event::where('entity_type', 'lot')
                ->where('entity_id', $childLot->id)
                ->where('operation_type', 'lot_created')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->payload['parent_lot_id'])->toBe($parentLotId);
            expect($event->payload['split_volume_ratio'])->toBeNumeric();
        }
    });
});

it('allows partial split leaving remaining volume on parent', function () {
    [$tenant, $token] = createSplitTestTenant();
    $parentLotId = createParentLot($tenant, 1000.0);

    $response = test()->postJson('/api/v1/lots/split', [
        'lot_id' => $parentLotId,
        'children' => [
            ['name' => 'Small Split A', 'volume_gallons' => 100],
            ['name' => 'Small Split B', 'volume_gallons' => 150],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    // Parent retains 750 gallons
    expect((float) $response->json('data.parent.volume_gallons'))->toBe(750.0);
});

it('rejects split when total child volume exceeds parent volume', function () {
    [$tenant, $token] = createSplitTestTenant();
    $parentLotId = createParentLot($tenant, 500.0);

    $response = test()->postJson('/api/v1/lots/split', [
        'lot_id' => $parentLotId,
        'children' => [
            ['name' => 'Over A', 'volume_gallons' => 300],
            ['name' => 'Over B', 'volume_gallons' => 300],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

it('prevents cross-tenant lot split data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Split Alpha',
        'slug' => 'split-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Split Beta',
        'slug' => 'split-iso-beta',
        'plan' => 'pro',
    ]);

    // Create a parent lot and child lots in tenant A
    $tenantA->run(function () {
        $parent = Lot::create([
            'name' => 'Alpha Parent',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        Lot::create([
            'name' => 'Alpha Child 1',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 250,
            'status' => 'in_progress',
            'parent_lot_id' => $parent->id,
        ]);

        Lot::create([
            'name' => 'Alpha Child 2',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 250,
            'status' => 'in_progress',
            'parent_lot_id' => $parent->id,
        ]);
    });

    // Tenant B should not see any lots
    $tenantB->run(function () {
        expect(Lot::count())->toBe(0);
    });

    // Tenant A should see all 3 lots
    $tenantA->run(function () {
        expect(Lot::count())->toBe(3);
    });
});

// ─── Tier 2: Validation ─────────────────────────────────────────

it('rejects split with missing required fields', function () {
    [$tenant, $token] = createSplitTestTenant();

    $response = test()->postJson('/api/v1/lots/split', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('lot_id');
    expect($fields)->toContain('children');
});

it('rejects split with only one child lot', function () {
    [$tenant, $token] = createSplitTestTenant();
    $parentLotId = createParentLot($tenant, 500.0);

    $response = test()->postJson('/api/v1/lots/split', [
        'lot_id' => $parentLotId,
        'children' => [
            ['name' => 'Only Child', 'volume_gallons' => 250],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('children');
});

it('rejects split with missing child name', function () {
    [$tenant, $token] = createSplitTestTenant();
    $parentLotId = createParentLot($tenant, 500.0);

    $response = test()->postJson('/api/v1/lots/split', [
        'lot_id' => $parentLotId,
        'children' => [
            ['volume_gallons' => 200],
            ['name' => 'Child B', 'volume_gallons' => 200],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('children.0.name');
});

it('rejects split with invalid lot_id', function () {
    [$tenant, $token] = createSplitTestTenant();

    $response = test()->postJson('/api/v1/lots/split', [
        'lot_id' => '00000000-0000-0000-0000-000000000000',
        'children' => [
            ['name' => 'A', 'volume_gallons' => 100],
            ['name' => 'B', 'volume_gallons' => 100],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('lot_id');
});

// ─── Tier 2: RBAC ───────────────────────────────────────────────

it('winemaker can split lots', function () {
    [$tenant, $token] = createSplitTestTenant('rbac-wm', 'winemaker');
    $parentLotId = createParentLot($tenant, 500.0);

    $response = test()->postJson('/api/v1/lots/split', [
        'lot_id' => $parentLotId,
        'children' => [
            ['name' => 'WM Split A', 'volume_gallons' => 250],
            ['name' => 'WM Split B', 'volume_gallons' => 250],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
});

it('cellar_hand cannot split lots', function () {
    [$tenant, $token] = createSplitTestTenant('rbac-ch', 'cellar_hand');
    $parentLotId = createParentLot($tenant, 500.0);

    $response = test()->postJson('/api/v1/lots/split', [
        'lot_id' => $parentLotId,
        'children' => [
            ['name' => 'CH Split A', 'volume_gallons' => 250],
            ['name' => 'CH Split B', 'volume_gallons' => 250],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(403);
});

it('read-only users cannot split lots', function () {
    [$tenant, $token] = createSplitTestTenant('rbac-ro', 'read_only');
    $parentLotId = createParentLot($tenant, 500.0);

    $response = test()->postJson('/api/v1/lots/split', [
        'lot_id' => $parentLotId,
        'children' => [
            ['name' => 'RO Split A', 'volume_gallons' => 250],
            ['name' => 'RO Split B', 'volume_gallons' => 250],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(403);
});

// ─── Tier 2: API Format ─────────────────────────────────────────

it('returns response in the standard API envelope format', function () {
    [$tenant, $token] = createSplitTestTenant();
    $parentLotId = createParentLot($tenant, 800.0);

    $response = test()->postJson('/api/v1/lots/split', [
        'lot_id' => $parentLotId,
        'children' => [
            ['name' => 'Envelope A', 'volume_gallons' => 400],
            ['name' => 'Envelope B', 'volume_gallons' => 400],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'parent' => ['id', 'name', 'variety', 'vintage', 'volume_gallons'],
                'children',
            ],
            'meta',
            'errors',
        ]);
});

it('rejects unauthenticated access to lot split', function () {
    $response = test()->postJson('/api/v1/lots/split', [
        'lot_id' => '00000000-0000-0000-0000-000000000000',
        'children' => [
            ['name' => 'A', 'volume_gallons' => 100],
            ['name' => 'B', 'volume_gallons' => 100],
        ],
    ]);

    $response->assertStatus(401);
});
