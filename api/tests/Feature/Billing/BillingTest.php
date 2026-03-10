<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with an owner, return [tenant, token].
 */
function createBillingTestTenant(string $slug = 'billing-winery'): array
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

    return [$tenant, $loginResponse->json('token')];
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

// ─── Tenant Billable Setup ──────────────────────────────────────

it('tenant model has Billable trait', function () {
    $tenant = Tenant::create([
        'name' => 'Billable Test',
        'slug' => 'billable-test',
        'plan' => 'starter',
    ]);

    // Cashier Billable methods exist on the Tenant model
    expect(method_exists($tenant, 'subscription'))->toBeTrue();
    expect(method_exists($tenant, 'subscribed'))->toBeTrue();
    expect(method_exists($tenant, 'createOrGetStripeCustomer'))->toBeTrue();
    expect(method_exists($tenant, 'newSubscription'))->toBeTrue();
    expect(method_exists($tenant, 'hasStripeId'))->toBeTrue();
});

it('tenant has plan helper methods', function () {
    $tenant = Tenant::create([
        'name' => 'Plan Helper Test',
        'slug' => 'plan-helper-test',
        'plan' => 'starter',
    ]);

    expect(method_exists($tenant, 'hasActiveSubscription'))->toBeTrue();
    expect(method_exists($tenant, 'isInGracePeriod'))->toBeTrue();
});

it('stripePriceForPlan returns null when env not set', function () {
    // Without STRIPE_PRICE_* env vars, should return null
    expect(Tenant::stripePriceForPlan('starter'))->toBeNull();
    expect(Tenant::stripePriceForPlan('growth'))->toBeNull();
    expect(Tenant::stripePriceForPlan('pro'))->toBeNull();
    expect(Tenant::stripePriceForPlan('nonexistent'))->toBeNull();
});

// ─── Billing Status Endpoint ────────────────────────────────────

it('returns billing status for owner', function () {
    [$tenant, $token] = createBillingTestTenant();

    $response = $this->getJson('/api/v1/billing/status', [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'plan',
            'has_stripe_id',
            'subscribed',
            'on_trial',
            'on_grace_period',
            'cancelled',
            'ends_at',
            'trial_ends_at',
        ])
        ->assertJsonPath('plan', 'starter')
        ->assertJsonPath('has_stripe_id', false)
        ->assertJsonPath('subscribed', false);
});

it('billing status requires authentication', function () {
    [$tenant, $token] = createBillingTestTenant();

    $response = $this->getJson('/api/v1/billing/status', [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(401);
});

it('billing status requires owner or admin role', function () {
    [$tenant, $ownerToken] = createBillingTestTenant();

    // Create a winemaker
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

    $winemakerToken = $loginResponse->json('token');

    $response = $this->getJson('/api/v1/billing/status', [
        'Authorization' => "Bearer {$winemakerToken}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(403);
});

// ─── Checkout Endpoint ──────────────────────────────────────────

it('checkout rejects when stripe price not configured', function () {
    [$tenant, $token] = createBillingTestTenant();

    $response = $this->postJson('/api/v1/billing/checkout', [
        'plan' => 'starter',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    // Without STRIPE_PRICE_STARTER env, should return 422
    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'Stripe price not configured for plan: starter']);
});

it('checkout validates plan is one of starter/growth/pro', function () {
    [$tenant, $token] = createBillingTestTenant();

    $response = $this->postJson('/api/v1/billing/checkout', [
        'plan' => 'enterprise',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('plan');
});

// ─── Portal Endpoint ────────────────────────────────────────────

it('portal returns error when no stripe customer exists', function () {
    [$tenant, $token] = createBillingTestTenant();

    $response = $this->postJson('/api/v1/billing/portal', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'No billing account found. Please subscribe to a plan first.']);
});

// ─── Plan Change Endpoint ───────────────────────────────────────

it('plan change rejects when no active subscription', function () {
    [$tenant, $token] = createBillingTestTenant();

    $response = $this->putJson('/api/v1/billing/plan', [
        'plan' => 'growth',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'No active subscription found.']);
});

it('plan change validates plan name', function () {
    [$tenant, $token] = createBillingTestTenant();

    $response = $this->putJson('/api/v1/billing/plan', [
        'plan' => 'invalid-plan',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('plan');
});

// ─── Webhook Route ──────────────────────────────────────────────

it('webhook endpoint exists and is reachable', function () {
    // The webhook route should exist (will return 403 without valid signature)
    $response = $this->postJson('/api/v1/stripe/webhook', [
        'type' => 'test.event',
        'data' => ['object' => []],
    ]);

    // Cashier will reject without a valid Stripe signature, but the route should exist
    // It returns 403 (not 404) which means the route is registered
    expect($response->getStatusCode())->not->toBe(404);
});

// ─── Central Schema Tables ──────────────────────────────────────

it('subscriptions table exists in central schema', function () {
    $exists = DB::select(
        "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'subscriptions')"
    );

    expect($exists[0]->exists)->toBeTrue();
});

it('subscription_items table exists in central schema', function () {
    $exists = DB::select(
        "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'subscription_items')"
    );

    expect($exists[0]->exists)->toBeTrue();
});

it('tenants table has cashier columns', function () {
    $columns = DB::select(
        "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'tenants'"
    );

    $columnNames = collect($columns)->pluck('column_name')->toArray();

    expect($columnNames)->toContain('stripe_customer_id');
    expect($columnNames)->toContain('pm_type');
    expect($columnNames)->toContain('pm_last_four');
    expect($columnNames)->toContain('trial_ends_at');
});
