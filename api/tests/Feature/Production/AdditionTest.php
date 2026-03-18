<?php

declare(strict_types=1);

use App\Models\Addition;
use App\Models\Event;
use App\Models\Lot;
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
function createAdditionTestTenant(string $slug = 'add-winery', string $role = 'cellar_hand'): array
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

it('writes an addition_made event when an addition is logged', function () {
    [$tenant, $token] = createAdditionTestTenant();

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'Event Test Lot',
            'variety' => 'Chardonnay',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    test()->postJson('/api/v1/additions', [
        'lot_id' => $lotId,
        'addition_type' => 'sulfite',
        'product_name' => 'Potassium Metabisulfite',
        'rate' => 25,
        'rate_unit' => 'ppm',
        'total_amount' => 12.5,
        'total_unit' => 'g',
        'reason' => 'Pre-fermentation sulfite addition',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);

    // Verify event was written
    $tenant->run(function () use ($lotId) {
        $event = Event::where('entity_type', 'lot')
            ->where('entity_id', $lotId)
            ->where('operation_type', 'addition_made')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['addition_type'])->toBe('sulfite');
        expect($event->payload['product_name'])->toBe('Potassium Metabisulfite');
        expect($event->payload['total_amount'])->toBe(12.5);
        expect($event->payload['total_unit'])->toBe('g');
        expect((float) $event->payload['rate'])->toBe(25.0);
        expect($event->payload['rate_unit'])->toBe('ppm');
    });
});

it('maintains SO2 running total across multiple sulfite additions', function () {
    [$tenant, $token] = createAdditionTestTenant('so2-winery');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'SO2 Test Lot',
            'variety' => 'Sauvignon Blanc',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 300,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // First SO2 addition: 25 ppm
    test()->postJson('/api/v1/additions', [
        'lot_id' => $lotId,
        'addition_type' => 'sulfite',
        'product_name' => 'Potassium Metabisulfite',
        'rate' => 25,
        'rate_unit' => 'ppm',
        'total_amount' => 12.5,
        'total_unit' => 'g',
    ], $headers)->assertStatus(201);

    // Second SO2 addition: 15 ppm
    test()->postJson('/api/v1/additions', [
        'lot_id' => $lotId,
        'addition_type' => 'sulfite',
        'product_name' => 'Potassium Metabisulfite',
        'rate' => 15,
        'rate_unit' => 'ppm',
        'total_amount' => 7.5,
        'total_unit' => 'g',
    ], $headers)->assertStatus(201);

    // Check SO2 running total = 25 + 15 = 40 ppm
    $response = test()->getJson("/api/v1/additions/so2-total?lot_id={$lotId}", $headers);

    $response->assertOk();
    expect((float) $response->json('data.so2_total_ppm'))->toBe(40.0);
});

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

it('prevents cross-tenant addition data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Winery Alpha',
        'slug' => 'add-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Winery Beta',
        'slug' => 'add-iso-beta',
        'plan' => 'pro',
    ]);

    // Create an addition in Tenant A
    $tenantA->run(function () {
        $user = User::create([
            'name' => 'Winemaker A',
            'email' => 'wm@alpha.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);

        $lot = Lot::create([
            'name' => 'Alpha Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        Addition::create([
            'lot_id' => $lot->id,
            'addition_type' => 'sulfite',
            'product_name' => 'KMBS',
            'total_amount' => 10,
            'total_unit' => 'g',
            'performed_by' => $user->id,
            'performed_at' => now(),
        ]);
    });

    // Tenant B should see zero additions
    $tenantB->run(function () {
        expect(Addition::count())->toBe(0);
    });

    // Tenant A should see one addition
    $tenantA->run(function () {
        expect(Addition::count())->toBe(1);
    });
});

// ─── Tier 2: CRUD ────────────────────────────────────────────────

