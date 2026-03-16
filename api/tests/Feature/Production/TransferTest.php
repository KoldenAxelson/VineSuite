<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createTransferTestTenant(string $slug = 'xfer-winery', string $role = 'cellar_hand'): array
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
 * Helper: create a lot in a vessel with initial volume via the lot_vessel pivot.
 *
 * @return array{lot_id: string, from_vessel_id: string, to_vessel_id: string}
 */
function createTransferFixtures(Tenant $tenant, float $sourceVolume = 500.0): array
{
    $ids = ['lot_id' => '', 'from_vessel_id' => '', 'to_vessel_id' => ''];

    $tenant->run(function () use (&$ids, $sourceVolume) {
        $lot = Lot::create([
            'name' => 'Transfer Test Lot',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => $sourceVolume,
            'status' => 'in_progress',
        ]);

        $fromVessel = Vessel::create([
            'name' => 'T-001',
            'type' => 'tank',
            'capacity_gallons' => 1000,
            'status' => 'in_use',
        ]);

        $toVessel = Vessel::create([
            'name' => 'T-002',
            'type' => 'tank',
            'capacity_gallons' => 800,
            'status' => 'empty',
        ]);

        // Put the lot in the source vessel
        DB::table('lot_vessel')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'lot_id' => $lot->id,
            'vessel_id' => $fromVessel->id,
            'volume_gallons' => $sourceVolume,
            'filled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ids = [
            'lot_id' => $lot->id,
            'from_vessel_id' => $fromVessel->id,
            'to_vessel_id' => $toVessel->id,
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

// ─── Tier 1: Event Log Writes ────────────────────────────────────

it('writes a transfer_executed event when a transfer is logged', function () {
    [$tenant, $token] = createTransferTestTenant();
    $ids = createTransferFixtures($tenant);

    test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 200,
        'transfer_type' => 'pump',
        'variance_gallons' => 0.5,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);

    $tenant->run(function () use ($ids) {
        $event = Event::where('entity_type', 'lot')
            ->where('entity_id', $ids['lot_id'])
            ->where('operation_type', 'transfer_executed')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['from_vessel_id'])->toBe($ids['from_vessel_id']);
        expect($event->payload['to_vessel_id'])->toBe($ids['to_vessel_id']);
        expect((float) $event->payload['volume_gallons'])->toBe(200.0);
        expect((float) $event->payload['variance_gallons'])->toBe(0.5);
        expect($event->payload['transfer_type'])->toBe('pump');
    });
});

// ─── Tier 1: Volume Validation ───────────────────────────────────

it('rejects transfer exceeding source vessel volume', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-overdrawn');
    $ids = createTransferFixtures($tenant, 100.0);

    $response = test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 150,
        'transfer_type' => 'gravity',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $codes = array_column($response->json('errors'), 'code');
    expect($codes)->toContain('INSUFFICIENT_VOLUME');
});

it('updates source and target vessel volumes after transfer', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-volumes');
    $ids = createTransferFixtures($tenant, 500.0);

    test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 200,
        'transfer_type' => 'pump',
        'variance_gallons' => 1.5,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);

    $tenant->run(function () use ($ids) {
        // Source should have 500 - 200 = 300 gallons
        $sourcePivot = DB::table('lot_vessel')
            ->where('vessel_id', $ids['from_vessel_id'])
            ->whereNull('emptied_at')
            ->first();
        expect((float) $sourcePivot->volume_gallons)->toBe(300.0);

        // Target should have 200 - 1.5 variance = 198.5 gallons
        $targetPivot = DB::table('lot_vessel')
            ->where('vessel_id', $ids['to_vessel_id'])
            ->whereNull('emptied_at')
            ->first();
        expect((float) $targetPivot->volume_gallons)->toBe(198.5);
    });
});

it('empties source vessel and updates status when all volume transferred', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-empty');
    $ids = createTransferFixtures($tenant, 200.0);

    test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 200,
        'transfer_type' => 'gravity',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);

    $tenant->run(function () use ($ids) {
        // Source should be emptied (emptied_at set)
        $sourcePivot = DB::table('lot_vessel')
            ->where('vessel_id', $ids['from_vessel_id'])
            ->first();
        expect($sourcePivot->emptied_at)->not->toBeNull();
        expect((float) $sourcePivot->volume_gallons)->toBe(0.0);

        // Source vessel status should be 'empty'
        $vessel = Vessel::find($ids['from_vessel_id']);
        expect($vessel->status)->toBe('empty');

        // Target vessel status should be 'in_use'
        $targetVessel = Vessel::find($ids['to_vessel_id']);
        expect($targetVessel->status)->toBe('in_use');
    });
});

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

it('prevents cross-tenant transfer data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Winery Alpha',
        'slug' => 'xfer-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Winery Beta',
        'slug' => 'xfer-iso-beta',
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

        $lot = Lot::create([
            'name' => 'Alpha Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $v1 = Vessel::create(['name' => 'T-A1', 'type' => 'tank', 'capacity_gallons' => 1000]);
        $v2 = Vessel::create(['name' => 'T-A2', 'type' => 'tank', 'capacity_gallons' => 1000]);

        Transfer::create([
            'lot_id' => $lot->id,
            'from_vessel_id' => $v1->id,
            'to_vessel_id' => $v2->id,
            'volume_gallons' => 200,
            'transfer_type' => 'pump',
            'performed_by' => $user->id,
            'performed_at' => now(),
        ]);
    });

    $tenantB->run(function () {
        expect(Transfer::count())->toBe(0);
    });

    $tenantA->run(function () {
        expect(Transfer::count())->toBe(1);
    });
});

// ─── Tier 2: CRUD ────────────────────────────────────────────────

