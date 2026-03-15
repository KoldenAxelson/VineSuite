<?php

declare(strict_types=1);

use App\Models\LabAnalysis;
use App\Models\LabThreshold;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LabThresholdChecker;
use Database\Seeders\DefaultLabThresholdsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createThresholdTestTenant(string $slug = 'thresh-winery', string $role = 'winemaker'): array
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

// ─── Tier 1: Threshold Checking Logic ────────────────────────────

it('fires a critical alert when VA exceeds the legal limit', function () {
    [$tenant] = createThresholdTestTenant('thresh-va-crit');

    $tenant->run(function () {
        // Seed the VA critical threshold (0.12 g/100mL)
        LabThreshold::create([
            'test_type' => 'VA',
            'variety' => null,
            'min_value' => null,
            'max_value' => 0.12,
            'alert_level' => 'critical',
        ]);

        $lot = Lot::create([
            'name' => 'VA Test Lot',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $user = User::first();

        $analysis = LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'VA',
            'value' => 0.13,
            'unit' => 'g/100mL',
            'source' => 'manual',
            'performed_by' => $user->id,
        ]);

        $checker = app(LabThresholdChecker::class);
        $alerts = $checker->check($analysis);

        expect($alerts)->toHaveCount(1);
        expect($alerts[0]['alert_level'])->toBe('critical');
        expect($alerts[0]['test_type'])->toBe('VA');
        expect($alerts[0]['value'])->toBe(0.13);
        expect($alerts[0]['max_value'])->toBe(0.12);
        expect($alerts[0]['message'])->toContain('exceeds maximum');
    });
});

it('fires a warning alert when VA approaches the limit but not critical', function () {
    [$tenant] = createThresholdTestTenant('thresh-va-warn');

    $tenant->run(function () {
        LabThreshold::create([
            'test_type' => 'VA',
            'variety' => null,
            'min_value' => null,
            'max_value' => 0.10,
            'alert_level' => 'warning',
        ]);
        LabThreshold::create([
            'test_type' => 'VA',
            'variety' => null,
            'min_value' => null,
            'max_value' => 0.12,
            'alert_level' => 'critical',
        ]);

        $lot = Lot::create([
            'name' => 'VA Warn Lot',
            'variety' => 'Merlot',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $user = User::first();

        // 0.11 exceeds warning (0.10) but not critical (0.12)
        $analysis = LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'VA',
            'value' => 0.11,
            'unit' => 'g/100mL',
            'source' => 'manual',
            'performed_by' => $user->id,
        ]);

        $checker = app(LabThresholdChecker::class);
        $alerts = $checker->check($analysis);

        expect($alerts)->toHaveCount(1);
        expect($alerts[0]['alert_level'])->toBe('warning');
    });
});

it('fires both warning and critical alerts when value exceeds both thresholds', function () {
    [$tenant] = createThresholdTestTenant('thresh-va-both');

    $tenant->run(function () {
        LabThreshold::create([
            'test_type' => 'VA',
            'variety' => null,
            'min_value' => null,
            'max_value' => 0.10,
            'alert_level' => 'warning',
        ]);
        LabThreshold::create([
            'test_type' => 'VA',
            'variety' => null,
            'min_value' => null,
            'max_value' => 0.12,
            'alert_level' => 'critical',
        ]);

        $lot = Lot::create([
            'name' => 'VA Both Lot',
            'variety' => 'Syrah',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $user = User::first();

        // 0.15 exceeds both warning (0.10) and critical (0.12)
        $analysis = LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'VA',
            'value' => 0.15,
            'unit' => 'g/100mL',
            'source' => 'manual',
            'performed_by' => $user->id,
        ]);

        $checker = app(LabThresholdChecker::class);
        $alerts = $checker->check($analysis);

        expect($alerts)->toHaveCount(2);
        $levels = array_column($alerts, 'alert_level');
        expect($levels)->toContain('warning');
        expect($levels)->toContain('critical');
    });
});

it('returns no alerts when value is within all thresholds', function () {
    [$tenant] = createThresholdTestTenant('thresh-no-alert');

    $tenant->run(function () {
        LabThreshold::create([
            'test_type' => 'VA',
            'variety' => null,
            'min_value' => null,
            'max_value' => 0.10,
            'alert_level' => 'warning',
        ]);

        $lot = Lot::create([
            'name' => 'Safe Lot',
            'variety' => 'Pinot Noir',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $user = User::first();

        $analysis = LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'VA',
            'value' => 0.05,
            'unit' => 'g/100mL',
            'source' => 'manual',
            'performed_by' => $user->id,
        ]);

        $checker = app(LabThresholdChecker::class);
        $alerts = $checker->check($analysis);

        expect($alerts)->toBeEmpty();
    });
});

