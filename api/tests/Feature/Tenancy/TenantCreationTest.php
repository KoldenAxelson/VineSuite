<?php

declare(strict_types=1);

use App\Jobs\CreateTenantJob;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

/*
 * Tenancy tests use DatabaseMigrations (not RefreshDatabase) because
 * PostgreSQL CREATE SCHEMA / DROP SCHEMA are DDL statements that
 * deadlock inside the transaction wrapper that RefreshDatabase uses.
 * DatabaseMigrations does migrate:fresh without wrapping tests in transactions.
 */
uses(DatabaseMigrations::class);

afterEach(function () {
    // End tenancy to clear dangling tenant connection before next test's migrate:fresh
    if (function_exists('tenancy') && tenancy()->initialized) {
        tenancy()->end();
    }

    // Drop any tenant schemas left behind by the test
    $schemas = DB::select(
        "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'"
    );
    foreach ($schemas as $schema) {
        DB::statement("DROP SCHEMA IF EXISTS \"{$schema->schema_name}\" CASCADE");
    }
});

it('creates a tenant with its own PostgreSQL schema', function () {
    $tenant = Tenant::create([
        'name' => 'Test Winery',
        'slug' => 'test-winery',
        'plan' => 'basic',
    ]);

    expect($tenant)->toBeInstanceOf(Tenant::class);
    expect($tenant->id)->not->toBeEmpty();
    expect($tenant->name)->toBe('Test Winery');
    expect($tenant->slug)->toBe('test-winery');
    expect($tenant->plan)->toBe(\App\Enums\PlanTier::Basic);

    // Verify PostgreSQL schema was created
    $schemas = DB::select(
        'SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?',
        ['tenant_'.$tenant->id]
    );

    expect($schemas)->toHaveCount(1);
});

it('runs tenant migrations in isolation', function () {
    $tenant = Tenant::create([
        'name' => 'Test Winery',
        'slug' => 'test-winery',
        'plan' => 'basic',
    ]);

    // Verify tenant has its own users table in its schema
    $tenant->run(function () {
        $tables = DB::select(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'users'"
        );

        expect($tables)->toHaveCount(1);
    });

    // Verify the central schema's users table is separate (exists from default Laravel migration)
    $centralUsers = DB::select(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users'"
    );
    expect($centralUsers)->toHaveCount(1);
});

it('prevents cross-tenant data access', function () {
    $tenantA = Tenant::create([
        'name' => 'Winery Alpha',
        'slug' => 'winery-alpha',
        'plan' => 'basic',
    ]);

    $tenantB = Tenant::create([
        'name' => 'Winery Beta',
        'slug' => 'winery-beta',
        'plan' => 'pro',
    ]);

    // Insert a user in tenant A
    $tenantA->run(function () {
        DB::table('users')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'name' => 'Alpha User',
            'email' => 'alpha@example.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    // Verify tenant B cannot see tenant A's data
    $tenantB->run(function () {
        $users = DB::table('users')->get();
        expect($users)->toHaveCount(0);
    });

    // Verify tenant A can see its own data
    $tenantA->run(function () {
        $users = DB::table('users')->get();
        expect($users)->toHaveCount(1);
        expect($users->first()->email)->toBe('alpha@example.com');
    });
});

it('creates a tenant via CreateTenantJob', function () {
    $job = new CreateTenantJob(
        name: 'Test Winery',
        slug: 'test-winery',
        plan: 'pro',
        ownerEmail: 'owner@example.com',
    );

    $tenant = $job->handle();

    expect($tenant)->toBeInstanceOf(Tenant::class);
    expect($tenant->name)->toBe('Test Winery');
    expect($tenant->slug)->toBe('test-winery');
    expect($tenant->plan)->toBe(\App\Enums\PlanTier::Pro);

    // Verify domain was created
    expect($tenant->domains)->toHaveCount(1);
    expect($tenant->domains->first()->domain)->toBe('test-winery');
});

it('enforces unique slugs', function () {
    Tenant::create([
        'name' => 'Test Winery',
        'slug' => 'test-winery',
        'plan' => 'basic',
    ]);

    expect(fn () => Tenant::create([
        'name' => 'Another Winery',
        'slug' => 'test-winery',
        'plan' => 'basic',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
