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
        'plan' => 'basic',
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

// ─── Tenant Billable Setup ──────────────────────────────────────

it('tenant model has Billable trait with working Stripe methods', function () {
    $tenant = Tenant::create([
        'name' => 'Billable Test',
        'slug' => 'billable-test',
        'plan' => 'basic',
    ]);

    // Verify Cashier Billable methods return expected defaults (no Stripe configured)
    expect($tenant->hasStripeId())->toBeFalse();
    expect($tenant->subscribed())->toBeFalse();
    expect($tenant->subscription())->toBeNull();
});

it('tenant plan helper methods return correct values for basic plan', function () {
    $tenant = Tenant::create([
        'name' => 'Plan Helper Test',
        'slug' => 'plan-helper-test',
        'plan' => 'basic',
    ]);

    // Exercise each helper with real return value checks
    expect($tenant->isFreePlan())->toBeFalse();
    expect($tenant->hasActiveSubscription())->toBeFalse();
    // hasActiveAccess() = isFreePlan() || subscribed() — basic plan with no Stripe sub = false
    expect($tenant->hasActiveAccess())->toBeFalse();
    expect($tenant->isInGracePeriod())->toBeFalse();
    expect($tenant->planRank())->toBe(1);
    expect($tenant->hasPlanAtLeast('basic'))->toBeTrue();
    expect($tenant->hasPlanAtLeast('pro'))->toBeFalse();
    expect($tenant->isDowngradeTo('free'))->toBeTrue();
    expect($tenant->isDowngradeTo('pro'))->toBeFalse();

    // Verify free plan returns hasActiveAccess = true
    $freeTenant = Tenant::create([
        'name' => 'Free Plan Test',
        'slug' => 'free-plan-test',
        'plan' => 'free',
    ]);
    expect($freeTenant->isFreePlan())->toBeTrue();
    expect($freeTenant->hasActiveAccess())->toBeTrue();
});

it('stripePriceForPlan returns null when env not set', function () {
    // Without STRIPE_PRICE_* env vars, should return null
    expect(Tenant::stripePriceForPlan('free'))->toBeNull();
    expect(Tenant::stripePriceForPlan('basic'))->toBeNull();
    expect(Tenant::stripePriceForPlan('pro'))->toBeNull();
    expect(Tenant::stripePriceForPlan('max'))->toBeNull();
    expect(Tenant::stripePriceForPlan('nonexistent'))->toBeNull();
});

// ─── Free Plan ──────────────────────────────────────────────────

it('new tenants default to free plan', function () {
    $tenant = Tenant::create([
        'name' => 'Free Plan Test',
        'slug' => 'free-plan-test',
    ]);

    expect($tenant->plan)->toBe('free');
    expect($tenant->isFreePlan())->toBeTrue();
    expect($tenant->hasActiveAccess())->toBeTrue();
});

it('free plan tenant has no stripe subscription', function () {
    $tenant = Tenant::create([
        'name' => 'Free No Stripe',
        'slug' => 'free-no-stripe',
    ]);

    expect($tenant->isFreePlan())->toBeTrue();
    expect($tenant->hasActiveSubscription())->toBeFalse();
    expect($tenant->hasActiveAccess())->toBeTrue();
});

// ─── Plan Hierarchy ─────────────────────────────────────────────

it('planRank returns correct numeric rank', function () {
    $free = Tenant::create(['name' => 'Free', 'slug' => 'rank-free', 'plan' => 'free']);
    $basic = Tenant::create(['name' => 'Basic', 'slug' => 'rank-basic', 'plan' => 'basic']);
    $pro = Tenant::create(['name' => 'Pro', 'slug' => 'rank-pro', 'plan' => 'pro']);
    $max = Tenant::create(['name' => 'Max', 'slug' => 'rank-max', 'plan' => 'max']);

    expect($free->planRank())->toBe(0);
    expect($basic->planRank())->toBe(1);
    expect($pro->planRank())->toBe(2);
    expect($max->planRank())->toBe(3);
});

