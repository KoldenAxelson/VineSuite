<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Lot;
use App\Models\PressLog;
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
function createPressLogTestTenant(string $slug = 'press-winery', string $role = 'winemaker'): array
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

/*
 * Helper: create a lot for pressing tests.
 */
function createPressTestLot(Tenant $tenant, string $name = 'Press Test Lot'): string
{
    $lotId = null;
    $tenant->run(function () use ($name, &$lotId) {
        $lot = Lot::create([
            'name' => $name,
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 1000,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    return $lotId;
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

it('writes a pressing_logged event when a pressing is logged', function () {
    [$tenant, $token] = createPressLogTestTenant();
    $lotId = createPressTestLot($tenant);

    $response = test()->postJson('/api/v1/press-logs', [
        'lot_id' => $lotId,
        'press_type' => 'pneumatic',
        'fruit_weight_kg' => 900,
        'total_juice_gallons' => 175,
        'fractions' => [
            ['fraction' => 'free_run', 'volume_gallons' => 115],
            ['fraction' => 'light_press', 'volume_gallons' => 45],
            ['fraction' => 'heavy_press', 'volume_gallons' => 15],
        ],
        'pomace_weight_kg' => 250,
        'pomace_destination' => 'compost',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    // Verify event was written
    $tenant->run(function () use ($lotId) {
        $event = Event::where('entity_type', 'lot')
            ->where('entity_id', $lotId)
            ->where('operation_type', 'pressing_logged')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['press_type'])->toBe('pneumatic');
        expect((float) $event->payload['fruit_weight_kg'])->toBe(900.0);
        expect((float) $event->payload['total_juice_gallons'])->toBe(175.0);
        expect($event->payload['fraction_count'])->toBe(3);
        expect((float) $event->payload['pomace_weight_kg'])->toBe(250.0);
        expect($event->payload['pomace_destination'])->toBe('compost');
    });
});

it('calculates yield percent from fruit weight and juice volume', function () {
    [$tenant, $token] = createPressLogTestTenant('press-yield');
    $lotId = createPressTestLot($tenant, 'Yield Test Lot');

    $response = test()->postJson('/api/v1/press-logs', [
        'lot_id' => $lotId,
        'press_type' => 'basket',
        'fruit_weight_kg' => 500,
        'total_juice_gallons' => 100,
        'fractions' => [
            ['fraction' => 'free_run', 'volume_gallons' => 100],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    // yield_percent = (100 / 500) * 100 = 20.0
    expect((float) $response->json('data.yield_percent'))->toBe(20.0);
});

it('creates child lots for press fractions when requested', function () {
    [$tenant, $token] = createPressLogTestTenant('press-child');
    $lotId = createPressTestLot($tenant, 'Parent Pressing Lot');

    $response = test()->postJson('/api/v1/press-logs', [
        'lot_id' => $lotId,
        'press_type' => 'pneumatic',
        'fruit_weight_kg' => 800,
        'total_juice_gallons' => 150,
        'fractions' => [
            ['fraction' => 'free_run', 'volume_gallons' => 100, 'create_child_lot' => true],
            ['fraction' => 'light_press', 'volume_gallons' => 35, 'create_child_lot' => true],
            ['fraction' => 'heavy_press', 'volume_gallons' => 15],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $fractions = $response->json('data.fractions');

    // Free run and light press should have child_lot_id set
    expect($fractions[0]['child_lot_id'])->not->toBeNull();
    expect($fractions[1]['child_lot_id'])->not->toBeNull();
    // Heavy press was not requested as child lot
    expect($fractions[2]['child_lot_id'])->toBeNull();

    // Verify child lots exist with correct data
    $tenant->run(function () use ($fractions, $lotId) {
        $freeRunLot = Lot::find($fractions[0]['child_lot_id']);
        expect($freeRunLot)->not->toBeNull();
        expect($freeRunLot->name)->toContain('Free Run');
        expect($freeRunLot->parent_lot_id)->toBe($lotId);
        expect($freeRunLot->variety)->toBe('Cabernet Sauvignon');
        expect($freeRunLot->vintage)->toBe(2024);
        expect((float) $freeRunLot->volume_gallons)->toBe(100.0);

        $lightPressLot = Lot::find($fractions[1]['child_lot_id']);
        expect($lightPressLot)->not->toBeNull();
        expect($lightPressLot->name)->toContain('Light Press');
        expect($lightPressLot->parent_lot_id)->toBe($lotId);
        expect((float) $lightPressLot->volume_gallons)->toBe(35.0);

        // Child lots should each have a lot_created event
        $childEvents = Event::where('entity_type', 'lot')
            ->where('operation_type', 'lot_created')
            ->whereIn('entity_id', [$freeRunLot->id, $lightPressLot->id])
            ->count();
        expect($childEvents)->toBe(2);
    });
});

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

it('prevents cross-tenant press log data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Winery Alpha',
        'slug' => 'press-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Winery Beta',
        'slug' => 'press-iso-beta',
        'plan' => 'pro',
    ]);

    // Create a press log in Tenant A
    $tenantA->run(function () {
        $user = User::create([
            'name' => 'Winemaker A',
            'email' => 'wm@alpha.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);

        $lot = Lot::create([
            'name' => 'Alpha Press Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        PressLog::create([
            'lot_id' => $lot->id,
            'press_type' => 'basket',
            'fruit_weight_kg' => 500,
            'total_juice_gallons' => 80,
            'fractions' => [
                ['fraction' => 'free_run', 'volume_gallons' => 80, 'child_lot_id' => null],
            ],
            'yield_percent' => 16.0,
            'performed_by' => $user->id,
            'performed_at' => now(),
        ]);
    });

    // Tenant B should see zero press logs
    $tenantB->run(function () {
        expect(PressLog::count())->toBe(0);
    });

    // Tenant A should see one
    $tenantA->run(function () {
        expect(PressLog::count())->toBe(1);
    });
});

// ─── Tier 2: CRUD ────────────────────────────────────────────────

it('creates a press log with all fields', function () {
    [$tenant, $token] = createPressLogTestTenant('press-crud');

    $lotId = null;
    $vesselId = null;
    $tenant->run(function () use (&$lotId, &$vesselId) {
        $lot = Lot::create([
            'name' => 'CRUD Press Lot',
            'variety' => 'Pinot Noir',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 800,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;

        $vessel = Vessel::create([
            'name' => 'Press-01',
            'type' => 'tank',
            'capacity_gallons' => 500,
            'status' => 'in_use',
        ]);
        $vesselId = $vessel->id;
    });

    $response = test()->postJson('/api/v1/press-logs', [
        'lot_id' => $lotId,
        'vessel_id' => $vesselId,
        'press_type' => 'bladder',
        'fruit_weight_kg' => 1200,
        'total_juice_gallons' => 220,
        'fractions' => [
            ['fraction' => 'free_run', 'volume_gallons' => 140],
            ['fraction' => 'light_press', 'volume_gallons' => 55],
            ['fraction' => 'heavy_press', 'volume_gallons' => 25],
        ],
        'pomace_weight_kg' => 350,
        'pomace_destination' => 'vineyard',
        'notes' => 'Gentle whole-cluster pressing, 2-hour cycle',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['lot_id'])->toBe($lotId);
    expect($data['vessel_id'])->toBe($vesselId);
    expect($data['press_type'])->toBe('bladder');
    expect((float) $data['fruit_weight_kg'])->toBe(1200.0);
    expect((float) $data['total_juice_gallons'])->toBe(220.0);
    expect($data['fractions'])->toHaveCount(3);
    expect((float) $data['pomace_weight_kg'])->toBe(350.0);
    expect($data['pomace_destination'])->toBe('vineyard');
    expect($data['notes'])->toBe('Gentle whole-cluster pressing, 2-hour cycle');
    expect($data['lot'])->not->toBeNull();
    expect($data['lot']['name'])->toBe('CRUD Press Lot');
    expect($data['vessel'])->not->toBeNull();
    expect($data['performed_by'])->not->toBeNull();
});

it('lists press logs with pagination', function () {
    [$tenant, $token] = createPressLogTestTenant('press-list');

    $tenant->run(function () {
        $lot = Lot::create([
            'name' => 'List Press Lot',
            'variety' => 'Chardonnay',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 600,
            'status' => 'in_progress',
        ]);

        $userId = User::where('email', 'winemaker@example.com')->first()->id;

        for ($i = 0; $i < 3; $i++) {
            PressLog::create([
                'lot_id' => $lot->id,
                'press_type' => 'pneumatic',
                'fruit_weight_kg' => 500 + ($i * 100),
                'total_juice_gallons' => 90 + ($i * 20),
                'fractions' => [
                    ['fraction' => 'free_run', 'volume_gallons' => 90 + ($i * 20), 'child_lot_id' => null],
                ],
                'yield_percent' => 18.0,
                'performed_by' => $userId,
                'performed_at' => now()->subDays($i),
            ]);
        }
    });

    $response = test()->getJson('/api/v1/press-logs', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('meta.total'))->toBe(3);
});

it('filters press logs by lot_id', function () {
    [$tenant, $token] = createPressLogTestTenant('press-filter');

    $lotId1 = null;
    $tenant->run(function () use (&$lotId1) {
        $lot1 = Lot::create([
            'name' => 'Filter Lot A',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
        $lotId1 = $lot1->id;

        $lot2 = Lot::create([
            'name' => 'Filter Lot B',
            'variety' => 'Syrah',
            'vintage' => 2024,
            'source_type' => 'purchased',
            'volume_gallons' => 300,
            'status' => 'in_progress',
        ]);

        $userId = User::where('email', 'winemaker@example.com')->first()->id;

        PressLog::create([
            'lot_id' => $lot1->id,
            'press_type' => 'basket',
            'fruit_weight_kg' => 400,
            'total_juice_gallons' => 70,
            'fractions' => [['fraction' => 'free_run', 'volume_gallons' => 70, 'child_lot_id' => null]],
            'yield_percent' => 17.5,
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
        PressLog::create([
            'lot_id' => $lot2->id,
            'press_type' => 'pneumatic',
            'fruit_weight_kg' => 300,
            'total_juice_gallons' => 55,
            'fractions' => [['fraction' => 'free_run', 'volume_gallons' => 55, 'child_lot_id' => null]],
            'yield_percent' => 18.3,
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
    });

    $response = test()->getJson("/api/v1/press-logs?lot_id={$lotId1}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.press_type'))->toBe('basket');
});

it('shows press log detail with relationships', function () {
    [$tenant, $token] = createPressLogTestTenant('press-show');

    $pressLogId = null;
    $tenant->run(function () use (&$pressLogId) {
        $lot = Lot::create([
            'name' => 'Show Press Lot',
            'variety' => 'Riesling',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 400,
            'status' => 'in_progress',
        ]);

        $vessel = Vessel::create([
            'name' => 'Press-05',
            'type' => 'tank',
            'capacity_gallons' => 300,
            'status' => 'in_use',
        ]);

        $userId = User::where('email', 'winemaker@example.com')->first()->id;

        $pressLog = PressLog::create([
            'lot_id' => $lot->id,
            'vessel_id' => $vessel->id,
            'press_type' => 'manual',
            'fruit_weight_kg' => 200,
            'total_juice_gallons' => 35,
            'fractions' => [
                ['fraction' => 'free_run', 'volume_gallons' => 25, 'child_lot_id' => null],
                ['fraction' => 'light_press', 'volume_gallons' => 10, 'child_lot_id' => null],
            ],
            'yield_percent' => 17.5,
            'pomace_weight_kg' => 60,
            'pomace_destination' => 'sold',
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
        $pressLogId = $pressLog->id;
    });

    $response = test()->getJson("/api/v1/press-logs/{$pressLogId}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();

    $data = $response->json('data');
    expect($data['id'])->toBe($pressLogId);
    expect($data['press_type'])->toBe('manual');
    expect($data['lot'])->not->toBeNull();
    expect($data['lot']['name'])->toBe('Show Press Lot');
    expect($data['vessel'])->not->toBeNull();
    expect($data['vessel']['name'])->toBe('Press-05');
    expect($data['performed_by'])->not->toBeNull();
    expect($data['fractions'])->toHaveCount(2);
});

// ─── Tier 2: Validation ──────────────────────────────────────────

it('rejects press log with missing required fields', function () {
    [$tenant, $token] = createPressLogTestTenant('press-val-req');

    $response = test()->postJson('/api/v1/press-logs', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('lot_id');
    expect($fields)->toContain('press_type');
    expect($fields)->toContain('fruit_weight_kg');
    expect($fields)->toContain('total_juice_gallons');
    expect($fields)->toContain('fractions');
});

it('rejects invalid press_type', function () {
    [$tenant, $token] = createPressLogTestTenant('press-val-type');
    $lotId = createPressTestLot($tenant, 'Val Type Lot');

    $response = test()->postJson('/api/v1/press-logs', [
        'lot_id' => $lotId,
        'press_type' => 'hydraulic_crusher',
        'fruit_weight_kg' => 500,
        'total_juice_gallons' => 90,
        'fractions' => [
            ['fraction' => 'free_run', 'volume_gallons' => 90],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('press_type');
});

it('rejects invalid fraction type', function () {
    [$tenant, $token] = createPressLogTestTenant('press-val-frac');
    $lotId = createPressTestLot($tenant, 'Val Frac Lot');

    $response = test()->postJson('/api/v1/press-logs', [
        'lot_id' => $lotId,
        'press_type' => 'pneumatic',
        'fruit_weight_kg' => 500,
        'total_juice_gallons' => 90,
        'fractions' => [
            ['fraction' => 'ultra_press', 'volume_gallons' => 90],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('fractions.0.fraction');
});

// ─── Tier 2: RBAC ────────────────────────────────────────────────

it('winemaker can log pressings', function () {
    [$tenant, $token] = createPressLogTestTenant('press-rbac-wm');
    $lotId = createPressTestLot($tenant, 'WM Press Lot');

    test()->postJson('/api/v1/press-logs', [
        'lot_id' => $lotId,
        'press_type' => 'pneumatic',
        'fruit_weight_kg' => 500,
        'total_juice_gallons' => 90,
        'fractions' => [
            ['fraction' => 'free_run', 'volume_gallons' => 90],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);
});

it('cellar_hand cannot log pressings', function () {
    [$tenant, $token] = createPressLogTestTenant('press-rbac-ch', 'cellar_hand');
    $lotId = createPressTestLot($tenant, 'CH Press Lot');

    test()->postJson('/api/v1/press-logs', [
        'lot_id' => $lotId,
        'press_type' => 'pneumatic',
        'fruit_weight_kg' => 500,
        'total_juice_gallons' => 90,
        'fractions' => [
            ['fraction' => 'free_run', 'volume_gallons' => 90],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('read-only users can list and view press logs', function () {
    [$tenant, $token] = createPressLogTestTenant('press-rbac-ro', 'read_only');

    $pressLogId = null;
    $tenant->run(function () use (&$pressLogId) {
        $lot = Lot::create([
            'name' => 'RO Press Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $userId = User::where('email', 'read_only@example.com')->first()->id;

        $pressLog = PressLog::create([
            'lot_id' => $lot->id,
            'press_type' => 'basket',
            'fruit_weight_kg' => 300,
            'total_juice_gallons' => 50,
            'fractions' => [['fraction' => 'free_run', 'volume_gallons' => 50, 'child_lot_id' => null]],
            'yield_percent' => 16.67,
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
        $pressLogId = $pressLog->id;
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Can list
    test()->getJson('/api/v1/press-logs', $headers)->assertOk();

    // Can view
    test()->getJson("/api/v1/press-logs/{$pressLogId}", $headers)->assertOk();
});

// ─── Tier 2: API Envelope & Auth ─────────────────────────────────

it('returns press log responses in the standard API envelope format', function () {
    [$tenant, $token] = createPressLogTestTenant('press-env');
    $lotId = createPressTestLot($tenant, 'Envelope Press Lot');

    $response = test()->postJson('/api/v1/press-logs', [
        'lot_id' => $lotId,
        'press_type' => 'pneumatic',
        'fruit_weight_kg' => 500,
        'total_juice_gallons' => 90,
        'fractions' => [
            ['fraction' => 'free_run', 'volume_gallons' => 90],
        ],
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'data' => ['id', 'lot_id', 'press_type', 'fruit_weight_kg', 'total_juice_gallons', 'yield_percent', 'fractions'],
        'meta',
        'errors',
    ]);
    expect($response->json('errors'))->toBe([]);
});

it('rejects unauthenticated access to press logs', function () {
    test()->getJson('/api/v1/press-logs', [
        'X-Tenant-ID' => 'fake-tenant',
    ])->assertStatus(401);
});