it('fires alert when value falls below minimum threshold', function () {
    [$tenant] = createThresholdTestTenant('thresh-below-min');

    $tenant->run(function () {
        LabThreshold::create([
            'test_type' => 'free_SO2',
            'variety' => null,
            'min_value' => 15.0,
            'max_value' => 50.0,
            'alert_level' => 'warning',
        ]);

        $lot = Lot::create([
            'name' => 'Low SO2 Lot',
            'variety' => 'Chardonnay',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 300,
            'status' => 'in_progress',
        ]);

        $user = User::first();

        // 8 mg/L is below the 15 mg/L minimum
        $analysis = LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'free_SO2',
            'value' => 8.0,
            'unit' => 'mg/L',
            'source' => 'manual',
            'performed_by' => $user->id,
        ]);

        $checker = app(LabThresholdChecker::class);
        $alerts = $checker->check($analysis);

        expect($alerts)->toHaveCount(1);
        expect($alerts[0]['alert_level'])->toBe('warning');
        expect($alerts[0]['message'])->toContain('below minimum');
    });
});

it('uses variety-specific threshold over global when both exist', function () {
    [$tenant] = createThresholdTestTenant('thresh-variety');

    $tenant->run(function () {
        // Global pH threshold: 2.9 – 3.8
        LabThreshold::create([
            'test_type' => 'pH',
            'variety' => null,
            'min_value' => 2.9,
            'max_value' => 3.8,
            'alert_level' => 'warning',
        ]);

        // Variety-specific for Riesling: 2.9 – 3.4 (tighter)
        LabThreshold::create([
            'test_type' => 'pH',
            'variety' => 'Riesling',
            'min_value' => 2.9,
            'max_value' => 3.4,
            'alert_level' => 'warning',
        ]);

        $lot = Lot::create([
            'name' => 'Riesling Lot',
            'variety' => 'Riesling',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 400,
            'status' => 'in_progress',
        ]);

        $user = User::first();

        // pH 3.6 — within global (3.8 max) but exceeds Riesling-specific (3.4 max)
        $analysis = LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'pH',
            'value' => 3.6,
            'unit' => 'pH',
            'source' => 'manual',
            'performed_by' => $user->id,
        ]);

        $checker = app(LabThresholdChecker::class);
        $alerts = $checker->check($analysis);

        expect($alerts)->toHaveCount(1);
        expect($alerts[0]['alert_level'])->toBe('warning');
        expect($alerts[0]['variety'])->toBe('Riesling');
        expect($alerts[0]['message'])->toContain('for Riesling');
    });
});

it('returns no alerts when no thresholds are configured for the test type', function () {
    [$tenant] = createThresholdTestTenant('thresh-none');

    $tenant->run(function () {
        // No thresholds at all
        $lot = Lot::create([
            'name' => 'No Thresh Lot',
            'variety' => 'Malbec',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $user = User::first();

        $analysis = LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'color',
            'value' => 999.0,
            'unit' => 'AU',
            'source' => 'manual',
            'performed_by' => $user->id,
        ]);

        $checker = app(LabThresholdChecker::class);
        $alerts = $checker->check($analysis);

        expect($alerts)->toBeEmpty();
    });
});

// ─── Tier 1: Integration — Alerts in API Response ────────────────

it('includes threshold alerts in the lab analysis creation response', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-api');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        LabThreshold::create([
            'test_type' => 'VA',
            'variety' => null,
            'min_value' => null,
            'max_value' => 0.10,
            'alert_level' => 'warning',
        ]);

        $lot = Lot::create([
            'name' => 'API Alert Lot',
            'variety' => 'Zinfandel',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 800,
            'status' => 'in_progress',
        ]);
        $lotId = $lot->id;
    });

    $response = test()->postJson("/api/v1/lots/{$lotId}/analyses", [
        'lot_id' => $lotId,
        'test_date' => '2024-10-15',
        'test_type' => 'VA',
        'value' => 0.11,
        'unit' => 'g/100mL',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['threshold_alerts'])->toHaveCount(1);
    expect($data['threshold_alerts'][0]['alert_level'])->toBe('warning');
    expect($data['threshold_alerts'][0]['test_type'])->toBe('VA');
});

