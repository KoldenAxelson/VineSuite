<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(DatabaseMigrations::class);

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

it('seeds all 7 roles when a tenant is created', function () {
    $tenant = Tenant::create([
        'name' => 'Test Winery',
        'slug' => 'test-winery',
        'plan' => 'basic',
    ]);

    $tenant->run(function () {
        $roles = Role::all()->pluck('name')->sort()->values()->toArray();

        expect($roles)->toBe([
            'accountant',
            'admin',
            'cellar_hand',
            'owner',
            'read_only',
            'tasting_room_staff',
            'winemaker',
        ]);
    });
});

it('owner has all permissions', function () {
    $tenant = Tenant::create([
        'name' => 'Test Winery',
        'slug' => 'test-winery',
        'plan' => 'basic',
    ]);

    $tenant->run(function () {
        $ownerRole = Role::findByName('owner');
        $totalPermissions = Permission::count();

        expect($ownerRole->permissions->count())->toBe($totalPermissions);
        expect($totalPermissions)->toBeGreaterThan(0);
    });
});

it('read_only role has only read permissions', function () {
    $tenant = Tenant::create([
        'name' => 'Test Winery',
        'slug' => 'test-winery',
        'plan' => 'basic',
    ]);

    $tenant->run(function () {
        $readOnlyRole = Role::findByName('read_only');
        $permissions = $readOnlyRole->permissions->pluck('name');

        // All permissions should end with .read
        $nonReadPerms = $permissions->filter(fn ($p) => ! str_ends_with($p, '.read'));

        expect($nonReadPerms)->toBeEmpty();
    });
});

it('cellar_hand cannot access admin permissions', function () {
    $tenant = Tenant::create([
        'name' => 'Test Winery',
        'slug' => 'test-winery',
        'plan' => 'basic',
    ]);

    $tenant->run(function () {
        $user = User::create([
            'name' => 'Cellar Worker',
            'email' => 'cellar@example.com',
            'password' => 'SecurePass123!',
            'role' => 'cellar_hand',
            'is_active' => true,
        ]);
        $user->assignRole('cellar_hand');

        expect($user->hasPermissionTo('work-orders.update'))->toBeTrue();
        expect($user->hasPermissionTo('additions.create'))->toBeTrue();
        expect($user->hasPermissionTo('settings.update'))->toBeFalse();
        expect($user->hasPermissionTo('users.create'))->toBeFalse();
        expect($user->hasPermissionTo('billing.read'))->toBeFalse();
    });
});

it('role middleware blocks unauthorized access', function () {
    $tenant = Tenant::create([
        'name' => 'Test Winery',
        'slug' => 'test-winery',
        'plan' => 'basic',
    ]);

    $tenant->run(function () use ($tenant) {
        $user = User::create([
            'name' => 'Cellar Worker',
            'email' => 'cellar@example.com',
            'password' => 'SecurePass123!',
            'role' => 'cellar_hand',
            'is_active' => true,
        ]);
        $user->assignRole('cellar_hand');

        $token = $user->createToken('test', User::TOKEN_ABILITIES['portal'])->plainTextToken;

        // Register a test route that requires owner role
        \Illuminate\Support\Facades\Route::middleware([
            \Stancl\Tenancy\Middleware\InitializeTenancyByRequestData::class,
            'auth:sanctum',
            'role:owner,admin',
        ])->get('/api/v1/test-admin-only', function () {
            return response()->json(['message' => 'admin area']);
        });

        $this->getJson('/api/v1/test-admin-only', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });
});

it('token abilities scope access per client type', function () {
    $tenant = Tenant::create([
        'name' => 'Test Winery',
        'slug' => 'test-winery',
        'plan' => 'basic',
    ]);

    $tenant->run(function () {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
            'role' => 'owner',
            'is_active' => true,
        ]);
        $user->assignRole('owner');

        // Portal token has full access
        $portalToken = $user->createToken('portal', User::TOKEN_ABILITIES['portal']);
        expect($portalToken->accessToken->can('*'))->toBeTrue();

        // Cellar app token has limited abilities
        $cellarToken = $user->createToken('cellar', User::TOKEN_ABILITIES['cellar_app']);
        expect($cellarToken->accessToken->can('events:create'))->toBeTrue();
        expect($cellarToken->accessToken->can('lots:read'))->toBeTrue();
        expect($cellarToken->accessToken->can('billing:update'))->toBeFalse();
        expect($cellarToken->accessToken->can('users:create'))->toBeFalse();
    });
});
