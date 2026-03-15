<?php

declare(strict_types=1);

use App\Models\FermentationEntry;
use App\Models\FermentationRound;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createChartTestTenant(string $slug = 'chart-winery', string $role = 'winemaker'): array
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

/*
 * Helper: create a lot inside the current tenant.
 */
function createChartLot(array $overrides = []): Lot
{
    return Lot::create(array_merge([
        'name' => 'Chart Test Lot',
        'variety' => 'Pinot Noir',
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => 300,
        'status' => 'in_progress',
    ], $overrides));
}

/*
 * Helper: create a round with daily entries.
 */
function createRoundWithEntries(Lot $lot, User $user, string $type = 'primary', array $entries = []): FermentationRound
{
    $round = FermentationRound::create([
        'lot_id' => $lot->id,
        'round_number' => 1,
        'fermentation_type' => $type,
        'inoculation_date' => '2024-09-15',
        'yeast_strain' => $type === 'primary' ? 'EC-1118' : null,
        'ml_bacteria' => $type === 'malolactic' ? 'VP41' : null,
        'target_temp' => $type === 'primary' ? 82.0 : 68.0,
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    if (empty($entries)) {
        // Default: realistic 7-day primary fermentation Brix curve
        $entries = [
            ['date' => '2024-09-16', 'temp' => 80.0, 'brix' => 24.5],
            ['date' => '2024-09-17', 'temp' => 82.0, 'brix' => 22.0],
            ['date' => '2024-09-18', 'temp' => 83.0, 'brix' => 18.5],
            ['date' => '2024-09-19', 'temp' => 81.0, 'brix' => 14.0],
            ['date' => '2024-09-20', 'temp' => 79.0, 'brix' => 8.5],
            ['date' => '2024-09-21', 'temp' => 77.0, 'brix' => 3.0],
            ['date' => '2024-09-22', 'temp' => 75.0, 'brix' => -1.0],
        ];
    }

    foreach ($entries as $entry) {
        FermentationEntry::create([
            'fermentation_round_id' => $round->id,
            'entry_date' => $entry['date'],
            'temperature' => $entry['temp'],
            'brix_or_density' => $entry['brix'],
            'measurement_type' => $entry['measurement_type'] ?? 'brix',
            'performed_by' => $user->id,
        ]);
    }

    return $round;
}

// ─── Tier 1: Chart Data Structure ──────────────────────────────

it('returns chart data in dual-axis format for a fermentation round', function () {
    [$tenant, $token] = createChartTestTenant('chart-dual-axis');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createChartLot();
        $user = User::first();
        $round = createRoundWithEntries($lot, $user);
        $roundId = $round->id;
    });

    $response = test()->getJson("/api/v1/fermentations/{$roundId}/chart", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'round' => ['id', 'lot_id', 'lot_name', 'lot_variety', 'fermentation_type', 'round_number', 'target_temp', 'status'],
            'series',
            'axes' => ['x', 'y_left', 'y_right'],
            'entry_count',
        ],
        'meta',
        'errors',
    ]);

    $data = $response->json('data');
    expect($data['entry_count'])->toBe(7);
    expect($data['axes']['x'])->toBe('date');
    expect($data['axes']['y_right'])->toBe('temperature_f');
    expect($data['round']['lot_name'])->toBe('Chart Test Lot');
    expect($data['round']['lot_variety'])->toBe('Pinot Noir');
    expect($data['round']['fermentation_type'])->toBe('primary');
});

it('returns series data with date, temperature, brix_or_density, and measurement_type', function () {
    [$tenant, $token] = createChartTestTenant('chart-series');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createChartLot();
        $user = User::first();
        $round = createRoundWithEntries($lot, $user);
        $roundId = $round->id;
    });

    $response = test()->getJson("/api/v1/fermentations/{$roundId}/chart", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    $series = $response->json('data.series');

    expect($series)->toHaveCount(7);

    // First entry
    expect($series[0]['date'])->toBe('2024-09-16');
    expect((float) $series[0]['temperature'])->toBe(80.0);
    expect((float) $series[0]['brix_or_density'])->toBe(24.5);
    expect($series[0]['measurement_type'])->toBe('brix');

    // Last entry (dry)
    expect($series[6]['date'])->toBe('2024-09-22');
    expect((float) $series[6]['brix_or_density'])->toBe(-1.0);
});