it('creates a transfer with all fields', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-crud');
    $ids = createTransferFixtures($tenant);

    $response = test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 300,
        'transfer_type' => 'gravity',
        'variance_gallons' => 0.25,
        'notes' => 'Gentle gravity transfer, minimal lees disturbance',
        'performed_at' => '2024-10-15T14:00:00Z',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['lot_id'])->toBe($ids['lot_id']);
    expect($data['from_vessel_id'])->toBe($ids['from_vessel_id']);
    expect($data['to_vessel_id'])->toBe($ids['to_vessel_id']);
    expect((float) $data['volume_gallons'])->toBe(300.0);
    expect($data['transfer_type'])->toBe('gravity');
    expect((float) $data['variance_gallons'])->toBe(0.25);
    expect($data['notes'])->toBe('Gentle gravity transfer, minimal lees disturbance');
    expect($data['from_vessel'])->not->toBeNull();
    expect($data['to_vessel'])->not->toBeNull();
    expect($data['performed_by'])->not->toBeNull();
});

it('lists transfers with pagination', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-list');
    $ids = createTransferFixtures($tenant, 1000.0);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Create two transfers
    test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 200,
        'transfer_type' => 'pump',
    ], $headers)->assertStatus(201);

    test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 150,
        'transfer_type' => 'gravity',
    ], $headers)->assertStatus(201);

    $response = test()->getJson('/api/v1/transfers', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('meta.total'))->toBe(2);
});

it('filters transfers by lot_id', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-filter');

    $ids = createTransferFixtures($tenant);

    // Create a second lot with its own transfer
    $lot2Id = null;
    $tenant->run(function () use (&$lot2Id, $ids) {
        $lot2 = Lot::create([
            'name' => 'Other Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'purchased',
            'volume_gallons' => 300,
            'status' => 'in_progress',
        ]);
        $lot2Id = $lot2->id;

        $v3 = Vessel::create([
            'name' => 'T-003',
            'type' => 'tank',
            'capacity_gallons' => 500,
            'status' => 'in_use',
        ]);

        DB::table('lot_vessel')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'lot_id' => $lot2->id,
            'vessel_id' => $v3->id,
            'volume_gallons' => 300,
            'filled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = User::where('email', 'cellar_hand@example.com')->first()->id;
        Transfer::create([
            'lot_id' => $lot2->id,
            'from_vessel_id' => $v3->id,
            'to_vessel_id' => $ids['to_vessel_id'],
            'volume_gallons' => 100,
            'transfer_type' => 'pump',
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Create a transfer for the first lot
    test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 100,
        'transfer_type' => 'gravity',
    ], $headers)->assertStatus(201);

    // Filter by first lot
    $response = test()->getJson("/api/v1/transfers?lot_id={$ids['lot_id']}", $headers);
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('shows transfer detail with relationships', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-show');
    $ids = createTransferFixtures($tenant);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $createResponse = test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 250,
        'transfer_type' => 'pump',
    ], $headers);

    $transferId = $createResponse->json('data.id');

    $response = test()->getJson("/api/v1/transfers/{$transferId}", $headers);

    $response->assertOk();
    $data = $response->json('data');
    expect($data['id'])->toBe($transferId);
    expect($data['lot'])->not->toBeNull();
    expect($data['from_vessel'])->not->toBeNull();
    expect($data['from_vessel']['name'])->toBe('T-001');
    expect($data['to_vessel'])->not->toBeNull();
    expect($data['to_vessel']['name'])->toBe('T-002');
    expect($data['performed_by'])->not->toBeNull();
});

// ─── Tier 2: Validation ──────────────────────────────────────────

it('rejects transfer with missing required fields', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-val-req');

    $response = test()->postJson('/api/v1/transfers', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('lot_id');
    expect($fields)->toContain('from_vessel_id');
    expect($fields)->toContain('to_vessel_id');
    expect($fields)->toContain('volume_gallons');
    expect($fields)->toContain('transfer_type');
});

it('rejects transfer to the same vessel', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-val-same');
    $ids = createTransferFixtures($tenant);

    $response = test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['from_vessel_id'],
        'volume_gallons' => 100,
        'transfer_type' => 'pump',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('from_vessel_id');
});

it('rejects invalid transfer_type', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-val-type');
    $ids = createTransferFixtures($tenant);

    $response = test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 100,
        'transfer_type' => 'teleportation',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('transfer_type');
});

// ─── Tier 2: RBAC ────────────────────────────────────────────────

it('cellar_hand can execute transfers', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-rbac-ch');
    $ids = createTransferFixtures($tenant);

    test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 100,
        'transfer_type' => 'gravity',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);
});

it('read-only users cannot execute transfers', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-rbac-ro', 'read_only');
    $ids = createTransferFixtures($tenant);

    test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 100,
        'transfer_type' => 'gravity',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

// ─── Tier 2: API Envelope & Auth ─────────────────────────────────

it('returns transfer responses in the standard API envelope format', function () {
    [$tenant, $token] = createTransferTestTenant('xfer-env');
    $ids = createTransferFixtures($tenant);

    $response = test()->postJson('/api/v1/transfers', [
        'lot_id' => $ids['lot_id'],
        'from_vessel_id' => $ids['from_vessel_id'],
        'to_vessel_id' => $ids['to_vessel_id'],
        'volume_gallons' => 100,
        'transfer_type' => 'pump',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'data' => ['id', 'lot_id', 'from_vessel_id', 'to_vessel_id', 'volume_gallons', 'transfer_type'],
        'meta',
        'errors',
    ]);
    expect($response->json('errors'))->toBe([]);
});

it('rejects unauthenticated access to transfers', function () {
    test()->getJson('/api/v1/transfers', [
        'X-Tenant-ID' => 'fake-tenant',
    ])->assertStatus(401);
});