it('creates an addition with all fields', function () {
    [$tenant, $token] = createAdditionTestTenant('add-crud');

    $lotId = null;
    $vesselId = null;
    $tenant->run(function () use (&$lotId, &$vesselId) {
        $lot = Lot::create([
            'name' => 'CRUD Test Lot',
            'variety' => 'Pinot Noir',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 1000,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;

        $vessel = Vessel::create([
            'name' => 'T-001',
            'type' => 'tank',
            'capacity_gallons' => 1200,
            'status' => 'in_use',
        ]);
        $vesselId = $vessel->id;
    });

    $response = test()->postJson('/api/v1/additions', [
        'lot_id' => $lotId,
        'vessel_id' => $vesselId,
        'addition_type' => 'nutrient',
        'product_name' => 'Fermaid O',
        'rate' => 0.4,
        'rate_unit' => 'g/L',
        'total_amount' => 151.4,
        'total_unit' => 'g',
        'reason' => 'Staggered nutrient addition — 1/3 sugar depletion',
        'performed_at' => '2024-09-15T10:30:00Z',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['lot_id'])->toBe($lotId);
    expect($data['vessel_id'])->toBe($vesselId);
    expect($data['addition_type'])->toBe('nutrient');
    expect($data['product_name'])->toBe('Fermaid O');
    expect($data['rate'])->toBe(0.4);
    expect($data['rate_unit'])->toBe('g/L');
    expect($data['total_amount'])->toBe(151.4);
    expect($data['total_unit'])->toBe('g');
    expect($data['reason'])->toBe('Staggered nutrient addition — 1/3 sugar depletion');
    expect($data['lot'])->not->toBeNull();
    expect($data['lot']['name'])->toBe('CRUD Test Lot');
    expect($data['performed_by'])->not->toBeNull();
});

it('lists additions with pagination', function () {
    [$tenant, $token] = createAdditionTestTenant('add-list');

    $lotId = null;
    $userId = null;
    $tenant->run(function () use (&$lotId, &$userId) {
        $lot = Lot::create([
            'name' => 'List Test Lot',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 1000,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;

        $userId = User::where('email', 'cellar_hand@example.com')->first()->id;

        // Create 3 additions directly
        Addition::create([
            'lot_id' => $lot->id,
            'addition_type' => 'sulfite',
            'product_name' => 'KMBS',
            'rate' => 25,
            'rate_unit' => 'ppm',
            'total_amount' => 12.5,
            'total_unit' => 'g',
            'performed_by' => $userId,
            'performed_at' => now()->subDays(3),
        ]);
        Addition::create([
            'lot_id' => $lot->id,
            'addition_type' => 'nutrient',
            'product_name' => 'Fermaid K',
            'rate' => 0.3,
            'rate_unit' => 'g/L',
            'total_amount' => 113.6,
            'total_unit' => 'g',
            'performed_by' => $userId,
            'performed_at' => now()->subDays(2),
        ]);
        Addition::create([
            'lot_id' => $lot->id,
            'addition_type' => 'fining',
            'product_name' => 'Bentonite',
            'rate' => 0.5,
            'rate_unit' => 'g/L',
            'total_amount' => 189.3,
            'total_unit' => 'g',
            'performed_by' => $userId,
            'performed_at' => now()->subDay(),
        ]);
    });

    $response = test()->getJson('/api/v1/additions', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('meta.total'))->toBe(3);
});

it('filters additions by lot_id', function () {
    [$tenant, $token] = createAdditionTestTenant('add-filter-lot');

    $lotId1 = null;
    $lotId2 = null;
    $tenant->run(function () use (&$lotId1, &$lotId2) {
        $lot1 = Lot::create([
            'name' => 'Lot A',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
        $lotId1 = $lot1->id;

        $lot2 = Lot::create([
            'name' => 'Lot B',
            'variety' => 'Syrah',
            'vintage' => 2024,
            'source_type' => 'purchased',
            'volume_gallons' => 300,
            'status' => 'in_progress',
        ]);
        $lotId2 = $lot2->id;

        $userId = User::where('email', 'cellar_hand@example.com')->first()->id;

        Addition::create([
            'lot_id' => $lotId1,
            'addition_type' => 'sulfite',
            'product_name' => 'KMBS',
            'total_amount' => 10,
            'total_unit' => 'g',
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
        Addition::create([
            'lot_id' => $lotId2,
            'addition_type' => 'acid',
            'product_name' => 'Tartaric Acid',
            'total_amount' => 50,
            'total_unit' => 'g',
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
    });

    $response = test()->getJson("/api/v1/additions?lot_id={$lotId1}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.product_name'))->toBe('KMBS');
});

it('filters additions by addition_type', function () {
    [$tenant, $token] = createAdditionTestTenant('add-filter-type');

    $tenant->run(function () {
        $lot = Lot::create([
            'name' => 'Filter Lot',
            'variety' => 'Zinfandel',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 800,
            'status' => 'in_progress',
        ]);

        $userId = User::where('email', 'cellar_hand@example.com')->first()->id;

        Addition::create([
            'lot_id' => $lot->id,
            'addition_type' => 'sulfite',
            'product_name' => 'KMBS',
            'total_amount' => 10,
            'total_unit' => 'g',
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
        Addition::create([
            'lot_id' => $lot->id,
            'addition_type' => 'nutrient',
            'product_name' => 'DAP',
            'total_amount' => 50,
            'total_unit' => 'g',
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
    });

    $response = test()->getJson('/api/v1/additions?addition_type=sulfite', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.addition_type'))->toBe('sulfite');
});

it('shows addition detail with relationships', function () {
    [$tenant, $token] = createAdditionTestTenant('add-show');

    $additionId = null;
    $tenant->run(function () use (&$additionId) {
        $lot = Lot::create([
            'name' => 'Show Test Lot',
            'variety' => 'Riesling',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 400,
            'status' => 'in_progress',
        ]);

        $vessel = Vessel::create([
            'name' => 'T-010',
            'type' => 'tank',
            'capacity_gallons' => 500,
            'status' => 'in_use',
        ]);

        $userId = User::where('email', 'cellar_hand@example.com')->first()->id;

        $addition = Addition::create([
            'lot_id' => $lot->id,
            'vessel_id' => $vessel->id,
            'addition_type' => 'enzyme',
            'product_name' => 'Lallzyme EX-V',
            'rate' => 0.03,
            'rate_unit' => 'g/L',
            'total_amount' => 4.54,
            'total_unit' => 'g',
            'reason' => 'Color extraction',
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
        $additionId = $addition->id;
    });

    $response = test()->getJson("/api/v1/additions/{$additionId}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();

    $data = $response->json('data');
    expect($data['id'])->toBe($additionId);
    expect($data['product_name'])->toBe('Lallzyme EX-V');
    expect($data['lot'])->not->toBeNull();
    expect($data['lot']['name'])->toBe('Show Test Lot');
    expect($data['vessel'])->not->toBeNull();
    expect($data['vessel']['name'])->toBe('T-010');
    expect($data['performed_by'])->not->toBeNull();
});

// ─── Tier 2: Validation ──────────────────────────────────────────

it('rejects addition with missing required fields', function () {
    [$tenant, $token] = createAdditionTestTenant('add-val-req');

    $response = test()->postJson('/api/v1/additions', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('lot_id');
    expect($fields)->toContain('addition_type');
    expect($fields)->toContain('product_name');
    expect($fields)->toContain('total_amount');
    expect($fields)->toContain('total_unit');
});

it('rejects invalid addition_type', function () {
    [$tenant, $token] = createAdditionTestTenant('add-val-type');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'Val Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    $response = test()->postJson('/api/v1/additions', [
        'lot_id' => $lotId,
        'addition_type' => 'magic_potion',
        'product_name' => 'Unicorn Dust',
        'total_amount' => 10,
        'total_unit' => 'g',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('addition_type');
});

it('rejects invalid total_unit', function () {
    [$tenant, $token] = createAdditionTestTenant('add-val-unit');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'Unit Val Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    $response = test()->postJson('/api/v1/additions', [
        'lot_id' => $lotId,
        'addition_type' => 'sulfite',
        'product_name' => 'KMBS',
        'total_amount' => 10,
        'total_unit' => 'bushels',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('total_unit');
});

// ─── Tier 2: RBAC ────────────────────────────────────────────────

it('cellar_hand can create additions', function () {
    [$tenant, $token] = createAdditionTestTenant('add-rbac-ch');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'RBAC Lot',
            'variety' => 'Tempranillo',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    test()->postJson('/api/v1/additions', [
        'lot_id' => $lotId,
        'addition_type' => 'sulfite',
        'product_name' => 'KMBS',
        'total_amount' => 10,
        'total_unit' => 'g',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);
});

it('read-only users cannot create additions', function () {
    [$tenant, $token] = createAdditionTestTenant('add-rbac-ro', 'read_only');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'RO Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    test()->postJson('/api/v1/additions', [
        'lot_id' => $lotId,
        'addition_type' => 'sulfite',
        'product_name' => 'KMBS',
        'total_amount' => 10,
        'total_unit' => 'g',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('read-only users can list and view additions', function () {
    [$tenant, $token] = createAdditionTestTenant('add-rbac-ro-view', 'read_only');

    $additionId = null;
    $tenant->run(function () use (&$additionId) {
        $lot = Lot::create([
            'name' => 'View Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $userId = User::where('email', 'read_only@example.com')->first()->id;

        $addition = Addition::create([
            'lot_id' => $lot->id,
            'addition_type' => 'sulfite',
            'product_name' => 'KMBS',
            'total_amount' => 10,
            'total_unit' => 'g',
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
        $additionId = $addition->id;
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Can list
    test()->getJson('/api/v1/additions', $headers)->assertOk();

    // Can view
    test()->getJson("/api/v1/additions/{$additionId}", $headers)->assertOk();
});

// ─── Tier 2: API Envelope & Auth ─────────────────────────────────

it('returns addition responses in the standard API envelope format', function () {
    [$tenant, $token] = createAdditionTestTenant('add-env');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'Envelope Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    $response = test()->postJson('/api/v1/additions', [
        'lot_id' => $lotId,
        'addition_type' => 'sulfite',
        'product_name' => 'KMBS',
        'total_amount' => 10,
        'total_unit' => 'g',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'data' => ['id', 'lot_id', 'addition_type', 'product_name', 'total_amount', 'total_unit'],
        'meta',
        'errors',
    ]);
    expect($response->json('errors'))->toBe([]);
});

it('rejects unauthenticated access to additions', function () {
    test()->getJson('/api/v1/additions', [
        'X-Tenant-ID' => 'fake-tenant',
    ])->assertStatus(401);
});
