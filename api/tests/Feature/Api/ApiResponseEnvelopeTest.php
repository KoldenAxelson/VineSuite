<?php

declare(strict_types=1);

use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant and optionally run a callback in its context.
 */
function createApiTestTenant(string $slug = 'envelope-winery', ?Closure $callback = null): Tenant
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

// ─── ApiResponse helper unit tests ──────────────────────────────

it('returns success envelope with data', function () {
    $response = ApiResponse::success(['foo' => 'bar']);

    $json = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($json)->toHaveKeys(['data', 'meta', 'errors'])
        ->and($json['data'])->toBe(['foo' => 'bar'])
        ->and($json['errors'])->toBe([]);
});

it('returns created envelope with 201 status', function () {
    $response = ApiResponse::created(['id' => 1]);

    $json = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($json['data'])->toBe(['id' => 1])
        ->and($json['errors'])->toBe([]);
});

it('returns message envelope with message in meta', function () {
    $response = ApiResponse::message('Done.');

    $json = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($json['data'])->toBeNull()
        ->and($json['meta']['message'])->toBe('Done.')
        ->and($json['errors'])->toBe([]);
});

it('returns error envelope with string error', function () {
    $response = ApiResponse::error('Not found.', 404);

    $json = $response->getData(true);

    expect($response->getStatusCode())->toBe(404)
        ->and($json['data'])->toBeNull()
        ->and($json['errors'])->toHaveCount(1)
        ->and($json['errors'][0]['message'])->toBe('Not found.');
});

it('returns validation error envelope with field-level details', function () {
    $response = ApiResponse::validationError([
        'email' => ['The email field is required.', 'The email must be valid.'],
        'name' => ['The name field is required.'],
    ]);

    $json = $response->getData(true);

    expect($response->getStatusCode())->toBe(422)
        ->and($json['data'])->toBeNull()
        ->and($json['errors'])->toHaveCount(3)
        ->and($json['errors'][0])->toBe(['field' => 'email', 'message' => 'The email field is required.'])
        ->and($json['errors'][1])->toBe(['field' => 'email', 'message' => 'The email must be valid.'])
        ->and($json['errors'][2])->toBe(['field' => 'name', 'message' => 'The name field is required.']);
});

it('includes meta data in success response', function () {
    $response = ApiResponse::success(['items' => []], meta: ['page' => 1, 'total' => 50]);

    $json = $response->getData(true);

    expect($json['meta']['page'])->toBe(1)
        ->and($json['meta']['total'])->toBe(50);
});

// ─── Integration tests: real HTTP requests ──────────────────────

it('wraps health check in envelope', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => ['status', 'timestamp'],
            'meta',
            'errors',
        ])
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('errors', []);
});

it('wraps validation errors in envelope with field details', function () {
    $tenant = createApiTestTenant();

    $response = $this->postJson('/api/v1/auth/login', [], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure([
            'data',
            'meta',
            'errors' => [
                ['field', 'message'],
            ],
        ])
        ->assertJsonPath('data', null);

    // Check that field-level errors are present
    $errors = $response->json('errors');
    $fields = array_column($errors, 'field');
    expect($fields)->toContain('email');
});

it('wraps 404 model not found in envelope', function () {
    // 404 on a non-existent route doesn't need auth
    $response = $this->getJson('/api/v1/nonexistent-route');

    $response->assertStatus(404)
        ->assertJsonStructure([
            'data',
            'meta',
            'errors' => [
                ['message'],
            ],
        ])
        ->assertJsonPath('data', null);
});

it('wraps successful login in envelope', function () {
    $tenant = createApiTestTenant();
    $user = null;

    $tenant->run(function () use (&$user) {
        $user = User::factory()->create([
            'role' => 'owner',
            'is_active' => true,
            'password' => bcrypt('password123'),
        ]);
        $user->assignRole('owner');
    });

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password123',
        'client_type' => 'portal',
        'device_name' => 'test',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => ['token', 'user' => ['id', 'name', 'email', 'role']],
            'meta',
            'errors',
        ])
        ->assertJsonPath('errors', [])
        ->assertJsonPath('data.user.role', 'owner');
});

it('wraps unauthenticated error in envelope', function () {
    $tenant = createApiTestTenant();

    $response = $this->getJson('/api/v1/winery', [
        'X-Tenant-ID' => $tenant->id,
    ]);

    // Sanctum returns 401 for unauthenticated
    $response->assertStatus(401)
        ->assertJsonStructure([
            'data',
            'meta',
            'errors',
        ]);
});

it('wraps forbidden role error in envelope', function () {
    $tenant = createApiTestTenant();
    $email = null;

    $tenant->run(function () use (&$email) {
        $user = User::factory()->create([
            'role' => 'read_only',
            'is_active' => true,
            'password' => bcrypt('password123'),
        ]);
        $user->assignRole('read_only');
        $email = $user->email;
    });

    // Login via API to get a proper token
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'password123',
        'client_type' => 'portal',
        'device_name' => 'test',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $token = $loginResponse->json('data.token');

    // read_only can't update winery profile (requires owner/admin)
    $response = $this->putJson('/api/v1/winery', [
        'name' => 'New Name',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(403)
        ->assertJsonStructure([
            'data',
            'meta',
            'errors' => [
                ['message'],
            ],
        ])
        ->assertJsonPath('data', null);
});

it('wraps logout message in envelope', function () {
    $tenant = createApiTestTenant();
    $email = null;

    $tenant->run(function () use (&$email) {
        $user = User::factory()->create([
            'role' => 'owner',
            'is_active' => true,
            'password' => bcrypt('password123'),
        ]);
        $user->assignRole('owner');
        $email = $user->email;
    });

    // Login via API to get a proper token
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'password123',
        'client_type' => 'portal',
        'device_name' => 'test',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $token = $loginResponse->json('data.token');

    $response = $this->postJson('/api/v1/auth/logout', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data',
            'meta' => ['message'],
            'errors',
        ])
        ->assertJsonPath('meta.message', 'Logged out.')
        ->assertJsonPath('errors', []);
});

it('does not apply envelope to non-API routes', function () {
    // The /up health check is a Laravel default, not under /api/
    $response = $this->get('/up');

    // Should be standard Laravel response, not our envelope
    $response->assertStatus(200);
    // The response should NOT have our envelope structure
    if ($response->headers->get('Content-Type') === 'application/json') {
        $json = $response->json();
        // If it happens to be JSON, it shouldn't have our exact envelope
        expect($json)->not->toHaveKeys(['data', 'meta', 'errors']);
    }
});

it('forces JSON accept header on API routes', function () {
    // Even without Accept: application/json, API routes should return JSON
    $response = $this->get('/api/v1/health', [
        'Accept' => 'text/html',
    ]);

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'application/json');
});
