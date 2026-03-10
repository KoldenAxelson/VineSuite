<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Models\WineryProfile;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with an owner, return [tenant, token].
 */
function createWineryTestTenant(string $slug = 'profile-winery'): array
{
    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'starter',
    ]);

    $tenant->run(function () {
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'SecurePass123!',
            'role' => 'owner',
            'is_active' => true,
        ]);
        $user->assignRole('owner');
    });

    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
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

// ─── Auto-Creation ──────────────────────────────────────────────

it('creates a winery profile automatically when tenant is provisioned', function () {
    $tenant = Tenant::create([
        'name' => 'Auto Profile Winery',
        'slug' => 'auto-profile',
        'plan' => 'starter',
    ]);

    $tenant->run(function () {
        $profile = WineryProfile::first();
        expect($profile)->not->toBeNull();
        expect($profile->name)->toBe('Auto Profile Winery');
        expect($profile->unit_system)->toBe('imperial');
        expect($profile->timezone)->toBe('America/Los_Angeles');
        expect($profile->country)->toBe('US');
        expect($profile->currency)->toBe('USD');
        expect($profile->fiscal_year_start_month)->toBe(1);
        expect($profile->onboarding_complete)->toBeFalse();
    });
});

// ─── GET /winery ────────────────────────────────────────────────

it('returns the winery profile for authenticated users', function () {
    [$tenant, $token] = createWineryTestTenant();

    $response = $this->getJson('/api/v1/winery', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Profile winery')
        ->assertJsonStructure([
            'data' => [
                'id', 'name', 'timezone', 'unit_system', 'currency',
                'fiscal_year_start_month', 'onboarding_complete',
            ],
        ]);
});

it('rejects unauthenticated access to winery profile', function () {
    [$tenant, $token] = createWineryTestTenant();

    $response = $this->getJson('/api/v1/winery', [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(401);
});

// ─── PUT /winery ────────────────────────────────────────────────

it('owner can update winery profile', function () {
    [$tenant, $token] = createWineryTestTenant();

    $response = $this->putJson('/api/v1/winery', [
        'name' => 'Updated Winery Name',
        'city' => 'Paso Robles',
        'state' => 'CA',
        'zip' => '93446',
        'timezone' => 'America/Los_Angeles',
        'ttb_permit_number' => 'BWC-CA-12345',
        'unit_system' => 'metric',
        'fiscal_year_start_month' => 7,
        'onboarding_complete' => true,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Winery Name')
        ->assertJsonPath('data.city', 'Paso Robles')
        ->assertJsonPath('data.state', 'CA')
        ->assertJsonPath('data.ttb_permit_number', 'BWC-CA-12345')
        ->assertJsonPath('data.unit_system', 'metric')
        ->assertJsonPath('data.fiscal_year_start_month', 7)
        ->assertJsonPath('data.onboarding_complete', true);
});

it('allows partial updates to winery profile', function () {
    [$tenant, $token] = createWineryTestTenant();

    // Just update the name
    $response = $this->putJson('/api/v1/winery', [
        'name' => 'Just The Name',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Just The Name')
        ->assertJsonPath('data.unit_system', 'imperial'); // unchanged
});

it('validates unit_system is imperial or metric', function () {
    [$tenant, $token] = createWineryTestTenant();

    $response = $this->putJson('/api/v1/winery', [
        'unit_system' => 'banana',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('unit_system');
});

it('validates fiscal_year_start_month is 1-12', function () {
    [$tenant, $token] = createWineryTestTenant();

    $response = $this->putJson('/api/v1/winery', [
        'fiscal_year_start_month' => 13,
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('fiscal_year_start_month');
});

it('validates timezone is a valid IANA timezone', function () {
    [$tenant, $token] = createWineryTestTenant();

    $response = $this->putJson('/api/v1/winery', [
        'timezone' => 'Not/A/Timezone',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('timezone');
});

// ─── RBAC ───────────────────────────────────────────────────────

it('non-admin users cannot update winery profile', function () {
    [$tenant, $ownerToken] = createWineryTestTenant();

    // Create a winemaker user
    $tenant->run(function () {
        $user = User::create([
            'name' => 'Winemaker',
            'email' => 'winemaker@example.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);
        $user->assignRole('winemaker');
    });

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'winemaker@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $winemakerToken = $loginResponse->json('data.token');

    // Winemaker CAN view profile
    $this->getJson('/api/v1/winery', [
        'Authorization' => "Bearer {$winemakerToken}",
        'X-Tenant-ID' => $tenant->id,
    ])->assertOk();

    // Winemaker CANNOT update profile
    $response = $this->putJson('/api/v1/winery', [
        'name' => 'Hacked Name',
    ], [
        'Authorization' => "Bearer {$winemakerToken}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(403);
});

// ─── Demo Seeder ────────────────────────────────────────────────

it('demo seeder creates paso robles cellars with all demo users', function () {
    // Run the demo seeder
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\DemoWinerySeeder']);

    $tenant = Tenant::where('slug', 'paso-robles-cellars')->first();
    expect($tenant)->not->toBeNull();
    expect($tenant->name)->toBe('Paso Robles Cellars');
    expect($tenant->plan)->toBe('growth');

    $tenant->run(function () {
        // Verify profile
        $profile = WineryProfile::first();
        expect($profile->name)->toBe('Paso Robles Cellars');
        expect($profile->city)->toBe('Paso Robles');
        expect($profile->state)->toBe('CA');
        expect($profile->ttb_permit_number)->toBe('BWC-CA-19847');
        expect($profile->fiscal_year_start_month)->toBe(7);
        expect($profile->onboarding_complete)->toBeTrue();

        // Verify 7 demo users (one per role)
        expect(User::count())->toBe(7);
        expect(User::where('role', 'owner')->count())->toBe(1);
        expect(User::where('role', 'admin')->count())->toBe(1);
        expect(User::where('role', 'winemaker')->count())->toBe(1);
        expect(User::where('role', 'cellar_hand')->count())->toBe(1);
        expect(User::where('role', 'tasting_room_staff')->count())->toBe(1);
        expect(User::where('role', 'accountant')->count())->toBe(1);
        expect(User::where('role', 'read_only')->count())->toBe(1);
    });
});

it('demo seeder is idempotent — running twice does not duplicate', function () {
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\DemoWinerySeeder']);
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\DemoWinerySeeder']);

    expect(Tenant::where('slug', 'paso-robles-cellars')->count())->toBe(1);
});
