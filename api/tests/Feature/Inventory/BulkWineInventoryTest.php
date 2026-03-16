<?php

declare(strict_types=1);

use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user and return [tenant, token].
 */
function createBulkWineTestTenant(string $slug = 'bw-winery', string $role = 'admin'): array
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

/*
 * Helper: seed lots, vessels, and lot_vessel pivot for testing.
 */
function seedBulkWineData(Tenant $tenant): array
{
    $data = [];

    $tenant->run(function () use (&$data) {
        $lot1 = Lot::factory()->create([
            'name' => 'Cab Sauv Block A',
            'variety' => 'Cabernet Sauvignon',
            'vintage' => 2024,
            'status' => 'aging',
            'volume_gallons' => 500.0,
        ]);

        $lot2 = Lot::factory()->create([
            'name' => 'Chardonnay Reserve',
            'variety' => 'Chardonnay',
            'vintage' => 2025,
            'status' => 'in_progress',
            'volume_gallons' => 300.0,
        ]);

        $lot3 = Lot::factory()->create([
            'name' => 'Finished Merlot',
            'variety' => 'Merlot',
            'vintage' => 2023,
            'status' => 'finished',
            'volume_gallons' => 200.0,
        ]);

        $vessel1 = Vessel::factory()->create([
            'name' => 'Tank T1',
            'type' => 'tank',
            'capacity_gallons' => 600.0,
            'location' => 'Barrel Room A',
            'status' => 'in_use',
        ]);

        $vessel2 = Vessel::factory()->create([
            'name' => 'Barrel B1',
            'type' => 'barrel',
            'capacity_gallons' => 60.0,
            'location' => 'Barrel Room A',
            'status' => 'in_use',
        ]);

        $vessel3 = Vessel::factory()->create([
            'name' => 'Tank T2',
            'type' => 'tank',
            'capacity_gallons' => 500.0,
            'location' => 'Fermentation Hall',
            'status' => 'in_use',
        ]);

        $vessel4 = Vessel::factory()->create([
            'name' => 'Empty Tank',
            'type' => 'tank',
            'capacity_gallons' => 800.0,
            'location' => 'Fermentation Hall',
            'status' => 'available',
        ]);

        // Lot 1 (aging, 500 gal book) → 450 gal in vessel1, 40 gal in vessel2 = 490 vessel (10 gal variance)
        DB::table('lot_vessel')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'lot_id' => $lot1->id,
            'vessel_id' => $vessel1->id,
            'volume_gallons' => 450.0,
            'filled_at' => now()->subMonths(6),
            'emptied_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lot_vessel')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'lot_id' => $lot1->id,
            'vessel_id' => $vessel2->id,
            'volume_gallons' => 40.0,
            'filled_at' => now()->subMonths(6),
            'emptied_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Lot 2 (in_progress, 300 gal book) → 300 gal in vessel3 = 300 vessel (0 variance)
        DB::table('lot_vessel')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'lot_id' => $lot2->id,
            'vessel_id' => $vessel3->id,
            'volume_gallons' => 300.0,
            'filled_at' => now()->subWeeks(2),
            'emptied_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // An emptied record (should be excluded from current contents)
        DB::table('lot_vessel')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'lot_id' => $lot1->id,
            'vessel_id' => $vessel3->id,
            'volume_gallons' => 100.0,
            'filled_at' => now()->subYear(),
            'emptied_at' => now()->subMonths(6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = [
            'lot1' => $lot1,
            'lot2' => $lot2,
            'lot3' => $lot3,
            'vessel1' => $vessel1,
            'vessel2' => $vessel2,
            'vessel3' => $vessel3,
            'vessel4' => $vessel4,
        ];
    });

    return $data;
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

// ─── Summary Endpoint ──────────────────────────────────────────

describe('bulk wine summary', function () {
    it('returns aggregate totals for active bulk wine', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-summary');
        seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/summary', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $data = $response->json('data');

        // Vessel volume: 450 + 40 + 300 = 790 (emptied record excluded)
        expect((float) $data['total_gallons_in_vessels'])->toEqual(790.0);

        // Book value: lot1(500) + lot2(300) = 800 (lot3 is "finished" — excluded)
        expect((float) $data['total_gallons_book_value'])->toEqual(800.0);

        // Variance: 800 - 790 = 10
        expect((float) $data['variance_gallons'])->toEqual(10.0);

        // 2 active lots (in_progress + aging), finished lot excluded
        expect($data['active_lot_count'])->toBe(2);

        // 3 active vessels (with current contents)
        expect($data['active_vessel_count'])->toBe(3);
    })->group('inventory');

    it('returns zeros when no bulk wine exists', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-empty');

        $response = test()->getJson('/api/v1/bulk-wine/summary', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $data = $response->json('data');

        expect((float) $data['total_gallons_in_vessels'])->toEqual(0.0);
        expect((float) $data['total_gallons_book_value'])->toEqual(0.0);
        expect($data['active_lot_count'])->toBe(0);
        expect($data['active_vessel_count'])->toBe(0);
    })->group('inventory');
})->group('inventory');

