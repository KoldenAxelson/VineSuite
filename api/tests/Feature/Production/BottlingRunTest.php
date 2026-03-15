<?php

declare(strict_types=1);

use App\Models\BottlingRun;
use App\Models\Event;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createBottlingTestTenant(string $slug = 'bottling-winery', string $role = 'winemaker'): array
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

/**
 * Helper: create a lot for bottling tests.
 */
function createBottlingLot(Tenant $tenant, float $volume = 500.0): string
{
    $lotId = '';
    $tenant->run(function () use ($volume, &$lotId) {
        $lot = Lot::create([
            'name' => '2024 Cabernet Sauvignon Estate',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => $volume,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    return $lotId;
}

// ─── Tier 1: Core Logic ─────────────────────────────────────────

it('creates a bottling run with components', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 500.0);

    $response = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 600,
        'bottles_breakage' => 3,
        'waste_percent' => 1.5,
        'volume_bottled_gallons' => 120.0,
        'bottles_per_case' => 12,
        'bottled_at' => '2026-03-15',
        'notes' => 'First bottling run of 2024 vintage',
        'components' => [
            [
                'component_type' => 'bottle',
                'product_name' => '750ml Bordeaux Green',
                'quantity_used' => 603,
                'quantity_wasted' => 3,
            ],
            [
                'component_type' => 'cork',
                'product_name' => 'Natural Cork #9',
                'quantity_used' => 600,
            ],
            [
                'component_type' => 'capsule',
                'product_name' => 'Tin Capsule Black',
                'quantity_used' => 600,
            ],
            [
                'component_type' => 'label',
                'product_name' => 'Front Label 2024 CS',
                'quantity_used' => 600,
            ],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['status'])->toBe('planned');
    expect($data['bottles_filled'])->toBe(600);
    expect($data['cases_produced'])->toBe(50); // 600 / 12
    expect($data['bottle_format'])->toBe('750ml');
    expect($data['components'])->toHaveCount(4);
});

it('completes a bottling run deducting lot volume', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 500.0);

    // Create the run
    $createResponse = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 600,
        'volume_bottled_gallons' => 120.0,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $runId = $createResponse->json('data.id');

    // Complete the run
    $completeResponse = test()->postJson("/api/v1/bottling-runs/{$runId}/complete", [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $completeResponse->assertStatus(200);

    $data = $completeResponse->json('data');
    expect($data['status'])->toBe('completed');
    expect($data['sku'])->not->toBeNull();
    expect($data['cases_produced'])->toBe(50);
    expect($data['completed_at'])->not->toBeNull();

    // Verify lot volume was deducted
    expect((float) $data['lot']['volume_gallons'])->toBe(380.0); // 500 - 120
});

it('writes bottling_completed event with full details', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 300.0);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $createResponse = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 480,
        'volume_bottled_gallons' => 96.0,
        'components' => [
            ['component_type' => 'bottle', 'product_name' => '750ml Bordeaux', 'quantity_used' => 480],
        ],
    ], $headers);

    $runId = $createResponse->json('data.id');
    test()->postJson("/api/v1/bottling-runs/{$runId}/complete", [], $headers);

    $tenant->run(function () use ($lotId) {
        $event = Event::where('entity_type', 'lot')
            ->where('entity_id', $lotId)
            ->where('operation_type', 'bottling_completed')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['bottle_format'])->toBe('750ml');
        expect($event->payload['bottles_filled'])->toBe(480);
        expect((float) $event->payload['volume_bottled_gallons'])->toBe(96.0);
        expect($event->payload['cases_produced'])->toBe(40); // 480 / 12
        expect($event->payload['sku'])->not->toBeNull();
        expect($event->payload['components'])->toHaveCount(1);
        expect((float) $event->payload['old_lot_volume_gallons'])->toBe(300.0);
        expect((float) $event->payload['new_lot_volume_gallons'])->toBe(204.0);
    });
});

