<?php

declare(strict_types=1);

use App\Http\Middleware\ThrottleByTokenType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant and optionally run a callback in its context.
 */
function createRateLimitTestTenant(string $slug = 'ratelimit-winery', ?Closure $callback = null): Tenant
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

/*
 * Helper: create a user, login, and return the token.
 */
function loginForRateLimit(Tenant $tenant, string $role = 'owner', string $clientType = 'portal', string $email = ''): string
{
    if ($email === '') {
        $email = $role.'-'.$clientType.'@example.com';
    }

    $tenant->run(function () use ($role, $email) {
        $user = User::create([
            'name' => ucfirst($role).' User',
            'email' => $email,
            'password' => 'SecurePass123!',
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);
    });

    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'SecurePass123!',
        'client_type' => $clientType,
        'device_name' => 'Test Device',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    return $loginResponse->json('data.token');
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

// ─── Rate limit constants ──────────────────────────────────────

it('defines correct rate limits per client type', function () {
    expect(ThrottleByTokenType::LIMITS)->toBe([
        'portal' => 120,
        'cellar_app' => 60,
        'pos_app' => 60,
        'widget' => 30,
        'public_api' => 60,
    ]);
    expect(ThrottleByTokenType::DEFAULT_LIMIT)->toBe(30);
});

// ─── Rate limit headers ────────────────────────────────────────

it('includes rate limit headers on authenticated API responses', function () {
    $tenant = createRateLimitTestTenant();
    $token = loginForRateLimit($tenant);

    $response = $this->getJson('/api/v1/winery', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(200)
        ->assertHeader('X-RateLimit-Limit')
        ->assertHeader('X-RateLimit-Remaining');
});

it('decrements remaining count on each request', function () {
    $tenant = createRateLimitTestTenant();
    $token = loginForRateLimit($tenant);

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    $response1 = $this->getJson('/api/v1/winery', $headers);
    $remaining1 = (int) $response1->headers->get('X-RateLimit-Remaining');

    $response2 = $this->getJson('/api/v1/winery', $headers);
    $remaining2 = (int) $response2->headers->get('X-RateLimit-Remaining');

    expect($remaining2)->toBeLessThan($remaining1);
});

// ─── Token type limits ─────────────────────────────────────────

it('applies portal limit of 120 for portal tokens', function () {
    $tenant = createRateLimitTestTenant();
    $token = loginForRateLimit($tenant, 'owner', 'portal');

    $response = $this->getJson('/api/v1/winery', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    expect((int) $response->headers->get('X-RateLimit-Limit'))->toBe(120);
});

it('applies mobile limit of 60 for cellar_app tokens', function () {
    $tenant = createRateLimitTestTenant();
    $token = loginForRateLimit($tenant, 'cellar_hand', 'cellar_app');

    $response = $this->getJson('/api/v1/winery', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    expect((int) $response->headers->get('X-RateLimit-Limit'))->toBe(60);
});

it('applies mobile limit of 60 for pos_app tokens', function () {
    $tenant = createRateLimitTestTenant();
    $token = loginForRateLimit($tenant, 'tasting_room_staff', 'pos_app');

    $response = $this->getJson('/api/v1/winery', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    expect((int) $response->headers->get('X-RateLimit-Limit'))->toBe(60);
});

it('applies widget limit of 30 for widget tokens', function () {
    $tenant = createRateLimitTestTenant();
    $token = loginForRateLimit($tenant, 'read_only', 'widget');

    $response = $this->getJson('/api/v1/winery', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    expect((int) $response->headers->get('X-RateLimit-Limit'))->toBe(30);
});

it('applies default limit of 30 for unauthenticated requests', function () {
    $tenant = createRateLimitTestTenant();

    // Unauthenticated request to a public tenant endpoint
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'wrong',
        'client_type' => 'portal',
        'device_name' => 'test',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    // Should have rate limit headers (default 30/min for unauthenticated)
    expect((int) $response->headers->get('X-RateLimit-Limit'))->toBe(30);
});

// ─── 429 Too Many Requests ─────────────────────────────────────

it('returns 429 with envelope when rate limit is exceeded', function () {
    $tenant = createRateLimitTestTenant();
    $token = loginForRateLimit($tenant, 'read_only', 'widget', 'widget429@example.com');

    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    // Widget limit is 30/min. Make 31 requests to exceed it.
    for ($i = 0; $i < 31; $i++) {
        $this->getJson('/api/v1/winery', $headers);
    }

    // This request should be rate limited
    $response = $this->getJson('/api/v1/winery', $headers);

    $response->assertStatus(429)
        ->assertHeader('X-RateLimit-Limit', '30')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertHeader('Retry-After')
        ->assertJsonStructure([
            'data',
            'meta',
            'errors' => [
                ['message'],
            ],
        ])
        ->assertJsonPath('data', null);
});

// ─── API versioning ────────────────────────────────────────────

it('serves all routes under /api/v1/ prefix', function () {
    $response = $this->getJson('/api/v1/health');
    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'ok');
});

it('returns 404 for routes without v1 prefix', function () {
    $response = $this->getJson('/api/health');
    $response->assertStatus(404);
});

it('returns 404 for non-existent API versions', function () {
    $response = $this->getJson('/api/v2/health');
    $response->assertStatus(404);
});

// ─── Token name format ─────────────────────────────────────────

it('stores client_type in token name for rate limit identification', function () {
    $tenant = createRateLimitTestTenant();

    $tenant->run(function () {
        $user = User::create([
            'name' => 'Token Name User',
            'email' => 'tokenname@example.com',
            'password' => 'SecurePass123!',
            'role' => 'owner',
            'is_active' => true,
        ]);
        $user->assignRole('owner');
    });

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'tokenname@example.com',
        'password' => 'SecurePass123!',
        'client_type' => 'cellar_app',
        'device_name' => 'My iPhone',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $loginResponse->assertStatus(200);

    // Verify token was created with client_type prefix
    $tenant->run(function () {
        $user = User::where('email', 'tokenname@example.com')->first();
        $token = $user->tokens()->latest()->first();
        expect($token->name)->toBe('cellar_app|My iPhone');
    });
});