it('returns empty threshold_alerts when value is within range', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-api-ok');

    $lotId = null;
    $tenant->run(function () use (&$lotId) {
        LabThreshold::create([
            'test_type' => 'pH',
            'variety' => null,
            'min_value' => 2.9,
            'max_value' => 3.8,
            'alert_level' => 'warning',
        ]);

        $lot = Lot::create([
            'name' => 'Safe API Lot',
            'variety' => 'Pinot Grigio',
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
    expect($response->json('data.threshold_alerts'))->toBeEmpty();
});

// ─── Tier 1: VA at Exact Legal Limit ─────────────────────────────

it('does not fire critical alert when VA is exactly at the limit', function () {
    [$tenant] = createThresholdTestTenant('thresh-va-exact');

    $tenant->run(function () {
        LabThreshold::create([
            'test_type' => 'VA',
            'variety' => null,
            'min_value' => null,
            'max_value' => 0.12,
            'alert_level' => 'critical',
        ]);

        $lot = Lot::create([
            'name' => 'Exact Limit Lot',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $user = User::first();

        // Exactly 0.12 — at the limit, not exceeding it
        $analysis = LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'VA',
            'value' => 0.12,
            'unit' => 'g/100mL',
            'source' => 'manual',
            'performed_by' => $user->id,
        ]);

        $checker = app(LabThresholdChecker::class);
        $alerts = $checker->check($analysis);

        expect($alerts)->toBeEmpty();
    });
});

it('fires critical alert when VA is just above the limit', function () {
    [$tenant] = createThresholdTestTenant('thresh-va-just-above');

    $tenant->run(function () {
        LabThreshold::create([
            'test_type' => 'VA',
            'variety' => null,
            'min_value' => null,
            'max_value' => 0.12,
            'alert_level' => 'critical',
        ]);

        $lot = Lot::create([
            'name' => 'Just Above Lot',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'source_type' => 'estate',
            'volume_gallons' => 500,
            'status' => 'in_progress',
        ]);

        $user = User::first();

        // 0.121 — just above the limit
        $analysis = LabAnalysis::create([
            'lot_id' => $lot->id,
            'test_date' => '2024-10-15',
            'test_type' => 'VA',
            'value' => 0.121,
            'unit' => 'g/100mL',
            'source' => 'manual',
            'performed_by' => $user->id,
        ]);

        $checker = app(LabThresholdChecker::class);
        $alerts = $checker->check($analysis);

        expect($alerts)->toHaveCount(1);
        expect($alerts[0]['alert_level'])->toBe('critical');
    });
});

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

it('prevents cross-tenant threshold data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Winery Alpha',
        'slug' => 'thresh-iso-alpha',
        'plan' => 'pro',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Winery Beta',
        'slug' => 'thresh-iso-beta',
        'plan' => 'pro',
    ]);

    $tenantA->run(function () {
        LabThreshold::create([
            'test_type' => 'VA',
            'variety' => null,
            'min_value' => null,
            'max_value' => 0.12,
            'alert_level' => 'critical',
        ]);
    });

    $tenantB->run(function () {
        expect(LabThreshold::count())->toBe(0);
    });

    $tenantA->run(function () {
        expect(LabThreshold::count())->toBe(1);
    });
});

// ─── Tier 2: Threshold CRUD API ──────────────────────────────────

it('creates a lab threshold via API', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-crud');

    $response = test()->postJson('/api/v1/lab-thresholds', [
        'test_type' => 'pH',
        'variety' => null,
        'min_value' => 2.9,
        'max_value' => 3.8,
        'alert_level' => 'warning',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['test_type'])->toBe('pH');
    expect($data['min_value'])->toBe(2.9);
    expect($data['max_value'])->toBe(3.8);
    expect($data['alert_level'])->toBe('warning');
    expect($data['variety'])->toBeNull();
});

it('lists lab thresholds with filtering', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-list');

    $tenant->run(function () {
        LabThreshold::create(['test_type' => 'pH', 'min_value' => 2.9, 'max_value' => 3.8, 'alert_level' => 'warning']);
        LabThreshold::create(['test_type' => 'VA', 'max_value' => 0.12, 'alert_level' => 'critical']);
        LabThreshold::create(['test_type' => 'TA', 'min_value' => 5.0, 'max_value' => 8.5, 'alert_level' => 'warning']);
    });

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // List all
    $response = test()->getJson('/api/v1/lab-thresholds', $headers);
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);

    // Filter by test_type
    $response = test()->getJson('/api/v1/lab-thresholds?test_type=VA', $headers);
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.test_type'))->toBe('VA');
});