// ─── By Lot Endpoint ───────────────────────────────────────────

describe('bulk wine by lot', function () {
    it('returns per-lot breakdown with vessel volumes', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-bylot');
        $data = seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/by-lot', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $lots = $response->json('data');

        // Should have 2 active lots (finished lot excluded)
        expect($lots)->toHaveCount(2);

        // Find the aging lot (Cab Sauv Block A)
        $cabSauv = collect($lots)->firstWhere('lot_name', 'Cab Sauv Block A');
        expect($cabSauv)->not->toBeNull();
        expect((float) $cabSauv['book_volume'])->toEqual(500.0);
        expect((float) $cabSauv['vessel_volume'])->toEqual(490.0);
        expect((float) $cabSauv['variance'])->toEqual(10.0);
        expect($cabSauv['vessel_count'])->toBe(2);

        // Chardonnay Reserve (no variance)
        $chard = collect($lots)->firstWhere('lot_name', 'Chardonnay Reserve');
        expect($chard)->not->toBeNull();
        expect((float) $chard['book_volume'])->toEqual(300.0);
        expect((float) $chard['vessel_volume'])->toEqual(300.0);
        expect((float) $chard['variance'])->toEqual(0.0);
    })->group('inventory');

    it('filters by vintage', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-bylot-v');
        seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/by-lot?vintage=2024', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $lots = $response->json('data');
        expect($lots)->toHaveCount(1);
        expect($lots[0]['vintage'])->toBe(2024);
    })->group('inventory');

    it('filters by variety', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-bylot-var');
        seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/by-lot?variety=Chardonnay', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $lots = $response->json('data');
        expect($lots)->toHaveCount(1);
        expect($lots[0]['lot_name'])->toBe('Chardonnay Reserve');
    })->group('inventory');

    it('filters by status', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-bylot-st');
        seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/by-lot?status=aging', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $lots = $response->json('data');
        expect($lots)->toHaveCount(1);
        expect($lots[0]['lot_status'])->toBe('aging');
    })->group('inventory');
})->group('inventory');

// ─── By Vessel Endpoint ────────────────────────────────────────

describe('bulk wine by vessel', function () {
    it('returns per-vessel breakdown with current contents', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-byvessel');
        $data = seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/by-vessel', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $vessels = $response->json('data');

        // All 4 vessels returned (including empty)
        expect($vessels)->toHaveCount(4);

        // Tank T1: 450 gal of 600 capacity
        $t1 = collect($vessels)->firstWhere('vessel_name', 'Tank T1');
        expect($t1)->not->toBeNull();
        expect((float) $t1['current_volume'])->toEqual(450.0);
        expect((float) $t1['capacity_gallons'])->toEqual(600.0);
        expect((float) $t1['available_capacity'])->toEqual(150.0);
        expect((float) $t1['fill_percentage'])->toEqual(75.0);
        expect($t1['lot_count'])->toBe(1);

        // Empty Tank: 0 current
        $empty = collect($vessels)->firstWhere('vessel_name', 'Empty Tank');
        expect($empty)->not->toBeNull();
        expect((float) $empty['current_volume'])->toEqual(0.0);
        expect((float) $empty['available_capacity'])->toEqual(800.0);
        expect((float) $empty['fill_percentage'])->toEqual(0.0);
        expect($empty['lot_count'])->toBe(0);
    })->group('inventory');

    it('filters by vessel type', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-byvessel-t');
        seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/by-vessel?vessel_type=barrel', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $vessels = $response->json('data');
        expect($vessels)->toHaveCount(1);
        expect($vessels[0]['vessel_type'])->toBe('barrel');
    })->group('inventory');

    it('filters by location', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-byvessel-l');
        seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/by-vessel?location=Barrel+Room', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $vessels = $response->json('data');
        expect($vessels)->toHaveCount(2); // Tank T1 + Barrel B1
    })->group('inventory');

    it('filters occupied vessels only', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-byvessel-o');
        seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/by-vessel?occupied_only=1', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $vessels = $response->json('data');
        // Only vessels with wine: T1, B1, T2 (not Empty Tank)
        expect($vessels)->toHaveCount(3);
    })->group('inventory');
})->group('inventory');

// ─── By Location Endpoint ──────────────────────────────────────

