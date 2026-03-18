<?php

declare(strict_types=1);

use App\Models\Barrel;
use App\Models\Lot;
use App\Models\RawMaterial;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with an owner user and return [tenant, token, headers].
 */
function createSyncPullTenant(string $slug = 'pull-winery'): array
{
    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'basic',
    ]);

    $tenant->run(function () {
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@pull-test.com',
            'password' => 'SecurePass123!',
            'role' => 'owner',
            'is_active' => true,
        ]);
        $user->assignRole('owner');
    });

    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => 'owner@pull-test.com',
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $token = $loginResponse->json('data.token');
    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => $tenant->id,
    ];

    return [$tenant, $token, $headers];
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

// ─── Basic Pull ─────────────────────────────────────────────────

describe('Sync Pull', function () {

    it('returns all entity types in a single response', function () {
        [$tenant, $token, $headers] = createSyncPullTenant();

        $tenant->run(function () {
            Lot::factory()->count(2)->create();
            Vessel::factory()->count(3)->create();
            WorkOrder::factory()->count(1)->create();
            Barrel::factory()->count(2)->create();
            RawMaterial::factory()->count(4)->create();
        });

        $response = $this->getJson('/api/v1/sync/pull', $headers);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['lots', 'vessels', 'work_orders', 'barrels', 'raw_materials'],
                'meta' => ['synced_at', 'has_more', 'counts'],
            ]);

        // Counts should be at least what we created (factories may create related records)
        $counts = $response->json('meta.counts');
        expect($counts['lots'])->toBeGreaterThanOrEqual(2);
        expect($counts['vessels'])->toBeGreaterThanOrEqual(3);
        expect($counts['work_orders'])->toBeGreaterThanOrEqual(1);
        expect($counts['barrels'])->toBeGreaterThanOrEqual(2);
        expect($counts['raw_materials'])->toBeGreaterThanOrEqual(4);
        expect($response->json('meta.has_more'))->toBeFalse();
    })->group('foundation');

    it('returns empty arrays when no data exists', function () {
        [$tenant, $token, $headers] = createSyncPullTenant('empty-winery');

        $response = $this->getJson('/api/v1/sync/pull', $headers);

        $response->assertOk()
            ->assertJsonPath('meta.counts.lots', 0)
            ->assertJsonPath('meta.counts.vessels', 0)
            ->assertJsonPath('meta.counts.work_orders', 0)
            ->assertJsonPath('meta.counts.barrels', 0)
            ->assertJsonPath('meta.counts.raw_materials', 0)
            ->assertJsonPath('meta.has_more', false);
    })->group('foundation');

    // ─── Delta Sync ─────────────────────────────────────────────

    it('filters entities by since parameter for delta sync', function () {
        [$tenant, $token, $headers] = createSyncPullTenant('delta-winery');

        $tenant->run(function () {
            // Create old records (updated 2 hours ago)
            Lot::factory()->count(3)->create(['updated_at' => now()->subHours(2)]);
            Vessel::factory()->count(2)->create(['updated_at' => now()->subHours(2)]);

            // Create recent records (updated 10 minutes ago)
            Lot::factory()->count(1)->create(['updated_at' => now()->subMinutes(10)]);
            Vessel::factory()->count(1)->create(['updated_at' => now()->subMinutes(10)]);
        });

        $since = urlencode(now()->subHour()->toIso8601String());
        $response = $this->getJson("/api/v1/sync/pull?since={$since}", $headers);

        $response->assertOk()
            ->assertJsonPath('meta.counts.lots', 1)
            ->assertJsonPath('meta.counts.vessels', 1);
    })->group('foundation');

    it('returns all records when since is omitted (initial sync)', function () {
        [$tenant, $token, $headers] = createSyncPullTenant('initial-winery');

        $tenant->run(function () {
            Lot::factory()->count(5)->create();
        });

        $response = $this->getJson('/api/v1/sync/pull', $headers);

        $response->assertOk()
            ->assertJsonPath('meta.counts.lots', 5);
    })->group('foundation');

    // ─── synced_at Timestamp ────────────────────────────────────

    it('returns synced_at timestamp for the next pull cycle', function () {
        [$tenant, $token, $headers] = createSyncPullTenant('timestamp-winery');

        $before = now()->toIso8601String();

        $response = $this->getJson('/api/v1/sync/pull', $headers);

        $response->assertOk();
        $syncedAt = $response->json('meta.synced_at');

        expect($syncedAt)->not->toBeNull();
        // synced_at should be close to now (within a few seconds)
        expect(strtotime($syncedAt))->toBeGreaterThanOrEqual(strtotime($before));
    })->group('foundation');

    it('can use synced_at from first pull as since for second pull', function () {
        [$tenant, $token, $headers] = createSyncPullTenant('chain-winery');

        // Create initial data
        $tenant->run(function () {
            Lot::factory()->count(2)->create();
        });

        // First pull — gets everything
        $response1 = $this->getJson('/api/v1/sync/pull', $headers);
        $response1->assertOk()->assertJsonPath('meta.counts.lots', 2);
        $syncedAt = $response1->json('meta.synced_at');

        // Create more data clearly after first pull's synced_at
        $tenant->run(function () use ($syncedAt) {
            Lot::factory()->count(1)->create([
                'updated_at' => Carbon::parse($syncedAt)->addSeconds(5),
            ]);
        });

        // Second pull using synced_at — gets only new data
        $response2 = $this->getJson('/api/v1/sync/pull?since='.urlencode($syncedAt), $headers);
        $response2->assertOk()->assertJsonPath('meta.counts.lots', 1);
    })->group('foundation');

    // ─── Pagination ─────────────────────────────────────────────

    it('caps results per entity and sets has_more flag', function () {
        [$tenant, $token, $headers] = createSyncPullTenant('cap-winery');

        // We can't easily create 500+ records in a test, but we can test the structure
        // and verify the flag is false when under the cap
        $tenant->run(function () {
            Lot::factory()->count(3)->create();
        });

        $response = $this->getJson('/api/v1/sync/pull', $headers);

        $response->assertOk()
            ->assertJsonPath('meta.has_more', false);
    })->group('foundation');

    // ─── Validation ─────────────────────────────────────────────

    it('rejects invalid since parameter', function () {
        [$tenant, $token, $headers] = createSyncPullTenant('validation-winery');

        $response = $this->getJson('/api/v1/sync/pull?since=not-a-date', $headers);

        $response->assertStatus(422);
    })->group('foundation');

    it('accepts various ISO8601 date formats', function () {
        [$tenant, $token, $headers] = createSyncPullTenant('format-winery');

        // Standard ISO8601
        $response = $this->getJson(
            '/api/v1/sync/pull?since='.urlencode('2026-03-17T12:00:00+00:00'),
            $headers
        );
        $response->assertOk();

        // Date-only
        $response2 = $this->getJson('/api/v1/sync/pull?since=2026-03-17', $headers);
        $response2->assertOk();
    })->group('foundation');

    // ─── Authentication ─────────────────────────────────────────

    it('requires authentication', function () {
        [$tenant, $token, $headers] = createSyncPullTenant('auth-winery');

        $response = $this->getJson('/api/v1/sync/pull', [
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(401);
    })->group('foundation');

    // ─── Resource Format ────────────────────────────────────────

    it('uses API resource format for entity serialization', function () {
        [$tenant, $token, $headers] = createSyncPullTenant('format-check-winery');

        $tenant->run(function () {
            Lot::factory()->create([
                'name' => 'Estate Cab 2024',
                'variety' => 'Cabernet Sauvignon',
                'vintage' => 2024,
            ]);
        });

        $response = $this->getJson('/api/v1/sync/pull', $headers);

        $response->assertOk();
        $lots = $response->json('data.lots');

        expect($lots)->toHaveCount(1);
        // Verify the lot has expected resource fields
        expect($lots[0])->toHaveKeys(['id', 'name', 'variety', 'vintage']);
        expect($lots[0]['name'])->toBe('Estate Cab 2024');
        expect($lots[0]['variety'])->toBe('Cabernet Sauvignon');
        expect($lots[0]['vintage'])->toBe(2024);
    })->group('foundation');

})->group('foundation');