it('hasPlanAtLeast checks plan hierarchy correctly', function () {
    $basic = Tenant::create(['name' => 'Basic', 'slug' => 'atleast-basic', 'plan' => 'basic']);
    $pro = Tenant::create(['name' => 'Pro', 'slug' => 'atleast-pro', 'plan' => 'pro']);

    expect($basic->hasPlanAtLeast('free'))->toBeTrue();
    expect($basic->hasPlanAtLeast('basic'))->toBeTrue();
    expect($basic->hasPlanAtLeast('pro'))->toBeFalse();
    expect($basic->hasPlanAtLeast('max'))->toBeFalse();

    expect($pro->hasPlanAtLeast('free'))->toBeTrue();
    expect($pro->hasPlanAtLeast('basic'))->toBeTrue();
    expect($pro->hasPlanAtLeast('pro'))->toBeTrue();
    expect($pro->hasPlanAtLeast('max'))->toBeFalse();
});

it('isDowngradeTo detects downgrades correctly', function () {
    $pro = Tenant::create(['name' => 'Pro', 'slug' => 'downgrade-pro', 'plan' => 'pro']);

    expect($pro->isDowngradeTo('free'))->toBeTrue();
    expect($pro->isDowngradeTo('basic'))->toBeTrue();
    expect($pro->isDowngradeTo('pro'))->toBeFalse();
    expect($pro->isDowngradeTo('max'))->toBeFalse();
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
            'data' => [
                'plan',
                'has_stripe_id',
                'subscribed',
                'on_trial',
                'on_grace_period',
                'cancelled',
                'ends_at',
                'trial_ends_at',
            ],
            'meta',
            'errors',
        ])
        ->assertJsonPath('data.plan', 'basic')
        ->assertJsonPath('data.has_stripe_id', false)
        ->assertJsonPath('data.subscribed', false);
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

    $winemakerToken = $loginResponse->json('data.token');

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
        'plan' => 'basic',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    // Without STRIPE_PRICE_BASIC env, should return 422
    $response->assertStatus(422);
    expect($response->json('errors.0.message'))->toBe('Stripe price not configured for plan: basic');
});

it('checkout validates plan is one of basic/pro/max', function () {
    [$tenant, $token] = createBillingTestTenant();

    $response = $this->postJson('/api/v1/billing/checkout', [
        'plan' => 'enterprise',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('plan');
});

it('checkout rejects free as a plan choice', function () {
    [$tenant, $token] = createBillingTestTenant();

    $response = $this->postJson('/api/v1/billing/checkout', [
        'plan' => 'free',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('plan');
});

// ─── Portal Endpoint ────────────────────────────────────────────

it('portal returns error when no stripe customer exists', function () {
    [$tenant, $token] = createBillingTestTenant();

    $response = $this->postJson('/api/v1/billing/portal', [], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.0.message'))->toBe('No billing account found. Please subscribe to a plan first.');
});

// ─── Plan Change Endpoint ───────────────────────────────────────

it('plan change rejects when no active subscription', function () {
    [$tenant, $token] = createBillingTestTenant();

    $response = $this->putJson('/api/v1/billing/plan', [
        'plan' => 'pro',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.0.message'))->toBe('No active subscription found.');
});

it('plan change validates plan name', function () {
    [$tenant, $token] = createBillingTestTenant();

    $response = $this->putJson('/api/v1/billing/plan', [
        'plan' => 'invalid-plan',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(422);
    $fields = array_column($response->json('errors'), 'field');
    expect($fields)->toContain('plan');
});

// ─── Webhook Route ──────────────────────────────────────────────

it('webhook endpoint is registered and reachable', function () {
    // Post to the webhook endpoint without a Stripe signature
    $response = $this->postJson('/api/v1/stripe/webhook', [
        'type' => 'test.event',
        'data' => ['object' => []],
    ]);

    // The route exists (not 404/405) and responds.
    // In test environment without STRIPE_WEBHOOK_SECRET, Cashier accepts unsigned requests (200).
    // In production with the secret set, unsigned requests would be rejected (403).
    $response->assertStatus(200);
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
