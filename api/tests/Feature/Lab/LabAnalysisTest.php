<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\LabAnalysis;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createLabTestTenant(string $slug = 'lab-winery', string $role = 'cellar_hand'): array
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

it('writes a lab_analysis_entered event when analysis is recorded', function () {
    [$tenant, $token] = createLabTestTenant();

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

    test()->postJson("/api/v1/lots/{$lotId}/analyses", [
        'lot_id' => $lotId,
        'test_date' => '2024-10-15',
        'test_type' => 'pH',
        'value' => 3.45,
        'unit' => 'pH',
        'method' => 'pH meter',
        'analyst' => 'Jane Doe',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);

    // Verify event was written with self-contained payload
    $tenant->run(function () use ($lotId) {
        $event = Event::where('entity_type', 'lot')
            ->where('entity_id', $lotId)
            ->where('operation_type', 'lab_analysis_entered')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['test_type'])->toBe('pH');
        expect($event->payload['value'])->toBe(3.45);
        expect($event->payload['unit'])->toBe('pH');
        expect($event->payload['method'])->toBe('pH meter');
        expect($event->payload['analyst'])->toBe('Jane Doe');
        expect($event->payload['test_date'])->toBe('2024-10-15');
        // Export-friendly: lot name and variety included in payload
        expect($event->payload['lot_name'])->toBe('Event Test Lot');
        expect($event->payload['lot_variety'])->toBe('Chardonnay');
    });
});

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

it('prevents cross-tenant lab analysis data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Winery Alpha',
        'slug' => 'lab-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Winery Beta',
        'slug' => 'lab-iso-beta',
        'plan' => 'pro',
    ]);

    // Create a lab analysis in Tenant A
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

        LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'pH',
            'value' => 3.5,
            'unit' => 'pH',
            'source' => 'manual',
            'performed_by' => $user->id,
        ]);
    });

    // Tenant B should see zero analyses
    $tenantB->run(function () {
        expect(LabAnalysis::count())->toBe(0);
    });

    // Tenant A should see one analysis
    $tenantA->run(function () {
        expect(LabAnalysis::count())->toBe(1);
    });
});

// ─── Tier 2: CRUD ────────────────────────────────────────────────

it('creates a lab analysis with all fields', function () {
    [$tenant, $token] = createLabTestTenant('lab-crud');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'CRUD Test Lot',
            'variety' => 'Pinot Noir',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 1000,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/analyses", [
        'lot_id' => $lotId,
        'test_date' => '2024-10-20',
        'test_type' => 'VA',
        'value' => 0.045,
        'unit' => 'g/100mL',
        'method' => 'Cash still',
        'analyst' => 'ETS Laboratories',
        'notes' => 'Routine VA check',
        'source' => 'ets_labs',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['lot_id'])->toBe($lotId);
    expect($data['test_date'])->toBe('2024-10-20');
    expect($data['test_type'])->toBe('VA');
    expect($data['value'])->toBe(0.045);
    expect($data['unit'])->toBe('g/100mL');
    expect($data['method'])->toBe('Cash still');
    expect($data['analyst'])->toBe('ETS Laboratories');
    expect($data['notes'])->toBe('Routine VA check');
    expect($data['source'])->toBe('ets_labs');
    expect($data['lot'])->not->toBeNull();
    expect($data['lot']['name'])->toBe('CRUD Test Lot');
    expect($data['performed_by'])->not->toBeNull();
});

it('lists lab analyses for a lot with pagination', function () {
    [$tenant, $token] = createLabTestTenant('lab-list');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
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

        LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'pH',
            'value' => 3.45,
            'unit' => 'pH',
            'source' => 'manual',
            'performed_by' => $userId,
        ]);
        LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'TA',
            'value' => 6.8,
            'unit' => 'g/L',
            'source' => 'manual',
            'performed_by' => $userId,
        ]);
        LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-20',
            'test_type' => 'VA',
            'value' => 0.03,
            'unit' => 'g/100mL',
            'source' => 'manual',
            'performed_by' => $userId,
        ]);
    });

    $response = test()->getJson("/api/v1/lots/{$lotId}/analyses", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('meta.total'))->toBe(3);
});

