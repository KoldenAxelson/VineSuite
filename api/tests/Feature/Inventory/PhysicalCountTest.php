<?php

declare(strict_types=1);

use App\Models\CaseGoodsSku;
use App\Models\Event;
use App\Models\Location;
use App\Models\PhysicalCount;
use App\Models\PhysicalCountLine;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PhysicalCountService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createCountTestTenant(string $slug = 'count-winery', string $role = 'winemaker'): array
{
    if (function_exists('tenancy') && tenancy()->initialized) {
        tenancy()->end();
    }

    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'pro',
    ]);

    $tenant->run(function () use ($role) {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create([
            'name' => 'Test '.ucfirst($role),
            'email' => "{$role}@example.com",
            'password' => 'SecurePass123!',
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);
    });

    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => "{$role}@example.com",
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    return [$tenant, $loginResponse->json('data.token')];
}

/**
 * Helper: create a location with some stocked SKUs in the current tenant context.
 *
 * @return array{location: Location, skus: array<int, CaseGoodsSku>}
 */
function createCountFixtures(): array
{
    $location = Location::create([
        'name' => 'Test Warehouse',
        'is_active' => true,
    ]);

    $skuA = CaseGoodsSku::create([
        'wine_name' => '2024 Count Cab',
        'vintage' => 2024,
        'varietal' => 'Cabernet Sauvignon',
    ]);

    $skuB = CaseGoodsSku::create([
        'wine_name' => '2024 Count Merlot',
        'vintage' => 2024,
        'varietal' => 'Merlot',
    ]);

    StockLevel::create([
        'sku_id' => $skuA->id,
        'location_id' => $location->id,
        'on_hand' => 100,
        'committed' => 10,
    ]);

    StockLevel::create([
        'sku_id' => $skuB->id,
        'location_id' => $location->id,
        'on_hand' => 50,
        'committed' => 0,
    ]);

    return ['location' => $location, 'skus' => [$skuA, $skuB]];
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

// ─── Tier 1: Event Logging ──────────────────────────────────────

describe('physical count event logging', function () {
    it('writes stock_count_started event with inventory source', function () {
        [$tenant, $token] = createCountTestTenant('count-evt-start');

        $locationId = null;
        $tenant->run(function () use (&$locationId) {
            $fixtures = createCountFixtures();
            $locationId = $fixtures['location']->id;
        });

        $response = test()->postJson('/api/v1/physical-counts/start', [
            'location_id' => $locationId,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $countId = $response->json('data.id');

        $tenant->run(function () use ($countId) {
            $event = Event::where('entity_id', $countId)
                ->where('operation_type', 'stock_count_started')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->entity_type)->toBe('physical_count');
            expect($event->payload['location_name'])->toBe('Test Warehouse');
            expect($event->payload['line_count'])->toBe(2);
        });
    });

    it('writes stock_counted event when count is approved', function () {
        [$tenant, $token] = createCountTestTenant('count-evt-approve');

        $countId = null;
        $locationId = null;
        $tenant->run(function () use (&$countId, &$locationId) {
            $fixtures = createCountFixtures();
            $locationId = $fixtures['location']->id;

            $service = app(PhysicalCountService::class);
            $count = $service->startCount($fixtures['location']->id, User::first()->id);
            $countId = $count->id;

            // Record counts with a variance
            $service->recordCounts($countId, [
                $fixtures['skus'][0]->id => ['counted_quantity' => 95],
                $fixtures['skus'][1]->id => ['counted_quantity' => 50],
            ]);
        });

        $response = test()->postJson("/api/v1/physical-counts/{$countId}/approve", [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();

        $tenant->run(function () use ($countId) {
            $event = Event::where('entity_id', $countId)
                ->where('operation_type', 'stock_counted')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['adjustments_made'])->toBe(1); // Only skuA had variance
        });
    });
})->group('inventory');

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

describe('physical count tenant isolation', function () {
    it('prevents cross-tenant physical count access', function () {
        $tenantA = Tenant::create([
            'name' => 'Winery Alpha',
            'slug' => 'count-iso-alpha',
            'plan' => 'pro',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Winery Beta',
            'slug' => 'count-iso-beta',
            'plan' => 'pro',
        ]);

        $tenantA->run(function () {
            $location = Location::create(['name' => 'Alpha Room']);
            PhysicalCount::create([
                'location_id' => $location->id,
                'status' => 'in_progress',
                'started_by' => (string) Str::uuid(),
                'started_at' => now(),
            ]);
        });

        $tenantB->run(function () {
            expect(PhysicalCount::count())->toBe(0);
            expect(PhysicalCountLine::count())->toBe(0);
        });

        $tenantA->run(function () {
            expect(PhysicalCount::count())->toBe(1);
        });
    });
})->group('inventory');

// ─── Tier 1: Count Workflow Data Integrity ──────────────────────

describe('physical count workflow integrity', function () {
    it('snapshots system quantities when count starts', function () {
        [$tenant, $token] = createCountTestTenant('count-snapshot');

        $locationId = null;
        $tenant->run(function () use (&$locationId) {
            $fixtures = createCountFixtures();
            $locationId = $fixtures['location']->id;
        });

        $response = test()->postJson('/api/v1/physical-counts/start', [
            'location_id' => $locationId,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $lines = $response->json('data.lines');
        expect($lines)->toHaveCount(2);

        // Lines should have system_quantity from stock levels (100 and 50)
        $systemQtys = array_column($lines, 'system_quantity');
        sort($systemQtys);
        expect($systemQtys)->toBe([50, 100]);

        // counted_quantity and variance should be null initially
        foreach ($lines as $line) {
            expect($line['counted_quantity'])->toBeNull();
            expect($line['variance'])->toBeNull();
        }
    });

    it('computes variance correctly after recording counts', function () {
        [$tenant, $token] = createCountTestTenant('count-variance');

        $countId = null;
        $skuAId = null;
        $skuBId = null;
        $tenant->run(function () use (&$countId, &$skuAId, &$skuBId) {
            $fixtures = createCountFixtures();
            $skuAId = $fixtures['skus'][0]->id;
            $skuBId = $fixtures['skus'][1]->id;

            $service = app(PhysicalCountService::class);
            $count = $service->startCount($fixtures['location']->id, User::first()->id);
            $countId = $count->id;
        });

        $response = test()->postJson("/api/v1/physical-counts/{$countId}/record", [
            'counts' => [
                ['sku_id' => $skuAId, 'counted_quantity' => 95],  // variance = -5
                ['sku_id' => $skuBId, 'counted_quantity' => 53],  // variance = +3
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();

        $tenant->run(function () use ($countId, $skuAId, $skuBId) {
            $lineA = PhysicalCountLine::where('physical_count_id', $countId)
                ->where('sku_id', $skuAId)->first();
            $lineB = PhysicalCountLine::where('physical_count_id', $countId)
                ->where('sku_id', $skuBId)->first();

            expect($lineA->counted_quantity)->toBe(95);
            expect($lineA->variance)->toBe(-5);
            expect($lineB->counted_quantity)->toBe(53);
            expect($lineB->variance)->toBe(3);
        });
    });

    it('writes stock adjustments for non-zero variances on approval', function () {
        [$tenant, $token] = createCountTestTenant('count-adj');

        $countId = null;
        $skuAId = null;
        $skuBId = null;
        $locationId = null;
        $tenant->run(function () use (&$countId, &$skuAId, &$skuBId, &$locationId) {
            $fixtures = createCountFixtures();
            $skuAId = $fixtures['skus'][0]->id;
            $skuBId = $fixtures['skus'][1]->id;
            $locationId = $fixtures['location']->id;

            $service = app(PhysicalCountService::class);
            $count = $service->startCount($fixtures['location']->id, User::first()->id);
            $countId = $count->id;

            $service->recordCounts($countId, [
                $skuAId => ['counted_quantity' => 90],  // variance = -10
                $skuBId => ['counted_quantity' => 50],  // variance = 0 → no adjustment
            ]);
        });

        test()->postJson("/api/v1/physical-counts/{$countId}/approve", [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertOk();

        $tenant->run(function () use ($skuAId, $skuBId, $locationId, $countId) {
            // Only skuA should have an adjustment movement
            $adjustments = StockMovement::where('movement_type', 'adjusted')
                ->where('reference_type', 'physical_count')
                ->where('reference_id', $countId)
                ->get();

            expect($adjustments)->toHaveCount(1);
            expect($adjustments[0]->sku_id)->toBe($skuAId);
            expect($adjustments[0]->quantity)->toBe(-10);

            // Stock level for skuA should be updated (100 - 10 = 90)
            $stockLevel = StockLevel::where('sku_id', $skuAId)
                ->where('location_id', $locationId)->first();
            expect($stockLevel->on_hand)->toBe(90);

            // Stock level for skuB should be unchanged
            $stockLevelB = StockLevel::where('sku_id', $skuBId)
                ->where('location_id', $locationId)->first();
            expect($stockLevelB->on_hand)->toBe(50);

            // Count status should be completed
            $count = PhysicalCount::find($countId);
            expect($count->status)->toBe('completed');
            expect($count->completed_at)->not->toBeNull();
        });
    });

    it('handles new SKU discovered during count', function () {
        [$tenant, $token] = createCountTestTenant('count-new-sku');

        $countId = null;
        $newSkuId = null;
        $tenant->run(function () use (&$countId, &$newSkuId) {
            $fixtures = createCountFixtures();

            $service = app(PhysicalCountService::class);
            $count = $service->startCount($fixtures['location']->id, User::first()->id);
            $countId = $count->id;

            // Create a new SKU that wasn't in the original snapshot
            $newSku = CaseGoodsSku::create([
                'wine_name' => '2024 Surprise Pinot',
                'vintage' => 2024,
                'varietal' => 'Pinot Noir',
            ]);
            $newSkuId = $newSku->id;
        });

        // Record counts including the new SKU
        $response = test()->postJson("/api/v1/physical-counts/{$countId}/record", [
            'counts' => [
                ['sku_id' => $newSkuId, 'counted_quantity' => 12],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();

        $tenant->run(function () use ($countId, $newSkuId) {
            $line = PhysicalCountLine::where('physical_count_id', $countId)
                ->where('sku_id', $newSkuId)->first();

            expect($line)->not->toBeNull();
            expect($line->system_quantity)->toBe(0); // wasn't in original snapshot
            expect($line->counted_quantity)->toBe(12);
            expect($line->variance)->toBe(12);
        });
    });

    it('cancel does not write stock adjustments', function () {
        [$tenant, $token] = createCountTestTenant('count-cancel');

        $countId = null;
        $tenant->run(function () use (&$countId) {
            $fixtures = createCountFixtures();

            $service = app(PhysicalCountService::class);
            $count = $service->startCount($fixtures['location']->id, User::first()->id);
            $countId = $count->id;

            $service->recordCounts($countId, [
                $fixtures['skus'][0]->id => ['counted_quantity' => 80], // variance = -20
            ]);
        });

        test()->postJson("/api/v1/physical-counts/{$countId}/cancel", [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertOk();

        $tenant->run(function () use ($countId) {
            // No adjustments should exist
            $adjustments = StockMovement::where('reference_type', 'physical_count')
                ->where('reference_id', $countId)
                ->count();
            expect($adjustments)->toBe(0);

            // Count should be cancelled
            $count = PhysicalCount::find($countId);
            expect($count->status)->toBe('cancelled');

            // Original stock should be unchanged
            $stockLevel = StockLevel::first();
            expect($stockLevel->on_hand)->toBe(100);
        });
    });
})->group('inventory');

// ─── Tier 2: API CRUD ────────────────────────────────────────────

describe('physical count API', function () {
    it('lists count sessions with pagination', function () {
        [$tenant, $token] = createCountTestTenant('count-list');

        $tenant->run(function () {
            $location = Location::create(['name' => 'List Room']);

            PhysicalCount::create([
                'location_id' => $location->id,
                'status' => 'in_progress',
                'started_by' => User::first()->id,
                'started_at' => now(),
            ]);

            PhysicalCount::create([
                'location_id' => $location->id,
                'status' => 'completed',
                'started_by' => User::first()->id,
                'started_at' => now()->subDay(),
                'completed_by' => User::first()->id,
                'completed_at' => now(),
            ]);
        });

        $response = test()->getJson('/api/v1/physical-counts', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(2);
        expect($response->json('meta.total'))->toBe(2);
    });

    it('filters count sessions by status', function () {
        [$tenant, $token] = createCountTestTenant('count-filter-status');

        $tenant->run(function () {
            $location = Location::create(['name' => 'Filter Room']);

            PhysicalCount::create([
                'location_id' => $location->id,
                'status' => 'in_progress',
                'started_by' => User::first()->id,
                'started_at' => now(),
            ]);

            PhysicalCount::create([
                'location_id' => $location->id,
                'status' => 'completed',
                'started_by' => User::first()->id,
                'started_at' => now()->subDay(),
                'completed_by' => User::first()->id,
                'completed_at' => now(),
            ]);
        });

        $response = test()->getJson('/api/v1/physical-counts?status=in_progress', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.status'))->toBe('in_progress');
    });

    it('filters count sessions by location_id', function () {
        [$tenant, $token] = createCountTestTenant('count-filter-loc');

        $locationAId = null;
        $tenant->run(function () use (&$locationAId) {
            $locationA = Location::create(['name' => 'Room A']);
            $locationB = Location::create(['name' => 'Room B']);
            $locationAId = $locationA->id;

            PhysicalCount::create([
                'location_id' => $locationA->id,
                'status' => 'in_progress',
                'started_by' => User::first()->id,
                'started_at' => now(),
            ]);

            PhysicalCount::create([
                'location_id' => $locationB->id,
                'status' => 'in_progress',
                'started_by' => User::first()->id,
                'started_at' => now(),
            ]);
        });

        $response = test()->getJson("/api/v1/physical-counts?location_id={$locationAId}", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.location_id'))->toBe($locationAId);
    });

    it('shows count session detail with lines', function () {
        [$tenant, $token] = createCountTestTenant('count-show');

        $countId = null;
        $tenant->run(function () use (&$countId) {
            $fixtures = createCountFixtures();

            $service = app(PhysicalCountService::class);
            $count = $service->startCount($fixtures['location']->id, User::first()->id);
            $countId = $count->id;
        });

        $response = test()->getJson("/api/v1/physical-counts/{$countId}", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();

        $data = $response->json('data');
        expect($data['id'])->toBe($countId);
        expect($data['status'])->toBe('in_progress');
        expect($data['lines'])->toHaveCount(2);
        expect($data['location']['name'])->toBe('Test Warehouse');

        // Lines should include SKU details
        foreach ($data['lines'] as $line) {
            expect($line['sku'])->not->toBeNull();
            expect($line['sku'])->toHaveKeys(['id', 'wine_name', 'vintage', 'varietal']);
        }
    });
})->group('inventory');

// ─── Tier 2: Validation ──────────────────────────────────────────

describe('physical count validation', function () {
    it('rejects start without location_id', function () {
        [$tenant, $token] = createCountTestTenant('count-val-no-loc');

        $response = test()->postJson('/api/v1/physical-counts/start', [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('location_id');
    });

    it('rejects start with non-existent location', function () {
        [$tenant, $token] = createCountTestTenant('count-val-bad-loc');

        $response = test()->postJson('/api/v1/physical-counts/start', [
            'location_id' => '00000000-0000-0000-0000-000000000000',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
    });

    it('rejects record with invalid counted_quantity', function () {
        [$tenant, $token] = createCountTestTenant('count-val-qty');

        $countId = null;
        $skuId = null;
        $tenant->run(function () use (&$countId, &$skuId) {
            $fixtures = createCountFixtures();
            $skuId = $fixtures['skus'][0]->id;

            $service = app(PhysicalCountService::class);
            $count = $service->startCount($fixtures['location']->id, User::first()->id);
            $countId = $count->id;
        });

        $response = test()->postJson("/api/v1/physical-counts/{$countId}/record", [
            'counts' => [
                ['sku_id' => $skuId, 'counted_quantity' => -5],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
    });

    it('rejects approve on non-in-progress count', function () {
        [$tenant, $token] = createCountTestTenant('count-val-approve');

        $countId = null;
        $tenant->run(function () use (&$countId) {
            $location = Location::create(['name' => 'Done Room']);
            $count = PhysicalCount::create([
                'location_id' => $location->id,
                'status' => 'completed',
                'started_by' => User::first()->id,
                'started_at' => now()->subHour(),
                'completed_by' => User::first()->id,
                'completed_at' => now(),
            ]);
            $countId = $count->id;
        });

        $response = test()->postJson("/api/v1/physical-counts/{$countId}/approve", [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(500); // InvalidArgumentException from service
    });
})->group('inventory');

// ─── Tier 2: RBAC ────────────────────────────────────────────────

describe('physical count RBAC', function () {
    it('winemaker can start a count', function () {
        [$tenant, $token] = createCountTestTenant('count-rbac-wm', 'winemaker');

        $locationId = null;
        $tenant->run(function () use (&$locationId) {
            $location = Location::create(['name' => 'RBAC Room']);
            $locationId = $location->id;
        });

        test()->postJson('/api/v1/physical-counts/start', [
            'location_id' => $locationId,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    });

    it('read-only users cannot start a count', function () {
        [$tenant, $token] = createCountTestTenant('count-rbac-ro', 'read_only');

        $locationId = null;
        $tenant->run(function () use (&$locationId) {
            $location = Location::create(['name' => 'Blocked Room']);
            $locationId = $location->id;
        });

        test()->postJson('/api/v1/physical-counts/start', [
            'location_id' => $locationId,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });

    it('cellar_hand cannot start a count', function () {
        [$tenant, $token] = createCountTestTenant('count-rbac-ch', 'cellar_hand');

        $locationId = null;
        $tenant->run(function () use (&$locationId) {
            $location = Location::create(['name' => 'CH Room']);
            $locationId = $location->id;
        });

        test()->postJson('/api/v1/physical-counts/start', [
            'location_id' => $locationId,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });

    it('read-only users can list and view count sessions', function () {
        [$tenant, $token] = createCountTestTenant('count-rbac-ro-view', 'read_only');

        $countId = null;
        $tenant->run(function () use (&$countId) {
            $location = Location::create(['name' => 'Viewable Room']);
            $count = PhysicalCount::create([
                'location_id' => $location->id,
                'status' => 'in_progress',
                'started_by' => User::first()->id,
                'started_at' => now(),
            ]);
            $countId = $count->id;
        });

        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ];

        test()->getJson('/api/v1/physical-counts', $headers)->assertOk();
        test()->getJson("/api/v1/physical-counts/{$countId}", $headers)->assertOk();
    });
})->group('inventory');

// ─── Tier 2: API Envelope ────────────────────────────────────────

describe('physical count API envelope', function () {
    it('returns responses in the standard API envelope format', function () {
        [$tenant, $token] = createCountTestTenant('count-envelope');

        $locationId = null;
        $tenant->run(function () use (&$locationId) {
            $location = Location::create(['name' => 'Envelope Room']);
            $locationId = $location->id;
        });

        $response = test()->postJson('/api/v1/physical-counts/start', [
            'location_id' => $locationId,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'location_id', 'status', 'started_at', 'lines'],
            'meta',
            'errors',
        ]);
        expect($response->json('errors'))->toBe([]);
    });

    it('rejects unauthenticated access', function () {
        test()->getJson('/api/v1/physical-counts', [
            'X-Tenant-ID' => 'fake-tenant',
        ])->assertStatus(401);
    });
})->group('inventory');