it('returns entries sorted chronologically', function () {
    [$tenant, $token] = createChartTestTenant('chart-sort');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createChartLot();
        $user = User::first();
        $round = FermentationRound::create([
            'lot_id' => $lot->id,
            'round_number' => 1,
            'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $roundId = $round->id;

        // Insert out of order
        FermentationEntry::create([
            'fermentation_round_id' => $round->id,
            'entry_date' => '2024-09-18',
            'temperature' => 82.0,
            'brix_or_density' => 18.0,
            'measurement_type' => 'brix',
            'performed_by' => $user->id,
        ]);
        FermentationEntry::create([
            'fermentation_round_id' => $round->id,
            'entry_date' => '2024-09-16',
            'temperature' => 80.0,
            'brix_or_density' => 24.5,
            'measurement_type' => 'brix',
            'performed_by' => $user->id,
        ]);
        FermentationEntry::create([
            'fermentation_round_id' => $round->id,
            'entry_date' => '2024-09-17',
            'temperature' => 81.0,
            'brix_or_density' => 22.0,
            'measurement_type' => 'brix',
            'performed_by' => $user->id,
        ]);
    });

    $response = test()->getJson("/api/v1/fermentations/{$roundId}/chart", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $series = $response->json('data.series');
    $dates = array_column($series, 'date');
    expect($dates)->toBe(['2024-09-16', '2024-09-17', '2024-09-18']);
});

it('resolves y_left axis label as brix when all entries are brix', function () {
    [$tenant, $token] = createChartTestTenant('chart-axis-brix');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createChartLot();
        $user = User::first();
        $round = createRoundWithEntries($lot, $user);
        $roundId = $round->id;
    });

    $response = test()->getJson("/api/v1/fermentations/{$roundId}/chart", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    expect($response->json('data.axes.y_left'))->toBe('brix');
});

it('resolves y_left axis label as specific_gravity when all entries use SG', function () {
    [$tenant, $token] = createChartTestTenant('chart-axis-sg');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createChartLot();
        $user = User::first();
        $round = createRoundWithEntries($lot, $user, 'primary', [
            ['date' => '2024-09-16', 'temp' => 80.0, 'brix' => 1.095, 'measurement_type' => 'specific_gravity'],
            ['date' => '2024-09-17', 'temp' => 82.0, 'brix' => 1.080, 'measurement_type' => 'specific_gravity'],
            ['date' => '2024-09-18', 'temp' => 83.0, 'brix' => 1.060, 'measurement_type' => 'specific_gravity'],
        ]);
        $roundId = $round->id;
    });

    $response = test()->getJson("/api/v1/fermentations/{$roundId}/chart", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    expect($response->json('data.axes.y_left'))->toBe('specific_gravity');
});

it('includes round metadata with lot_name and target_temp', function () {
    [$tenant, $token] = createChartTestTenant('chart-meta');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createChartLot(['name' => 'Estate Pinot 2024']);
        $user = User::first();
        $round = createRoundWithEntries($lot, $user);
        $roundId = $round->id;
    });

    $response = test()->getJson("/api/v1/fermentations/{$roundId}/chart", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $round = $response->json('data.round');
    expect($round['lot_name'])->toBe('Estate Pinot 2024');
    expect((float) $round['target_temp'])->toBe(82.0);
    expect($round['status'])->toBe('active');
});

it('returns empty series for a round with no entries', function () {
    [$tenant, $token] = createChartTestTenant('chart-empty');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createChartLot();
        $user = User::first();
        $round = FermentationRound::create([
            'lot_id' => $lot->id,
            'round_number' => 1,
            'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $roundId = $round->id;
    });

    $response = test()->getJson("/api/v1/fermentations/{$roundId}/chart", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect($response->json('data.series'))->toBe([]);
    expect($response->json('data.entry_count'))->toBe(0);
});

// ─── Tier 1: Lot Overview (multi-round overlay) ─────────────────

it('returns chart data for all rounds of a lot', function () {
    [$tenant, $token] = createChartTestTenant('chart-lot-overview');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        $lot = createChartLot(['name' => 'Multi-Round Lot']);
        $lotId = $lot->id;
        $user = User::first();

        // Primary round
        createRoundWithEntries($lot, $user, 'primary');

        // ML round
        $mlRound = FermentationRound::create([
            'lot_id' => $lot->id,
            'round_number' => 2,
            'fermentation_type' => 'malolactic',
            'inoculation_date' => '2024-10-01',
            'ml_bacteria' => 'VP41',
            'target_temp' => 68.0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        FermentationEntry::create([
            'fermentation_round_id' => $mlRound->id,
            'entry_date' => '2024-10-02',
            'temperature' => 65.0,
            'brix_or_density' => null,
            'performed_by' => $user->id,
        ]);
    });

    $response = test()->getJson("/api/v1/lots/{$lotId}/fermentation-chart", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'lot_id',
            'lot_name',
            'rounds' => [
                '*' => ['round_id', 'round_number', 'fermentation_type', 'status', 'label', 'series'],
            ],
        ],
        'meta',
        'errors',
    ]);

    $data = $response->json('data');
    expect($data['lot_name'])->toBe('Multi-Round Lot');
    expect($data['rounds'])->toHaveCount(2);
    expect($data['rounds'][0]['fermentation_type'])->toBe('primary');
    expect($data['rounds'][0]['series'])->toHaveCount(7);
    expect($data['rounds'][1]['fermentation_type'])->toBe('malolactic');
    expect($data['rounds'][1]['series'])->toHaveCount(1);
});

// ─── Tier 1: Tenant Isolation ──────────────────────────────────