it('filters lab analyses by test_type', function () {
    [$tenant, $token] = createLabTestTenant('lab-filter-type');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'Filter Lot',
            'variety' => 'Zinfandel',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 800,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;

        $userId = User::where('email', 'cellar_hand@example.com')->first()->id;

        LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'pH',
            'value' => 3.5,
            'unit' => 'pH',
            'source' => 'manual',
            'performed_by' => $userId,
        ]);
        LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'VA',
            'value' => 0.04,
            'unit' => 'g/100mL',
            'source' => 'manual',
            'performed_by' => $userId,
        ]);
    });

    $response = test()->getJson("/api/v1/lots/{$lotId}/analyses?test_type=pH", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.test_type'))->toBe('pH');
});

it('shows lab analysis detail with relationships', function () {
    [$tenant, $token] = createLabTestTenant('lab-show');

    $analysisId = null;
    $lotId = null;
    $tenant->run(function () use (&$analysisId, &$lotId) {
        $lot = Lot::create([
            'name' => 'Show Test Lot',
            'variety' => 'Riesling',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 400,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;

        $userId = User::where('email', 'cellar_hand@example.com')->first()->id;

        $analysis = LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-11-01',
            'test_type' => 'residual_sugar',
            'value' => 8.5,
            'unit' => 'g/L',
            'method' => 'Enzymatic',
            'analyst' => 'In-house lab',
            'notes' => 'Off-dry target',
            'source' => 'manual',
            'performed_by' => $userId,
        ]);
        $analysisId = $analysis->id;
    });

    $response = test()->getJson("/api/v1/lots/{$lotId}/analyses/{$analysisId}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();

    $data = $response->json('data');
    expect($data['id'])->toBe($analysisId);
    expect($data['test_type'])->toBe('residual_sugar');
    expect($data['value'])->toBe(8.5);
    expect($data['lot'])->not->toBeNull();
    expect($data['lot']['name'])->toBe('Show Test Lot');
    expect($data['performed_by'])->not->toBeNull();
});

it('records multiple test types for the same lot and date', function () {
    [$tenant, $token] = createLabTestTenant('lab-multi');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'Multi Test Lot',
            'variety' => 'Sauvignon Blanc',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 600,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Record pH, TA, and free SO2 all for the same date
    test()->postJson("/api/v1/lots/{$lotId}/analyses", [
        'lot_id' => $lotId,
        'test_date' => '2024-10-15',
        'test_type' => 'pH',
        'value' => 3.35,
        'unit' => 'pH',
    ], $headers)->assertStatus(201);

    test()->postJson("/api/v1/lots/{$lotId}/analyses", [
        'lot_id' => $lotId,
        'test_date' => '2024-10-15',
        'test_type' => 'TA',
        'value' => 7.2,
        'unit' => 'g/L',
    ], $headers)->assertStatus(201);

    test()->postJson("/api/v1/lots/{$lotId}/analyses", [
        'lot_id' => $lotId,
        'test_date' => '2024-10-15',
        'test_type' => 'free_SO2',
        'value' => 28.0,
        'unit' => 'mg/L',
    ], $headers)->assertStatus(201);

    // All three should be listed
    $response = test()->getJson("/api/v1/lots/{$lotId}/analyses", $headers);
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);

    // Verify three separate events were written
    $tenant->run(function () use ($lotId) {
        $events = Event::where('entity_type', 'lot')
            ->where('entity_id', $lotId)
            ->where('operation_type', 'lab_analysis_entered')
            ->get();

        expect($events)->toHaveCount(3);
    });
});

// ─── Tier 2: Validation ──────────────────────────────────────────

