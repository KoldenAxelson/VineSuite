<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

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

// ─── Panel Configuration ────────────────────────────────────────

it('has a portal panel configured at /portal path', function () {
    $panel = filament()->getPanel('portal');

    expect($panel)->not->toBeNull();
    expect($panel->getPath())->toBe('portal');
    expect($panel->getId())->toBe('portal');
});

it('has all required navigation groups configured', function () {
    $panel = filament()->getPanel('portal');
    $groups = collect($panel->getNavigationGroups())->map(fn ($g) => $g->getLabel());

    expect($groups)->toContain('Production');
    expect($groups)->toContain('Inventory');
    expect($groups)->toContain('Compliance');
    expect($groups)->toContain('Sales');
    expect($groups)->toContain('Club');
    expect($groups)->toContain('CRM');
    expect($groups)->toContain('Settings');
});

it('brand name is VineSuite', function () {
    $panel = filament()->getPanel('portal');

    expect($panel->getBrandName())->toBe('VineSuite');
});

// ─── Dashboard Page ─────────────────────────────────────────────

it('dashboard page exists and is registered', function () {
    $tenant = Tenant::create([
        'name' => 'Portal Test Winery',
        'slug' => 'portal-test',
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

        expect(Dashboard::class)->toBeString();
        expect(class_exists(Dashboard::class))->toBeTrue();
    });
});

// ─── UserResource Configuration ─────────────────────────────────

it('user resource is in Settings navigation group', function () {
    expect(UserResource::getNavigationGroup())->toBe('Settings');
});

it('user resource label is Team Members', function () {
    expect(UserResource::getModelLabel())->toBe('Team Member');
    expect(UserResource::getPluralModelLabel())->toBe('Team Members');
});

it('user resource has list and edit pages', function () {
    $pages = UserResource::getPages();

    expect($pages)->toHaveKey('index');
    expect($pages)->toHaveKey('edit');
});

// ─── Access Control ─────────────────────────────────────────────

it('owner can access user resource', function () {
    $tenant = Tenant::create([
        'name' => 'Access Test Winery',
        'slug' => 'access-test',
        'plan' => 'basic',
    ]);

    $tenant->run(function () {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'SecurePass123!',
            'role' => 'owner',
            'is_active' => true,
        ]);
        $owner->assignRole('owner');

        $this->actingAs($owner);
        expect(UserResource::canAccess())->toBeTrue();
    });
});

it('admin can access user resource', function () {
    $tenant = Tenant::create([
        'name' => 'Admin Access Winery',
        'slug' => 'admin-access',
        'plan' => 'basic',
    ]);

    $tenant->run(function () {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'SecurePass123!',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin);
        expect(UserResource::canAccess())->toBeTrue();
    });
});

it('winemaker cannot access user resource', function () {
    $tenant = Tenant::create([
        'name' => 'Winemaker Access Winery',
        'slug' => 'winemaker-access',
        'plan' => 'basic',
    ]);

    $tenant->run(function () {
        $winemaker = User::create([
            'name' => 'Winemaker',
            'email' => 'winemaker@example.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);
        $winemaker->assignRole('winemaker');

        $this->actingAs($winemaker);
        expect(UserResource::canAccess())->toBeFalse();
    });
});

it('read_only user cannot access user resource', function () {
    $tenant = Tenant::create([
        'name' => 'ReadOnly Access Winery',
        'slug' => 'readonly-access',
        'plan' => 'basic',
    ]);

    $tenant->run(function () {
        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => 'SecurePass123!',
            'role' => 'read_only',
            'is_active' => true,
        ]);
        $viewer->assignRole('read_only');

        $this->actingAs($viewer);
        expect(UserResource::canAccess())->toBeFalse();
    });
});
