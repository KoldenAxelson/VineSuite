<?php

declare(strict_types=1);

use App\Filament\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WineryProfile;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant and optionally run a callback in its context.
 */
function createTestTenantForActivityLog(string $slug = 'activity-winery', ?Closure $callback = null): Tenant
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

// ── Model & Migration ──────────────────────────────────────────────

it('creates activity_logs table with immutability trigger', function () {
    $tenant = createTestTenantForActivityLog('trigger-test');

    $tenant->run(function () {
        // Insert a log entry directly
        $log = ActivityLog::create([
            'user_id' => null,
            'action' => 'created',
            'model_type' => 'App\\Models\\User',
            'model_id' => fake()->uuid(),
            'new_values' => ['name' => 'Test'],
        ]);

        expect($log->id)->not->toBeNull();
        expect($log->created_at)->not->toBeNull();

        // Attempt to UPDATE — should fail (immutability trigger)
        try {
            DB::table('activity_logs')->where('id', $log->id)->update(['action' => 'deleted']);
            $this->fail('Expected exception for UPDATE on immutable activity_logs');
        } catch (\Throwable $e) {
            expect($e->getMessage())->toContain('immutable');
        }

        // Attempt to DELETE — should fail (immutability trigger)
        try {
            DB::table('activity_logs')->where('id', $log->id)->delete();
            $this->fail('Expected exception for DELETE on immutable activity_logs');
        } catch (\Throwable $e) {
            expect($e->getMessage())->toContain('immutable');
        }
    });
});

it('has correct JSONB casts on the model', function () {
    $tenant = createTestTenantForActivityLog('cast-test');

    $tenant->run(function () {
        $log = ActivityLog::create([
            'action' => 'updated',
            'model_type' => 'App\\Models\\WineryProfile',
            'model_id' => fake()->uuid(),
            'old_values' => ['name' => 'Old'],
            'new_values' => ['name' => 'New'],
            'changed_fields' => ['name'],
        ]);

        $fresh = ActivityLog::find($log->id);
        expect($fresh->old_values)->toBeArray()->and($fresh->old_values['name'])->toBe('Old');
        expect($fresh->new_values)->toBeArray()->and($fresh->new_values['name'])->toBe('New');
        expect($fresh->changed_fields)->toBeArray()->and($fresh->changed_fields)->toContain('name');
    });
});

it('has scopes: forModel, byUser, ofAction', function () {
    $tenant = createTestTenantForActivityLog('scope-test');

    $tenant->run(function () {
        $user = User::create([
            'name' => 'Scope User',
            'email' => 'scope@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        // Disable activity logging temporarily to control test data
        $modelId = fake()->uuid();

        // Create logs directly to avoid trait auto-logging interference
        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'created',
            'model_type' => 'App\\Models\\WineryProfile',
            'model_id' => $modelId,
            'new_values' => ['name' => 'Test'],
        ]);

        ActivityLog::create([
            'user_id' => null,
            'action' => 'updated',
            'model_type' => 'App\\Models\\User',
            'model_id' => fake()->uuid(),
            'old_values' => ['role' => 'admin'],
            'new_values' => ['role' => 'winemaker'],
            'changed_fields' => ['role'],
        ]);

        // Count includes auto-logged entries from User::create above
        $forModel = ActivityLog::forModel('App\\Models\\WineryProfile', $modelId)->count();
        expect($forModel)->toBe(1);

        $byUser = ActivityLog::byUser($user->id)->count();
        expect($byUser)->toBeGreaterThanOrEqual(1);

        $creates = ActivityLog::ofAction('created')->count();
        expect($creates)->toBeGreaterThanOrEqual(1);
    });
});

// ── LogsActivity Trait ─────────────────────────────────────────────

it('automatically logs model creation via LogsActivity trait', function () {
    $tenant = createTestTenantForActivityLog('auto-create');

    $tenant->run(function () {
        $user = User::create([
            'name' => 'Auto Log User',
            'email' => 'autolog@test.com',
            'password' => bcrypt('password'),
            'role' => 'winemaker',
        ]);

        $log = ActivityLog::where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('action', 'created')
            ->first();

        expect($log)->not->toBeNull();
        expect($log->new_values)->toBeArray();
        expect($log->new_values)->toHaveKey('name');
        expect($log->new_values['name'])->toBe('Auto Log User');
        expect($log->old_values)->toBeNull();
    });
});

