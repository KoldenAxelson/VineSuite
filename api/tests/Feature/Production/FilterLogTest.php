<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\FilterLog;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createFilterLogTestTenant(string $slug = 'filter-winery', string $role = 'cellar_hand'): array
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

function createFilterTestLot(Tenant $tenant, string $name = 'Filter Test Lot'): string
{
    $lotId = null;
    $tenant->run(function () use ($name, &$lotId) {
        $lot = Lot::create([
            'name' => $name,
            'variety' => 'Chardonnay',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
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

it('writes a filtering_logged event when a filtering is logged', function () {
    [$tenant, $token] = createFilterLogTestTenant();
    $lotId = createFilterTestLot($tenant);

    $response = test()->postJson('/api/v1/filter-logs', [
        'lot_id' => $lotId,
        'filter_type' => 'crossflow',
        'filter_media' => '0.45µm PES membrane',
        'flow_rate_lph' => 800,
        'volume_processed_gallons' => 450,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $tenant->run(function () use ($lotId) {
        $event = Event::where('entity_type', 'lot')
            ->where('entity_id', $lotId)
            ->where('operation_type', 'filtering_logged')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['filter_type'])->toBe('crossflow');
        expect($event->payload['filter_media'])->toBe('0.45µm PES membrane');
        expect((float) $event->payload['volume_processed_gallons'])->toBe(450.0);
        expect((float) $event->payload['flow_rate_lph'])->toBe(800.0);
    });
});

it('includes fining details in the event when present', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-fining-event');
    $lotId = createFilterTestLot($tenant, 'Fining Event Lot');

    $response = test()->postJson('/api/v1/filter-logs', [
        'lot_id' => $lotId,
        'filter_type' => 'pad',
        'filter_media' => 'Polish pad (K300)',
        'volume_processed_gallons' => 300,
        'fining_agent' => 'Bentonite',
        'fining_rate' => 0.5,
        'fining_rate_unit' => 'g/L',
        'bench_trial_notes' => 'Tested at 0.25, 0.5, 0.75 g/L. 0.5 g/L optimal.',
        'treatment_notes' => 'Applied with 24-hour contact time before racking.',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $tenant->run(function () use ($lotId) {
        $event = Event::where('entity_type', 'lot')
            ->where('entity_id', $lotId)
            ->where('operation_type', 'filtering_logged')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->payload['fining_agent'])->toBe('Bentonite');
        expect((float) $event->payload['fining_rate'])->toBe(0.5);
        expect($event->payload['fining_rate_unit'])->toBe('g/L');
    });
});

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

it('prevents cross-tenant filter log data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Winery Alpha',
        'slug' => 'filt-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Winery Beta',
        'slug' => 'filt-iso-beta',
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
            'name' => 'Alpha Filter Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        FilterLog::create([
            'lot_id' => $lot->id,
            'filter_type' => 'crossflow',
            'volume_processed_gallons' => 400,
            'performed_by' => $user->id,
            'performed_at' => now(),
        ]);
    });

    $tenantB->run(function () {
        expect(FilterLog::count())->toBe(0);
    });

    $tenantA->run(function () {
        expect(FilterLog::count())->toBe(1);
    });
});

// ─── Tier 2: CRUD ────────────────────────────────────────────────

it('creates a filter log with all fields', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-crud');

    $lotId = null;
    $vesselId = null;
    $tenant->run(function () use (&$lotId, &$vesselId) {
        $lot = Lot::create([
            'name' => 'CRUD Filter Lot',
            'variety' => 'Pinot Noir',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 800,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;

        $vessel = Vessel::create([
            'name' => 'T-020',
            'type' => 'tank',
            'capacity_gallons' => 1000,
            'status' => 'in_use',
        ]);
        $vesselId = $vessel->id;
    });

    $response = test()->postJson('/api/v1/filter-logs', [
        'lot_id' => $lotId,
        'vessel_id' => $vesselId,
        'filter_type' => 'plate_and_frame',
        'filter_media' => 'Cellulose sheets',
        'flow_rate_lph' => 1200,
        'volume_processed_gallons' => 750,
        'fining_agent' => 'Gelatin',
        'fining_rate' => 0.1,
        'fining_rate_unit' => 'g/L',
        'bench_trial_notes' => 'Trial at 3 rates, medium selected.',
        'treatment_notes' => '48-hour contact, then rack and filter.',
        'notes' => 'Pre-bottling polish filtration with fining.',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['lot_id'])->toBe($lotId);
    expect($data['vessel_id'])->toBe($vesselId);
    expect($data['filter_type'])->toBe('plate_and_frame');
    expect($data['filter_media'])->toBe('Cellulose sheets');
    expect((float) $data['flow_rate_lph'])->toBe(1200.0);
    expect((float) $data['volume_processed_gallons'])->toBe(750.0);
    expect($data['fining_agent'])->toBe('Gelatin');
    expect((float) $data['fining_rate'])->toBe(0.1);
    expect($data['fining_rate_unit'])->toBe('g/L');
    expect($data['bench_trial_notes'])->toBe('Trial at 3 rates, medium selected.');
    expect($data['treatment_notes'])->toBe('48-hour contact, then rack and filter.');
    expect($data['notes'])->toBe('Pre-bottling polish filtration with fining.');
    expect($data['lot'])->not->toBeNull();
    expect($data['vessel'])->not->toBeNull();
    expect($data['performed_by'])->not->toBeNull();
});

it('lists filter logs with pagination', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-list');

    $tenant->run(function () {
        $lot = Lot::create([
            'name' => 'List Filter Lot',
            'variety' => 'Sauvignon Blanc',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 600,
            'status' => 'in_progress',
        ]);

        $userId = User::where('email', 'cellar_hand@example.com')->first()->id;

        for ($i = 0; $i < 3; $i++) {
            FilterLog::create([
                'lot_id' => $lot->id,
                'filter_type' => 'pad',
                'volume_processed_gallons' => 200 + ($i * 50),
                'performed_by' => $userId,
                'performed_at' => now()->subDays($i),
            ]);
        }
    });

    $response = test()->getJson('/api/v1/filter-logs', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('meta.total'))->toBe(3);
});

it('filters filter logs by lot_id', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-by-lot');

    $lotId1 = null;
    $tenant->run(function () use (&$lotId1) {
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

        $userId = User::where('email', 'cellar_hand@example.com')->first()->id;

        FilterLog::create([
            'lot_id' => $lot1->id,
            'filter_type' => 'crossflow',
            'volume_processed_gallons' => 400,
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
        FilterLog::create([
            'lot_id' => $lot2->id,
            'filter_type' => 'pad',
            'volume_processed_gallons' => 250,
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
    });

    $response = test()->getJson("/api/v1/filter-logs?lot_id={$lotId1}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.filter_type'))->toBe('crossflow');
});

it('filters filter logs by has_fining flag', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-has-fining');

    $tenant->run(function () {
        $lot = Lot::create([
            'name' => 'Fining Filter Lot',
            'variety' => 'Riesling',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 400,
            'status' => 'in_progress',
        ]);

        $userId = User::where('email', 'cellar_hand@example.com')->first()->id;

        // One with fining
        FilterLog::create([
            'lot_id' => $lot->id,
            'filter_type' => 'pad',
            'volume_processed_gallons' => 350,
            'fining_agent' => 'Bentonite',
            'fining_rate' => 0.5,
            'fining_rate_unit' => 'g/L',
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
        // One without fining
        FilterLog::create([
            'lot_id' => $lot->id,
            'filter_type' => 'crossflow',
            'volume_processed_gallons' => 350,
            'performed_by' => $userId,
            'performed_at' => now()->subDay(),
        ]);
    });

    $response = test()->getJson('/api/v1/filter-logs?has_fining=1', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.fining_agent'))->toBe('Bentonite');
});

it('shows filter log detail with relationships', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-show');

    $filterLogId = null;
    $tenant->run(function () use (&$filterLogId) {
        $lot = Lot::create([
            'name' => 'Show Filter Lot',
            'variety' => 'Viognier',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 300,
            'status' => 'in_progress',
        ]);

        $vessel = Vessel::create([
            'name' => 'T-030',
            'type' => 'tank',
            'capacity_gallons' => 500,
            'status' => 'in_use',
        ]);

        $userId = User::where('email', 'cellar_hand@example.com')->first()->id;

        $filterLog = FilterLog::create([
            'lot_id' => $lot->id,
            'vessel_id' => $vessel->id,
            'filter_type' => 'lenticular',
            'filter_media' => 'Fine lenticular module',
            'flow_rate_lph' => 600,
            'volume_processed_gallons' => 280,
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
        $filterLogId = $filterLog->id;
    });

    $response = test()->getJson("/api/v1/filter-logs/{$filterLogId}", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();

    $data = $response->json('data');
    expect($data['id'])->toBe($filterLogId);
    expect($data['filter_type'])->toBe('lenticular');
    expect($data['lot'])->not->toBeNull();
    expect($data['lot']['name'])->toBe('Show Filter Lot');
    expect($data['vessel'])->not->toBeNull();
    expect($data['vessel']['name'])->toBe('T-030');
    expect($data['performed_by'])->not->toBeNull();
});

// ─── Tier 2: Validation ──────────────────────────────────────────

it('rejects filter log with missing required fields', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-val-req');

    $response = test()->postJson('/api/v1/filter-logs', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('lot_id');
    expect($fields)->toContain('filter_type');
    expect($fields)->toContain('volume_processed_gallons');
});

it('rejects invalid filter_type', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-val-type');
    $lotId = createFilterTestLot($tenant, 'Val Type Lot');

    $response = test()->postJson('/api/v1/filter-logs', [
        'lot_id' => $lotId,
        'filter_type' => 'magic_sieve',
        'volume_processed_gallons' => 300,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('filter_type');
});

it('rejects invalid fining_rate_unit', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-val-fru');
    $lotId = createFilterTestLot($tenant, 'Val FRU Lot');

    $response = test()->postJson('/api/v1/filter-logs', [
        'lot_id' => $lotId,
        'filter_type' => 'pad',
        'volume_processed_gallons' => 300,
        'fining_agent' => 'Bentonite',
        'fining_rate' => 0.5,
        'fining_rate_unit' => 'bushels/acre',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('fining_rate_unit');
});

// ─── Tier 2: RBAC ────────────────────────────────────────────────

it('cellar_hand can log filtering operations', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-rbac-ch');
    $lotId = createFilterTestLot($tenant, 'CH Filter Lot');

    test()->postJson('/api/v1/filter-logs', [
        'lot_id' => $lotId,
        'filter_type' => 'pad',
        'volume_processed_gallons' => 300,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);
});

it('read-only users cannot log filtering operations', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-rbac-ro', 'read_only');
    $lotId = createFilterTestLot($tenant, 'RO Filter Lot');

    test()->postJson('/api/v1/filter-logs', [
        'lot_id' => $lotId,
        'filter_type' => 'pad',
        'volume_processed_gallons' => 300,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('read-only users can list and view filter logs', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-rbac-ro-view', 'read_only');

    $filterLogId = null;
    $tenant->run(function () use (&$filterLogId) {
        $lot = Lot::create([
            'name' => 'RO View Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $userId = User::where('email', 'read_only@example.com')->first()->id;

        $filterLog = FilterLog::create([
            'lot_id' => $lot->id,
            'filter_type' => 'crossflow',
            'volume_processed_gallons' => 400,
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
        $filterLogId = $filterLog->id;
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    test()->getJson('/api/v1/filter-logs', $headers)->assertOk();
    test()->getJson("/api/v1/filter-logs/{$filterLogId}", $headers)->assertOk();
});

// ─── Tier 2: API Envelope & Auth ─────────────────────────────────

it('returns filter log responses in the standard API envelope format', function () {
    [$tenant, $token] = createFilterLogTestTenant('filter-env');
    $lotId = createFilterTestLot($tenant, 'Envelope Filter Lot');

    $response = test()->postJson('/api/v1/filter-logs', [
        'lot_id' => $lotId,
        'filter_type' => 'crossflow',
        'volume_processed_gallons' => 400,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'data' => ['id', 'lot_id', 'filter_type', 'volume_processed_gallons'],
        'meta',
        'errors',
    ]);
    expect($response->json('errors'))->toBe([]);
});

it('rejects unauthenticated access to filter logs', function () {
    test()->getJson('/api/v1/filter-logs', [
        'X-Tenant-ID' => 'fake-tenant',
    ])->assertStatus(401);
});