it('auto-sets lot status to bottled when volume reaches zero', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 100.0);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $createResponse = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 500,
        'volume_bottled_gallons' => 100.0, // exact lot volume
    ], $headers);

    $runId = $createResponse->json('data.id');
    $completeResponse = test()->postJson("/api/v1/bottling-runs/{$runId}/complete", [], $headers);

    $completeResponse->assertStatus(200);

    // Lot should now be 'bottled'
    $tenant->run(function () use ($lotId) {
        $lot = Lot::find($lotId);
        expect($lot->status)->toBe('bottled');
        expect((float) $lot->volume_gallons)->toBe(0.0);

        // Should have a lot_status_changed event
        $statusEvent = Event::where('entity_type', 'lot')
            ->where('entity_id', $lotId)
            ->where('operation_type', 'lot_status_changed')
            ->first();

        expect($statusEvent)->not->toBeNull();
        expect($statusEvent->payload['new_status'])->toBe('bottled');
        expect($statusEvent->payload['reason'])->toBe('bottling_completed');
    });
});

it('rejects completion when lot has insufficient volume', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 50.0);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $createResponse = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 600,
        'volume_bottled_gallons' => 120.0, // more than the 50 gal lot
    ], $headers);

    $runId = $createResponse->json('data.id');
    $completeResponse = test()->postJson("/api/v1/bottling-runs/{$runId}/complete", [], $headers);

    $completeResponse->assertStatus(422);
});

it('rejects double completion', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 500.0);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $createResponse = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 300,
        'volume_bottled_gallons' => 60.0,
    ], $headers);

    $runId = $createResponse->json('data.id');

    // First completion succeeds
    test()->postJson("/api/v1/bottling-runs/{$runId}/complete", [], $headers)->assertStatus(200);

    // Second completion fails
    test()->postJson("/api/v1/bottling-runs/{$runId}/complete", [], $headers)->assertStatus(422);
});

it('auto-generates SKU when not provided', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 500.0);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $createResponse = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 300,
        'volume_bottled_gallons' => 60.0,
        // No SKU provided
    ], $headers);

    $runId = $createResponse->json('data.id');
    $completeResponse = test()->postJson("/api/v1/bottling-runs/{$runId}/complete", [], $headers);

    $data = $completeResponse->json('data');
    expect($data['sku'])->not->toBeNull();
    expect($data['sku'])->toContain('CABE'); // First 4 chars of "Cabernet Sauvignon"
    expect($data['sku'])->toContain('2024');
    expect($data['sku'])->toContain('750ml');
});

it('uses custom SKU when provided', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 500.0);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $createResponse = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 300,
        'volume_bottled_gallons' => 60.0,
        'sku' => 'CS-2024-RESERVE-001',
    ], $headers);

    $runId = $createResponse->json('data.id');
    $completeResponse = test()->postJson("/api/v1/bottling-runs/{$runId}/complete", [], $headers);

    expect($completeResponse->json('data.sku'))->toBe('CS-2024-RESERVE-001');
});

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

it('prevents cross-tenant bottling run data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Bottling Alpha',
        'slug' => 'bottling-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Bottling Beta',
        'slug' => 'bottling-iso-beta',
        'plan' => 'pro',
    ]);

    $tenantA->run(function () {
        $lot = Lot::create([
            'name' => 'Alpha Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        BottlingRun::create([
            'lot_id' => $lot->id,
            'bottle_format' => '750ml',
            'bottles_filled' => 300,
            'volume_bottled_gallons' => 60,
            'status' => 'planned',
        ]);
    });

    $tenantB->run(function () {
        expect(BottlingRun::count())->toBe(0);
    });

    $tenantA->run(function () {
        expect(BottlingRun::count())->toBe(1);
    });
});

// ─── Tier 2: CRUD ────────────────────────────────────────────────

it('lists bottling runs with pagination', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 1000.0);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Create 2 runs
    test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 300,
        'volume_bottled_gallons' => 60.0,
    ], $headers);

    test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '375ml',
        'bottles_filled' => 200,
        'volume_bottled_gallons' => 20.0,
    ], $headers);

    $response = test()->getJson('/api/v1/bottling-runs', $headers);

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(2);
});

