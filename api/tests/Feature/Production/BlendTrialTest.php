<?php

declare(strict_types=1);

use App\Models\BlendTrial;
use App\Models\Event;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createBlendTestTenant(string $slug = 'blend-winery', string $role = 'winemaker'): array
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
 * Helper: create source lots for blending.
 *
 * @return array{lot_cs_id: string, lot_merlot_id: string, lot_pv_id: string}
 */
function createBlendSourceLots(Tenant $tenant): array
{
    $ids = [];
    $tenant->run(function () use (&$ids) {
        $cs = Lot::create([
            'name' => '2024 Cabernet Sauvignon Block A',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
        $merlot = Lot::create([
            'name' => '2024 Merlot Block B',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 300,
            'status' => 'in_progress',
        ]);
        $pv = Lot::create([
            'name' => '2024 Petit Verdot Block C',
            'variety' => 'Petit Verdot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 100,
            'status' => 'in_progress',
        ]);
        $ids = [
            'lot_cs_id' => $cs->id,
            'lot_merlot_id' => $merlot->id,
            'lot_pv_id' => $pv->id,
        ];
    });

    return $ids;
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

// ─── Tier 1: Variety Composition & TTB ───────────────────────────

it('calculates variety composition and TTB label variety', function () {
    [$tenant, $token] = createBlendTestTenant();
    $lots = createBlendSourceLots($tenant);

    // 80% CS, 15% Merlot, 5% PV → TTB label = Cabernet Sauvignon (>=75%)
    $response = test()->postJson('/api/v1/blend-trials', [
        'name' => '2024 Reserve Blend Trial #1',
        'components' => [
            ['source_lot_id' => $lots['lot_cs_id'], 'percentage' => 80, 'volume_gallons' => 400],
            ['source_lot_id' => $lots['lot_merlot_id'], 'percentage' => 15, 'volume_gallons' => 75],
            ['source_lot_id' => $lots['lot_pv_id'], 'percentage' => 5, 'volume_gallons' => 25],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect((float) $data['variety_composition']['Cabernet Sauvignon'])->toBe(80.0);
    expect((float) $data['variety_composition']['Merlot'])->toBe(15.0);
    expect((float) $data['variety_composition']['Petit Verdot'])->toBe(5.0);
    expect($data['ttb_label_variety'])->toBe('Cabernet Sauvignon');
    expect((float) $data['total_volume_gallons'])->toBe(500.0);
});

it('sets ttb_label_variety to null when no variety reaches 75%', function () {
    [$tenant, $token] = createBlendTestTenant('blend-no-ttb');
    $lots = createBlendSourceLots($tenant);

    // 60% CS, 40% Merlot → no single variety >=75%, TTB label = null
    $response = test()->postJson('/api/v1/blend-trials', [
        'name' => '2024 Field Blend Trial',
        'components' => [
            ['source_lot_id' => $lots['lot_cs_id'], 'percentage' => 60, 'volume_gallons' => 300],
            ['source_lot_id' => $lots['lot_merlot_id'], 'percentage' => 40, 'volume_gallons' => 200],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    expect($response->json('data.ttb_label_variety'))->toBeNull();
    expect($response->json('data.variety_composition'))->toHaveCount(2);
});

// ─── Tier 1: Finalization ────────────────────────────────────────

it('finalizes a blend trial creating a new lot and deducting source volumes', function () {
    [$tenant, $token] = createBlendTestTenant('blend-finalize');
    $lots = createBlendSourceLots($tenant);

    // Create the trial
    $createResponse = test()->postJson('/api/v1/blend-trials', [
        'name' => '2024 Reserve Blend',
        'components' => [
            ['source_lot_id' => $lots['lot_cs_id'], 'percentage' => 80, 'volume_gallons' => 200],
            ['source_lot_id' => $lots['lot_merlot_id'], 'percentage' => 20, 'volume_gallons' => 50],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $trialId = $createResponse->json('data.id');

    // Finalize it
    $finalizeResponse = test()->postJson("/api/v1/blend-trials/{$trialId}/finalize", [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $finalizeResponse->assertOk();

    $data = $finalizeResponse->json('data');
    expect($data['status'])->toBe('finalized');
    expect($data['resulting_lot_id'])->not->toBeNull();
    expect($data['finalized_at'])->not->toBeNull();

    // Verify source lots had volumes deducted
    $tenant->run(function () use ($lots) {
        $cs = Lot::find($lots['lot_cs_id']);
        $merlot = Lot::find($lots['lot_merlot_id']);

        // CS: 500 - 200 = 300
        expect((float) $cs->volume_gallons)->toBe(300.0);
        // Merlot: 300 - 50 = 250
        expect((float) $merlot->volume_gallons)->toBe(250.0);
    });
});

it('writes blend_finalized and volume_deducted events on finalization', function () {
    [$tenant, $token] = createBlendTestTenant('blend-events');
    $lots = createBlendSourceLots($tenant);

    $createResponse = test()->postJson('/api/v1/blend-trials', [
        'name' => '2024 Estate Blend',
        'components' => [
            ['source_lot_id' => $lots['lot_cs_id'], 'percentage' => 75, 'volume_gallons' => 150],
            ['source_lot_id' => $lots['lot_merlot_id'], 'percentage' => 25, 'volume_gallons' => 50],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $trialId = $createResponse->json('data.id');

    test()->postJson("/api/v1/blend-trials/{$trialId}/finalize", [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();

    $tenant->run(function () use ($lots) {
        // blend_finalized event on the new blended lot
        $blendEvent = Event::where('operation_type', 'blend_finalized')->first();
        expect($blendEvent)->not->toBeNull();
        expect($blendEvent->payload['components'])->toHaveCount(2);
        expect($blendEvent->payload['ttb_label_variety'])->toBe('Cabernet Sauvignon');
        expect((float) $blendEvent->payload['total_volume_gallons'])->toBe(200.0);

        // volume_adjusted events on each source lot (via LotService::adjustVolume)
        $deductEvents = Event::where('operation_type', 'volume_adjusted')
            ->where('entity_type', 'lot')
            ->get();
        expect($deductEvents)->toHaveCount(2);

        $csDeduct = $deductEvents->firstWhere('entity_id', $lots['lot_cs_id']);
        expect($csDeduct)->not->toBeNull();
        expect((float) $csDeduct->payload['delta_gallons'])->toBe(-150.0);
        expect((float) $csDeduct->payload['old_volume_gallons'])->toBe(500.0);
        expect((float) $csDeduct->payload['new_volume_gallons'])->toBe(350.0);
        expect($csDeduct->payload['reason'])->toBe('blend_finalization');

        $merlotDeduct = $deductEvents->firstWhere('entity_id', $lots['lot_merlot_id']);
        expect($merlotDeduct)->not->toBeNull();
        expect((float) $merlotDeduct->payload['delta_gallons'])->toBe(-50.0);
    });
});

it('rejects finalization of already finalized trial', function () {
    [$tenant, $token] = createBlendTestTenant('blend-double-final');
    $lots = createBlendSourceLots($tenant);

    $createResponse = test()->postJson('/api/v1/blend-trials', [
        'name' => 'Double Finalize Test',
        'components' => [
            ['source_lot_id' => $lots['lot_cs_id'], 'percentage' => 60, 'volume_gallons' => 100],
            ['source_lot_id' => $lots['lot_merlot_id'], 'percentage' => 40, 'volume_gallons' => 67],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $trialId = $createResponse->json('data.id');
    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // First finalize succeeds
    test()->postJson("/api/v1/blend-trials/{$trialId}/finalize", [], $headers)->assertOk();

    // Second finalize fails
    test()->postJson("/api/v1/blend-trials/{$trialId}/finalize", [], $headers)->assertStatus(422);
});

it('rejects finalization when source lot has insufficient volume', function () {
    [$tenant, $token] = createBlendTestTenant('blend-insuf-vol');

    $lotId = null;
    $lotId2 = null;
    $tenant->run(function () use (&$lotId, &$lotId2) {
        $lot = Lot::create([
            'name' => 'Small Lot',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 50, // Only 50 gallons
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;

        $lot2 = Lot::create([
            'name' => 'Other Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 200,
            'status' => 'in_progress',
        ]);
        $lotId2 = $lot2->id;
    });

    $createResponse = test()->postJson('/api/v1/blend-trials', [
        'name' => 'Overdraw Blend',
        'components' => [
            ['source_lot_id' => $lotId, 'percentage' => 80, 'volume_gallons' => 200], // Wants 200, only has 50
            ['source_lot_id' => $lotId2, 'percentage' => 20, 'volume_gallons' => 50],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $trialId = $createResponse->json('data.id');

    $response = test()->postJson("/api/v1/blend-trials/{$trialId}/finalize", [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

it('prevents cross-tenant blend trial data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Winery Alpha',
        'slug' => 'blend-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Winery Beta',
        'slug' => 'blend-iso-beta',
        'plan' => 'pro',
    ]);

    $tenantA->run(function () {
        $user = User::create([
            'name' => 'Winemaker A',
            'email' => 'wm@alpha.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);

        BlendTrial::create([
            'name' => 'Alpha Blend',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);
    });

    $tenantB->run(function () {
        expect(BlendTrial::count())->toBe(0);
    });

    $tenantA->run(function () {
        expect(BlendTrial::count())->toBe(1);
    });
});

// ─── Tier 2: CRUD ────────────────────────────────────────────────

it('creates a blend trial with components', function () {
    [$tenant, $token] = createBlendTestTenant('blend-crud');
    $lots = createBlendSourceLots($tenant);

    $response = test()->postJson('/api/v1/blend-trials', [
        'name' => '2024 Heritage Blend Trial #1',
        'notes' => 'First attempt at heritage blend',
        'components' => [
            ['source_lot_id' => $lots['lot_cs_id'], 'percentage' => 70, 'volume_gallons' => 350],
            ['source_lot_id' => $lots['lot_merlot_id'], 'percentage' => 30, 'volume_gallons' => 150],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['name'])->toBe('2024 Heritage Blend Trial #1');
    expect($data['status'])->toBe('draft');
    expect($data['notes'])->toBe('First attempt at heritage blend');
    expect($data['components'])->toHaveCount(2);
    expect($data['created_by'])->not->toBeNull();
});

it('lists blend trials with pagination', function () {
    [$tenant, $token] = createBlendTestTenant('blend-list');

    $tenant->run(function () {
        $userId = User::where('email', 'winemaker@example.com')->first()->id;

        for ($i = 0; $i < 3; $i++) {
            BlendTrial::create([
                'name' => "Blend Trial #{$i}",
                'status' => 'draft',
                'created_by' => $userId,
            ]);
        }
    });

    $response = test()->getJson('/api/v1/blend-trials', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('meta.total'))->toBe(3);
});

it('filters blend trials by status', function () {
    [$tenant, $token] = createBlendTestTenant('blend-filter-status');

    $tenant->run(function () {
        $userId = User::where('email', 'winemaker@example.com')->first()->id;

        BlendTrial::create(['name' => 'Draft One', 'status' => 'draft', 'created_by' => $userId]);
        BlendTrial::create(['name' => 'Archived One', 'status' => 'archived', 'created_by' => $userId]);
    });

    $response = test()->getJson('/api/v1/blend-trials?status=draft', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Draft One');
});

it('shows blend trial detail with components and source lots', function () {
    [$tenant, $token] = createBlendTestTenant('blend-show');
    $lots = createBlendSourceLots($tenant);

    $createResponse = test()->postJson('/api/v1/blend-trials', [
        'name' => 'Show Detail Trial',
        'components' => [
            ['source_lot_id' => $lots['lot_cs_id'], 'percentage' => 75, 'volume_gallons' => 375],
            ['source_lot_id' => $lots['lot_merlot_id'], 'percentage' => 25, 'volume_gallons' => 125],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $trialId = $createResponse->json('data.id');

    $response = test()->getJson("/api/v1/blend-trials/{$trialId}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();

    $data = $response->json('data');
    expect($data['id'])->toBe($trialId);
    expect($data['components'])->toHaveCount(2);
    expect($data['components'][0]['source_lot'])->not->toBeNull();
    expect($data['components'][0]['source_lot']['variety'])->not->toBeNull();
    expect($data['created_by'])->not->toBeNull();
});

// ─── Tier 2: Validation ──────────────────────────────────────────

it('rejects blend trial with missing required fields', function () {
    [$tenant, $token] = createBlendTestTenant('blend-val-req');

    $response = test()->postJson('/api/v1/blend-trials', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('name');
    expect($fields)->toContain('components');
});

it('rejects blend trial with only one component', function () {
    [$tenant, $token] = createBlendTestTenant('blend-val-min');
    $lots = createBlendSourceLots($tenant);

    $response = test()->postJson('/api/v1/blend-trials', [
        'name' => 'Single Component',
        'components' => [
            ['source_lot_id' => $lots['lot_cs_id'], 'percentage' => 100, 'volume_gallons' => 500],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('components');
});

// ─── Tier 2: RBAC ────────────────────────────────────────────────

it('winemaker can create and finalize blend trials', function () {
    [$tenant, $token] = createBlendTestTenant('blend-rbac-wm');
    $lots = createBlendSourceLots($tenant);

    $response = test()->postJson('/api/v1/blend-trials', [
        'name' => 'WM Blend',
        'components' => [
            ['source_lot_id' => $lots['lot_cs_id'], 'percentage' => 60, 'volume_gallons' => 100],
            ['source_lot_id' => $lots['lot_merlot_id'], 'percentage' => 40, 'volume_gallons' => 67],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
});

it('cellar_hand cannot create blend trials', function () {
    [$tenant, $token] = createBlendTestTenant('blend-rbac-ch', 'cellar_hand');
    $lots = createBlendSourceLots($tenant);

    test()->postJson('/api/v1/blend-trials', [
        'name' => 'CH Blend',
        'components' => [
            ['source_lot_id' => $lots['lot_cs_id'], 'percentage' => 60, 'volume_gallons' => 100],
            ['source_lot_id' => $lots['lot_merlot_id'], 'percentage' => 40, 'volume_gallons' => 67],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('read-only users can list and view blend trials', function () {
    [$tenant, $token] = createBlendTestTenant('blend-rbac-ro', 'read_only');

    $trialId = null;
    $tenant->run(function () use (&$trialId) {
        $userId = User::where('email', 'read_only@example.com')->first()->id;

        $trial = BlendTrial::create([
            'name' => 'RO View Trial',
            'status' => 'draft',
            'created_by' => $userId,
        ]);
        $trialId = $trial->id;
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    test()->getJson('/api/v1/blend-trials', $headers)->assertOk();
    test()->getJson("/api/v1/blend-trials/{$trialId}", $headers)->assertOk();
});

// ─── Tier 2: API Envelope & Auth ─────────────────────────────────

it('returns blend trial responses in the standard API envelope format', function () {
    [$tenant, $token] = createBlendTestTenant('blend-env');
    $lots = createBlendSourceLots($tenant);

    $response = test()->postJson('/api/v1/blend-trials', [
        'name' => 'Envelope Test Blend',
        'components' => [
            ['source_lot_id' => $lots['lot_cs_id'], 'percentage' => 50, 'volume_gallons' => 100],
            ['source_lot_id' => $lots['lot_merlot_id'], 'percentage' => 50, 'volume_gallons' => 100],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'data' => ['id', 'name', 'status', 'variety_composition', 'ttb_label_variety', 'total_volume_gallons', 'components'],
        'meta',
        'errors',
    ]);
    expect($response->json('errors'))->toBe([]);
});

it('rejects unauthenticated access to blend trials', function () {
    test()->getJson('/api/v1/blend-trials', [
        'X-Tenant-ID' => 'fake-tenant',
    ])->assertStatus(401);
});