describe('bulk wine by location', function () {
    it('aggregates volume by vessel location', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-byloc');
        seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/by-location', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $locations = $response->json('data');

        // 2 locations: Barrel Room A, Fermentation Hall
        expect($locations)->toHaveCount(2);

        $barrelRoom = collect($locations)->firstWhere('location', 'Barrel Room A');
        expect($barrelRoom)->not->toBeNull();
        expect($barrelRoom['vessel_count'])->toBe(2); // T1, B1
        expect((float) $barrelRoom['total_volume'])->toEqual(490.0); // 450 + 40
        expect((float) $barrelRoom['total_capacity'])->toEqual(660.0); // 600 + 60

        $fermHall = collect($locations)->firstWhere('location', 'Fermentation Hall');
        expect($fermHall)->not->toBeNull();
        expect($fermHall['vessel_count'])->toBe(2); // T2, Empty Tank
        expect((float) $fermHall['total_volume'])->toEqual(300.0); // 300 + 0
        expect((float) $fermHall['total_capacity'])->toEqual(1300.0); // 500 + 800
    })->group('inventory');
})->group('inventory');

// ─── Reconciliation Endpoint ───────────────────────────────────

describe('bulk wine reconciliation', function () {
    it('returns only lots with variance between book and vessel volumes', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-recon');
        seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/reconciliation', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $results = $response->json('data');

        // Only lot1 has variance (500 book vs 490 vessel = 10 gal variance)
        // Lot2 has no variance (300 vs 300)
        expect($results)->toHaveCount(1);
        expect($results[0]['lot_name'])->toBe('Cab Sauv Block A');
        expect((float) $results[0]['variance'])->toEqual(10.0);
        expect((float) $results[0]['variance_percentage'])->toEqual(2.0);
    })->group('inventory');
})->group('inventory');

// ─── Aging Schedule Endpoint ───────────────────────────────────

describe('bulk wine aging schedule', function () {
    it('returns lots currently aging with fill dates and aging days', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-aging');
        seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/aging-schedule', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $results = $response->json('data');

        // Only lot1 is aging
        expect($results)->toHaveCount(1);
        expect($results[0]['lot_name'])->toBe('Cab Sauv Block A');
        expect($results[0]['variety'])->toBe('Cabernet Sauvignon');
        expect($results[0]['vintage'])->toBe(2024);
        expect($results[0]['vessel_count'])->toBe(2);
        expect($results[0]['aging_days'])->toBeGreaterThan(0);
        expect($results[0]['earliest_fill_date'])->not->toBeNull();
    })->group('inventory');

    it('filters aging schedule by vintage', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-aging-v');
        seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/aging-schedule?vintage=2099', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(0);
    })->group('inventory');

    it('filters aging schedule by variety', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-aging-var');
        seedBulkWineData($tenant);

        $response = test()->getJson('/api/v1/bulk-wine/aging-schedule?variety=Cabernet', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $results = $response->json('data');
        expect($results)->toHaveCount(1);
        expect($results[0]['variety'])->toBe('Cabernet Sauvignon');
    })->group('inventory');
})->group('inventory');

// ─── RBAC ──────────────────────────────────────────────────────

describe('bulk wine RBAC', function () {
    it('requires authentication for all endpoints', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-auth');

        $endpoints = [
            '/api/v1/bulk-wine/summary',
            '/api/v1/bulk-wine/by-lot',
            '/api/v1/bulk-wine/by-vessel',
            '/api/v1/bulk-wine/by-location',
            '/api/v1/bulk-wine/reconciliation',
            '/api/v1/bulk-wine/aging-schedule',
        ];

        foreach ($endpoints as $endpoint) {
            $response = test()->getJson($endpoint, [
                'X-Tenant-ID' => $tenant->id,
            ]);

            $response->assertUnauthorized();
        }
    })->group('inventory');

    it('allows read_only role to access bulk wine data', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-role-ro', 'read_only');

        $response = test()->getJson('/api/v1/bulk-wine/summary', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
    })->group('inventory');
})->group('inventory');

// ─── API Envelope ──────────────────────────────────────────────

describe('bulk wine API envelope', function () {
    it('wraps responses in the standard envelope', function () {
        [$tenant, $token] = createBulkWineTestTenant('bw-envelope');

        $response = test()->getJson('/api/v1/bulk-wine/summary', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta',
            ]);
    })->group('inventory');
})->group('inventory');

// ─── Tenant Isolation ──────────────────────────────────────────

describe('bulk wine tenant isolation', function () {
    it('does not leak data across tenants', function () {
        $tenantA = Tenant::create([
            'name' => 'Winery Alpha',
            'slug' => 'bw-iso-alpha',
            'plan' => 'pro',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Winery Beta',
            'slug' => 'bw-iso-beta',
            'plan' => 'pro',
        ]);

        // Seed lots in tenant A only
        $tenantA->run(function () {
            Lot::factory()->create([
                'name' => 'Alpha Lot',
                'status' => 'aging',
                'volume_gallons' => 100.0,
            ]);
        });

        // Tenant B should see zero lots
        $tenantB->run(function () {
            expect(Lot::whereIn('status', ['in_progress', 'aging'])->count())->toBe(0);
        });

        // Tenant A should see its lot
        $tenantA->run(function () {
            expect(Lot::whereIn('status', ['in_progress', 'aging'])->count())->toBe(1);
        });
    })->group('inventory');
})->group('inventory');