it('filters bottling runs by status', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 1000.0);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Create and complete one run
    $r1 = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 300,
        'volume_bottled_gallons' => 60.0,
    ], $headers);
    $runId = $r1->json('data.id');
    test()->postJson("/api/v1/bottling-runs/{$runId}/complete", [], $headers);

    // Create another run (planned)
    test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 200,
        'volume_bottled_gallons' => 40.0,
    ], $headers);

    // Filter by completed
    $completedResponse = test()->getJson('/api/v1/bottling-runs?status=completed', $headers);
    expect($completedResponse->json('data'))->toHaveCount(1);

    // Filter by planned
    $plannedResponse = test()->getJson('/api/v1/bottling-runs?status=planned', $headers);
    expect($plannedResponse->json('data'))->toHaveCount(1);
});

it('shows bottling run detail with components', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 500.0);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $createResponse = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 600,
        'volume_bottled_gallons' => 120.0,
        'components' => [
            ['component_type' => 'bottle', 'product_name' => '750ml Green', 'quantity_used' => 600],
            ['component_type' => 'cork', 'product_name' => 'Cork #9', 'quantity_used' => 600],
        ],
    ], $headers);

    $runId = $createResponse->json('data.id');

    $showResponse = test()->getJson("/api/v1/bottling-runs/{$runId}", $headers);

    $showResponse->assertStatus(200);
    $data = $showResponse->json('data');
    expect($data['lot'])->not->toBeNull();
    expect($data['components'])->toHaveCount(2);
});

// ─── Tier 2: Validation ─────────────────────────────────────────

it('rejects bottling run with missing required fields', function () {
    [$tenant, $token] = createBottlingTestTenant();

    $response = test()->postJson('/api/v1/bottling-runs', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('lot_id');
    expect($fields)->toContain('bottle_format');
    expect($fields)->toContain('bottles_filled');
    expect($fields)->toContain('volume_bottled_gallons');
});

it('rejects bottling run with invalid bottle format', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 500.0);

    $response = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '999ml', // not a valid format
        'bottles_filled' => 300,
        'volume_bottled_gallons' => 60.0,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('bottle_format');
});

// ─── Tier 2: RBAC ───────────────────────────────────────────────

it('winemaker can create and complete bottling runs', function () {
    [$tenant, $token] = createBottlingTestTenant('bottling-wm', 'winemaker');
    $lotId = createBottlingLot($tenant, 500.0);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $response = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 300,
        'volume_bottled_gallons' => 60.0,
    ], $headers);

    $response->assertStatus(201);

    $runId = $response->json('data.id');
    test()->postJson("/api/v1/bottling-runs/{$runId}/complete", [], $headers)->assertStatus(200);
});

it('cellar_hand cannot create bottling runs', function () {
    [$tenant, $token] = createBottlingTestTenant('bottling-ch', 'cellar_hand');
    $lotId = createBottlingLot($tenant, 500.0);

    $response = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 300,
        'volume_bottled_gallons' => 60.0,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(403);
});

it('read-only users can list and view bottling runs', function () {
    [$tenant, $token] = createBottlingTestTenant('bottling-ro', 'read_only');

    $response = test()->getJson('/api/v1/bottling-runs', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(200);
});

// ─── Tier 2: API Format ─────────────────────────────────────────

it('returns bottling run responses in the standard API envelope format', function () {
    [$tenant, $token] = createBottlingTestTenant();
    $lotId = createBottlingLot($tenant, 500.0);

    $response = test()->postJson('/api/v1/bottling-runs', [
        'lot_id' => $lotId,
        'bottle_format' => '750ml',
        'bottles_filled' => 300,
        'volume_bottled_gallons' => 60.0,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'id',
                'lot_id',
                'bottle_format',
                'bottles_filled',
                'status',
                'cases_produced',
            ],
        ]);
});

it('rejects unauthenticated access to bottling runs', function () {
    $response = test()->getJson('/api/v1/bottling-runs');
    $response->assertStatus(401);
});