it('rejects lab analysis with missing required fields', function () {
    [$tenant, $token] = createLabTestTenant('lab-val-req');

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

    $response = test()->postJson("/api/v1/lots/{$lotId}/analyses", [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('lot_id');
    expect($fields)->toContain('test_date');
    expect($fields)->toContain('test_type');
    expect($fields)->toContain('value');
    expect($fields)->toContain('unit');
});

it('rejects invalid test_type', function () {
    [$tenant, $token] = createLabTestTenant('lab-val-type');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'Val Type Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/analyses", [
        'lot_id' => $lotId,
        'test_date' => '2024-10-15',
        'test_type' => 'magic_measurement',
        'value' => 42.0,
        'unit' => 'unicorns',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('test_type');
});

it('accepts backdated test_date for historical imports', function () {
    [$tenant, $token] = createLabTestTenant('lab-backdate');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = Lot::create([
            'name' => 'Historical Lot',
            'variety' => 'Cabernet Franc',
            'vintage' => 2023,
            'source_type' => 'estate',
            'volume_gallons' => 400,
            'status' => 'aging',
        ]);
        $lotId = $lot->id;
    });

    // Backdated by over a year — per gradual-migration-path constraint
    $response = test()->postJson("/api/v1/lots/{$lotId}/analyses", [
        'lot_id' => $lotId,
        'test_date' => '2023-09-20',
        'test_type' => 'pH',
        'value' => 3.62,
        'unit' => 'pH',
        'source' => 'csv_import',
        'analyst' => 'ETS Laboratories',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    expect($response->json('data.test_date'))->toBe('2023-09-20');
    expect($response->json('data.source'))->toBe('csv_import');
});

// ─── Tier 2: RBAC ────────────────────────────────────────────────

it('cellar_hand can create lab analyses', function () {
    [$tenant, $token] = createLabTestTenant('lab-rbac-ch');

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

    test()->postJson("/api/v1/lots/{$lotId}/analyses", [
        'lot_id' => $lotId,
        'test_date' => '2024-10-15',
        'test_type' => 'pH',
        'value' => 3.5,
        'unit' => 'pH',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);
});

it('read-only users cannot create lab analyses', function () {
    [$tenant, $token] = createLabTestTenant('lab-rbac-ro', 'read_only');

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

    test()->postJson("/api/v1/lots/{$lotId}/analyses", [
        'lot_id' => $lotId,
        'test_date' => '2024-10-15',
        'test_type' => 'pH',
        'value' => 3.5,
        'unit' => 'pH',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('read-only users can list and view lab analyses', function () {
    [$tenant, $token] = createLabTestTenant('lab-rbac-ro-view', 'read_only');

    $analysisId = null;
    $lotId = null;
    $tenant->run(function () use (&$analysisId, &$lotId) {
        $lot = Lot::create([
            'name' => 'View Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;

        $userId = User::where('email', 'read_only@example.com')->first()->id;

        $analysis = LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'pH',
            'value' => 3.5,
            'unit' => 'pH',
            'source' => 'manual',
            'performed_by' => $userId,
        ]);
        $analysisId = $analysis->id;
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Can list
    test()->getJson("/api/v1/lots/{$lotId}/analyses", $headers)->assertOk();

    // Can view
    test()->getJson("/api/v1/lots/{$lotId}/analyses/{$analysisId}", $headers)->assertOk();
});

// ─── Tier 2: API Envelope & Auth ─────────────────────────────────

it('returns lab analysis responses in the standard API envelope format', function () {
    [$tenant, $token] = createLabTestTenant('lab-env');

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

    $response = test()->postJson("/api/v1/lots/{$lotId}/analyses", [
        'lot_id' => $lotId,
        'test_date' => '2024-10-15',
        'test_type' => 'pH',
        'value' => 3.45,
        'unit' => 'pH',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'data' => ['id', 'lot_id', 'test_date', 'test_type', 'value', 'unit', 'source'],
        'meta',
        'errors',
    ]);
    expect($response->json('errors'))->toBe([]);
});

it('rejects unauthenticated access to lab analyses', function () {
    test()->getJson('/api/v1/lots/fake-lot-id/analyses', [
        'X-Tenant-ID' => 'fake-tenant',
    ])->assertStatus(401);
});