it('prevents cross-tenant chart data access', function () {
    $tenantA = Tenant::create(['name' => 'Chart Alpha', 'slug' => 'chart-iso-a', 'plan' => 'pro']);
    $tenantB = Tenant::create(['name' => 'Chart Beta', 'slug' => 'chart-iso-b', 'plan' => 'pro']);

    $roundId = null;
    $tenantA->run(function () use (&$roundId) {
        $lot = createChartLot();
        $user = User::create([
            'name' => 'Alpha User', 'email' => 'a@example.com',
            'password' => 'SecurePass123!', 'role' => 'winemaker', 'is_active' => true,
        ]);
        $round = createRoundWithEntries($lot, $user);
        $roundId = $round->id;
    });

    // Tenant B user tries to access Tenant A's round chart
    $tenantB->run(function () {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::create([
            'name' => 'Beta User', 'email' => 'b@example.com',
            'password' => 'SecurePass123!', 'role' => 'winemaker', 'is_active' => true,
        ]);
        $user->assignRole('winemaker');
    });

    $loginB = test()->postJson('/api/v1/auth/login', [
        'email' => 'b@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenantB->id,
    ]);

    $tokenB = $loginB->json('data.token');

    // Should 404 — the round doesn't exist in Tenant B's schema
    test()->getJson("/api/v1/fermentations/{$roundId}/chart", [
        'Authorization' => "Bearer {$tokenB}",
        'X-Tenant-ID' => $tenantB->id,
    ])->assertStatus(404);
});

// ─── Tier 2: RBAC ──────────────────────────────────────────────

it('read_only users can access chart data', function () {
    [$tenant, $token] = createChartTestTenant('chart-rbac-ro', 'read_only');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createChartLot();
        $user = User::first();
        $round = createRoundWithEntries($lot, $user);
        $roundId = $round->id;
    });

    test()->getJson("/api/v1/fermentations/{$roundId}/chart", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();
});

it('unauthenticated request to chart endpoint is rejected', function () {
    test()->getJson('/api/v1/fermentations/fake-uuid/chart')
        ->assertStatus(401);
});

// ─── Tier 2: API Envelope ──────────────────────────────────────

it('returns chart data in the standard API envelope format', function () {
    [$tenant, $token] = createChartTestTenant('chart-envelope');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createChartLot();
        $user = User::first();
        $round = createRoundWithEntries($lot, $user);
        $roundId = $round->id;
    });

    $response = test()->getJson("/api/v1/fermentations/{$roundId}/chart", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['data', 'meta', 'errors']);
    expect($response->json('errors'))->toBe([]);
});

// ─── Tier 2: Null handling ─────────────────────────────────────

it('handles entries with null temperature or brix gracefully', function () {
    [$tenant, $token] = createChartTestTenant('chart-nulls');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createChartLot();
        $user = User::first();
        $round = FermentationRound::create([
            'lot_id' => $lot->id,
            'round_number' => 1,
            'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $roundId = $round->id;

        // Entry with only temperature (no brix)
        FermentationEntry::create([
            'fermentation_round_id' => $round->id,
            'entry_date' => '2024-09-16',
            'temperature' => 80.0,
            'brix_or_density' => null,
            'measurement_type' => null,
            'performed_by' => $user->id,
        ]);

        // Entry with only brix (no temperature)
        FermentationEntry::create([
            'fermentation_round_id' => $round->id,
            'entry_date' => '2024-09-17',
            'temperature' => null,
            'brix_or_density' => 22.0,
            'measurement_type' => 'brix',
            'performed_by' => $user->id,
        ]);
    });

    $response = test()->getJson("/api/v1/fermentations/{$roundId}/chart", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    $series = $response->json('data.series');
    expect($series)->toHaveCount(2);

    // First: temp set, brix null
    expect((float) $series[0]['temperature'])->toBe(80.0);
    expect($series[0]['brix_or_density'])->toBeNull();

    // Second: brix set, temp null
    expect($series[1]['temperature'])->toBeNull();
    expect((float) $series[1]['brix_or_density'])->toBe(22.0);
});

it('includes free_so2 in chart series data', function () {
    [$tenant, $token] = createChartTestTenant('chart-so2');

    $roundId = null;
    $tenant->run(function () use (&$roundId) {
        $lot = createChartLot();
        $user = User::first();
        $round = FermentationRound::create([
            'lot_id' => $lot->id,
            'round_number' => 1,
            'fermentation_type' => 'primary',
            'inoculation_date' => '2024-09-15',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $roundId = $round->id;

        FermentationEntry::create([
            'fermentation_round_id' => $round->id,
            'entry_date' => '2024-09-16',
            'temperature' => 80.0,
            'brix_or_density' => 24.5,
            'measurement_type' => 'brix',
            'free_so2' => 28.5,
            'performed_by' => $user->id,
        ]);
    });

    $response = test()->getJson("/api/v1/fermentations/{$roundId}/chart", [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $series = $response->json('data.series');
    expect((float) $series[0]['free_so2'])->toBe(28.5);
});