it('updates a lab threshold via API', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-update');

    $thresholdId = null;
    $tenant->run(function () use (&$thresholdId) {
        $threshold = LabThreshold::create([
            'test_type' => 'pH',
            'min_value' => 2.9,
            'max_value' => 3.8,
            'alert_level' => 'warning',
        ]);
        $thresholdId = $threshold->id;
    });

    $response = test()->putJson("/api/v1/lab-thresholds/{$thresholdId}", [
        'max_value' => 4.0,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk();
    expect((float) $response->json('data.max_value'))->toBe(4.0);
});

it('deletes a lab threshold via API', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-delete');

    $thresholdId = null;
    $tenant->run(function () use (&$thresholdId) {
        $threshold = LabThreshold::create([
            'test_type' => 'pH',
            'min_value' => 2.9,
            'max_value' => 3.8,
            'alert_level' => 'warning',
        ]);
        $thresholdId = $threshold->id;
    });

    test()->deleteJson("/api/v1/lab-thresholds/{$thresholdId}", [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();

    $tenant->run(function () {
        expect(LabThreshold::count())->toBe(0);
    });
});

// ─── Tier 2: Validation ──────────────────────────────────────────

it('rejects threshold with invalid test_type', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-val-type');

    $response = test()->postJson('/api/v1/lab-thresholds', [
        'test_type' => 'magic',
        'max_value' => 10.0,
        'alert_level' => 'warning',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('test_type');
});

it('rejects threshold with invalid alert_level', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-val-level');

    $response = test()->postJson('/api/v1/lab-thresholds', [
        'test_type' => 'pH',
        'max_value' => 4.0,
        'alert_level' => 'panic',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('alert_level');
});

// ─── Tier 2: RBAC ────────────────────────────────────────────────

it('winemaker can manage thresholds', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-rbac-wm');

    test()->postJson('/api/v1/lab-thresholds', [
        'test_type' => 'pH',
        'min_value' => 2.9,
        'max_value' => 3.8,
        'alert_level' => 'warning',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(201);
});

it('cellar_hand cannot create thresholds', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-rbac-ch', 'cellar_hand');

    test()->postJson('/api/v1/lab-thresholds', [
        'test_type' => 'pH',
        'min_value' => 2.9,
        'max_value' => 3.8,
        'alert_level' => 'warning',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

it('cellar_hand can view thresholds', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-rbac-ch-view', 'cellar_hand');

    $tenant->run(function () {
        LabThreshold::create([
            'test_type' => 'VA',
            'max_value' => 0.12,
            'alert_level' => 'critical',
        ]);
    });

    test()->getJson('/api/v1/lab-thresholds', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();
});

it('read_only users cannot create thresholds', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-rbac-ro', 'read_only');

    test()->postJson('/api/v1/lab-thresholds', [
        'test_type' => 'pH',
        'max_value' => 3.8,
        'alert_level' => 'warning',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertStatus(403);
});

// ─── Tier 2: Default Seeder ──────────────────────────────────────

it('seeds default thresholds with expected values', function () {
    [$tenant] = createThresholdTestTenant('thresh-seeder');

    $tenant->run(function () {
        app(DefaultLabThresholdsSeeder::class)->run();

        // VA thresholds exist
        $vaWarning = LabThreshold::where('test_type', 'VA')->where('alert_level', 'warning')->first();
        $vaCritical = LabThreshold::where('test_type', 'VA')->where('alert_level', 'critical')->first();

        expect($vaWarning)->not->toBeNull();
        expect((float) $vaWarning->max_value)->toBe(0.10);
        expect($vaCritical)->not->toBeNull();
        expect((float) $vaCritical->max_value)->toBe(0.12);

        // pH thresholds exist
        $phWarning = LabThreshold::where('test_type', 'pH')->where('alert_level', 'warning')->first();
        expect($phWarning)->not->toBeNull();
        expect((float) $phWarning->min_value)->toBe(2.9);
        expect((float) $phWarning->max_value)->toBe(3.8);

        // Free SO2 thresholds exist
        $so2Warning = LabThreshold::where('test_type', 'free_SO2')->where('alert_level', 'warning')->first();
        expect($so2Warning)->not->toBeNull();
        expect((float) $so2Warning->min_value)->toBe(15.0);

        // Total count: should be at least 15 defaults
        expect(LabThreshold::count())->toBeGreaterThanOrEqual(15);
    });
});

it('seeder is idempotent — running twice does not create duplicates', function () {
    [$tenant] = createThresholdTestTenant('thresh-seeder-idem');

    $tenant->run(function () {
        app(DefaultLabThresholdsSeeder::class)->run();
        $countAfterFirst = LabThreshold::count();

        app(DefaultLabThresholdsSeeder::class)->run();
        $countAfterSecond = LabThreshold::count();

        expect($countAfterSecond)->toBe($countAfterFirst);
    });
});

// ─── Tier 2: API Envelope ────────────────────────────────────────

it('returns threshold responses in the standard API envelope format', function () {
    [$tenant, $token] = createThresholdTestTenant('thresh-env');

    $response = test()->postJson('/api/v1/lab-thresholds', [
        'test_type' => 'pH',
        'min_value' => 2.9,
        'max_value' => 3.8,
        'alert_level' => 'warning',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'data' => ['id', 'test_type', 'min_value', 'max_value', 'alert_level'],
        'meta',
        'errors',
    ]);
    expect($response->json('errors'))->toBe([]);
});