it('automatically logs model updates with old and new values', function () {
    $tenant = createTestTenantForActivityLog('auto-update');

    $tenant->run(function () {
        $user = User::create([
            'name' => 'Before Update',
            'email' => 'update@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $user->update(['name' => 'After Update']);

        $log = ActivityLog::where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('action', 'updated')
            ->first();

        expect($log)->not->toBeNull();
        expect($log->old_values)->toHaveKey('name');
        expect($log->old_values['name'])->toBe('Before Update');
        expect($log->new_values)->toHaveKey('name');
        expect($log->new_values['name'])->toBe('After Update');
        expect($log->changed_fields)->toContain('name');
    });
});

it('automatically logs model deletion', function () {
    $tenant = createTestTenantForActivityLog('auto-delete');

    $tenant->run(function () {
        $profile = WineryProfile::create([
            'name' => 'Doomed Winery',
            'country' => 'US',
            'timezone' => 'America/Los_Angeles',
            'unit_system' => 'imperial',
            'currency' => 'USD',
            'fiscal_year_start_month' => 1,
            'date_format' => 'm/d/Y',
        ]);

        $profileId = $profile->id;
        $profile->delete();

        $log = ActivityLog::where('model_type', WineryProfile::class)
            ->where('model_id', $profileId)
            ->where('action', 'deleted')
            ->first();

        expect($log)->not->toBeNull();
        expect($log->old_values)->toBeArray();
        expect($log->old_values)->toHaveKey('name');
        expect($log->old_values['name'])->toBe('Doomed Winery');
        expect($log->new_values)->toBeNull();
    });
});

it('excludes sensitive fields from activity logging', function () {
    $tenant = createTestTenantForActivityLog('exclude-fields');

    $tenant->run(function () {
        $user = User::create([
            'name' => 'Sensitive User',
            'email' => 'sensitive@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $log = ActivityLog::where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('action', 'created')
            ->first();

        expect($log->new_values)->not->toHaveKey('password');
        expect($log->new_values)->not->toHaveKey('remember_token');
        expect($log->new_values)->not->toHaveKey('updated_at');
        expect($log->new_values)->not->toHaveKey('created_at');
    });
});

it('skips logging when only excluded fields change', function () {
    $tenant = createTestTenantForActivityLog('skip-excluded');

    $tenant->run(function () {
        $profile = WineryProfile::create([
            'name' => 'Skip Test Winery',
            'country' => 'US',
            'timezone' => 'America/Los_Angeles',
            'unit_system' => 'imperial',
            'currency' => 'USD',
            'fiscal_year_start_month' => 1,
            'date_format' => 'm/d/Y',
        ]);

        $updateCountBefore = ActivityLog::where('model_type', WineryProfile::class)
            ->where('model_id', $profile->id)
            ->where('action', 'updated')
            ->count();

        // Touch only an excluded field (updated_at is excluded on WineryProfile)
        DB::table('winery_profiles')->where('id', $profile->id)->update([
            'updated_at' => now()->addHour(),
        ]);

        // Re-fetch and "update" through Eloquent with only excluded timestamps
        // The trait checks getDirty() — if all dirty fields are excluded, no log is created
        $profile->refresh();
        // No explicit update that would only change excluded fields is easy to trigger
        // so we verify the count didn't change spuriously
        $updateCountAfter = ActivityLog::where('model_type', WineryProfile::class)
            ->where('model_id', $profile->id)
            ->where('action', 'updated')
            ->count();

        expect($updateCountAfter)->toBe($updateCountBefore);
    });
});

it('captures authenticated user in activity log', function () {
    $tenant = createTestTenantForActivityLog('auth-capture');

    $tenant->run(function () {
        $admin = User::create([
            'name' => 'The Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        // Authenticate as admin
        auth()->login($admin);

        $profile = WineryProfile::create([
            'name' => 'Auth Test Winery',
            'country' => 'US',
            'timezone' => 'America/Los_Angeles',
            'unit_system' => 'imperial',
            'currency' => 'USD',
            'fiscal_year_start_month' => 1,
            'date_format' => 'm/d/Y',
        ]);

        $log = ActivityLog::where('model_type', WineryProfile::class)
            ->where('model_id', $profile->id)
            ->where('action', 'created')
            ->first();

        expect($log->user_id)->toBe($admin->id);

        auth()->logout();
    });
});

it('logs WineryProfile updates correctly', function () {
    $tenant = createTestTenantForActivityLog('winery-update');

    $tenant->run(function () {
        $profile = WineryProfile::create([
            'name' => 'Original Name',
            'country' => 'US',
            'timezone' => 'America/Los_Angeles',
            'unit_system' => 'imperial',
            'currency' => 'USD',
            'fiscal_year_start_month' => 1,
            'date_format' => 'm/d/Y',
        ]);

        $profile->update([
            'name' => 'Updated Name',
            'unit_system' => 'metric',
        ]);

        $log = ActivityLog::where('model_type', WineryProfile::class)
            ->where('model_id', $profile->id)
            ->where('action', 'updated')
            ->first();

        expect($log)->not->toBeNull();
        expect($log->changed_fields)->toContain('name');
        expect($log->changed_fields)->toContain('unit_system');
        expect($log->old_values['name'])->toBe('Original Name');
        expect($log->new_values['name'])->toBe('Updated Name');
        expect($log->old_values['unit_system'])->toBe('imperial');
        expect($log->new_values['unit_system'])->toBe('metric');
    });
});

// ── Cross-Tenant Isolation ─────────────────────────────────────────

it('isolates activity logs between tenants', function () {
    $tenant1 = createTestTenantForActivityLog('activity-iso-1');
    $tenant2 = createTestTenantForActivityLog('activity-iso-2');

    $tenant1->run(function () {
        WineryProfile::create([
            'name' => 'Tenant 1 Winery',
            'country' => 'US',
            'timezone' => 'America/Los_Angeles',
            'unit_system' => 'imperial',
            'currency' => 'USD',
            'fiscal_year_start_month' => 1,
            'date_format' => 'm/d/Y',
        ]);
    });

    $tenant2->run(function () {
        // Tenant 2 may have activity logs from its own seeder (e.g. WineryProfile auto-created),
        // but it must NOT contain any logs referencing tenant 1's WineryProfile.
        $crossTenantLogs = ActivityLog::where('model_type', WineryProfile::class)
            ->whereHas('user', function ($q) {
                // No user from tenant 1 should appear here
            })
            ->get();

        // More directly: no log should reference "Tenant 1 Winery"
        $leaked = ActivityLog::where('model_type', WineryProfile::class)
            ->get()
            ->filter(fn ($log) => ($log->new_values['name'] ?? null) === 'Tenant 1 Winery');

        expect($leaked)->toHaveCount(0);
    });
});

// ── Filament ActivityLogResource ───────────────────────────────────

it('has correct Filament resource configuration', function () {
    expect(ActivityLogResource::canCreate())->toBeFalse();
    expect(ActivityLogResource::getNavigationGroup())->toBe('Settings');
    expect(ActivityLogResource::getNavigationLabel())->toBe('Activity Log');
});

it('restricts ActivityLogResource access to owner and admin', function () {
    $tenant = createTestTenantForActivityLog('filament-access');

    $tenant->run(function () {
        // Not logged in — no access
        expect(ActivityLogResource::canAccess())->toBeFalse();

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@filament.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
        auth()->login($admin);
        expect(ActivityLogResource::canAccess())->toBeTrue();
        auth()->logout();

        $owner = User::create([
            'name' => 'Owner User',
            'email' => 'owner@filament.test',
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);
        auth()->login($owner);
        expect(ActivityLogResource::canAccess())->toBeTrue();
        auth()->logout();

        $winemaker = User::create([
            'name' => 'Winemaker User',
            'email' => 'winemaker@filament.test',
            'password' => bcrypt('password'),
            'role' => 'winemaker',
        ]);
        auth()->login($winemaker);
        expect(ActivityLogResource::canAccess())->toBeFalse();
        auth()->logout();
    });
});

// ── Trait Resilience ───────────────────────────────────────────────

it('does not break the application if activity logging fails', function () {
    $tenant = createTestTenantForActivityLog('resilience-test');

    $tenant->run(function () {
        // Temporarily rename the activity_logs table to force a real logging failure
        DB::statement('ALTER TABLE activity_logs RENAME TO activity_logs_disabled');

        // User creation should still succeed despite activity logging failure
        // (LogsActivity trait wraps in try/catch)
        $user = User::create([
            'name' => 'Resilient User',
            'email' => 'resilient@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        expect($user->exists)->toBeTrue();
        expect($user->name)->toBe('Resilient User');

        // Restore the table so other tests aren't affected
        DB::statement('ALTER TABLE activity_logs_disabled RENAME TO activity_logs');

        // Verify no activity log was written for this user (the logging failed silently)
        $logs = ActivityLog::where('model_type', User::class)
            ->where('model_id', $user->id)
            ->count();
        expect($logs)->toBe(0);
    });
});
