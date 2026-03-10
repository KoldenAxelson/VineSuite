<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant and optionally run a callback in its context.
 */
function createTestTenant(string $slug = 'test-winery', ?Closure $callback = null): Tenant
{
    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'basic',
    ]);

    if ($callback) {
        $tenant->run($callback);
    }

    return $tenant;
}

afterEach(function () {
    // End tenancy to clear the tenant connection before next test's migrate:fresh
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

it('registers an owner and returns a Sanctum token', function () {
    $tenant = createTestTenant();

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Jane Owner',
        'email' => 'jane@example.com',
        'password' => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => ['token', 'user' => ['id', 'name', 'email', 'role']],
            'meta',
            'errors',
        ])
        ->assertJsonPath('data.user.role', 'owner')
        ->assertJsonPath('data.user.name', 'Jane Owner');
});

it('logs in with valid credentials and receives a token', function () {
    $tenant = createTestTenant('test-winery', function () {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);
        $user->assignRole('winemaker');
    });

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user'], 'meta', 'errors'])
        ->assertJsonPath('data.user.role', 'winemaker');
});

it('rejects login with invalid credentials', function () {
    $tenant = createTestTenant('test-winery', function () {
        User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);
    });

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'wrong-password',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);

    // Validation errors are now in the envelope errors array
    $errors = $response->json('errors');
    $fields = array_column($errors, 'field');
    expect($fields)->toContain('email');
});

it('rejects login for deactivated users', function () {
    $tenant = createTestTenant('test-winery', function () {
        User::create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'password' => 'SecurePass123!',
            'role' => 'cellar_hand',
            'is_active' => false,
        ]);
    });

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'inactive@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
});

it('accesses authenticated endpoint with valid token', function () {
    $tenant = createTestTenant('test-winery', function () {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
            'role' => 'owner',
            'is_active' => true,
        ]);
        $user->assignRole('owner');
    });

    // Login first
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $token = $loginResponse->json('data.token');

    // Access /auth/me with the token
    $meResponse = $this->getJson('/api/v1/auth/me', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $meResponse->assertOk()
        ->assertJsonPath('data.email', 'test@example.com')
        ->assertJsonPath('data.role', 'owner');
});

it('rejects unauthenticated access to protected endpoints', function () {
    $tenant = createTestTenant();

    $response = $this->getJson('/api/v1/auth/me', [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(401);
});

it('logs out and revokes the current token', function () {
    $tenant = createTestTenant('test-winery', function () {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
            'role' => 'owner',
            'is_active' => true,
        ]);
        $user->assignRole('owner');
    });

    // Login
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $token = $loginResponse->json('data.token');

    // Logout
    $logoutResponse = $this->postJson('/api/v1/auth/logout', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $logoutResponse->assertOk();

    // Reset auth guards so Sanctum re-resolves the token from DB
    app('auth')->forgetGuards();

    // Verify token is revoked
    $meResponse = $this->getJson('/api/v1/auth/me', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $meResponse->assertStatus(401);
});
