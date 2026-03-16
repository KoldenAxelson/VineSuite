<?php

declare(strict_types=1);

use App\Models\CaseGoodsSku;
use App\Models\Event;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createSkuTestTenant(string $slug = 'sku-winery', string $role = 'winemaker'): array
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
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

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

// ─── Tier 1: Event Source Partitioning ────────────────────────────

describe('event_source column', function () {
    it('writes event_source=production for production events', function () {
        [$tenant, $token] = createSkuTestTenant('evt-src-prod');

        $tenant->run(function () {
            $lot = Lot::create([
                'name' => 'Source Test Lot',
                'variety' => 'Cabernet Sauvignon',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 500,
                'status' => 'in_progress',
            ]);

            $logger = app(\App\Services\EventLogger::class);
            $event = $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'lot_created',
                payload: ['name' => $lot->name],
                performedBy: User::first()->id,
                performedAt: now(),
            );

            expect($event->event_source)->toBe('production');
        });
    });

    it('writes event_source=lab for lab events', function () {
        [$tenant, $token] = createSkuTestTenant('evt-src-lab');

        $tenant->run(function () {
            $lot = Lot::create([
                'name' => 'Lab Source Lot',
                'variety' => 'Chardonnay',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 500,
                'status' => 'in_progress',
            ]);

            $logger = app(\App\Services\EventLogger::class);

            $event = $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'lab_analysis_entered',
                payload: ['test_type' => 'pH'],
                performedBy: User::first()->id,
                performedAt: now(),
            );
            expect($event->event_source)->toBe('lab');

            $event2 = $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'fermentation_round_created',
                payload: ['type' => 'primary'],
                performedBy: User::first()->id,
                performedAt: now(),
            );
            expect($event2->event_source)->toBe('lab');

            $event3 = $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'sensory_note_recorded',
                payload: ['rating' => 4],
                performedBy: User::first()->id,
                performedAt: now(),
            );
            expect($event3->event_source)->toBe('lab');
        });
    });

    it('writes event_source=inventory for inventory events', function () {
        [$tenant, $token] = createSkuTestTenant('evt-src-inv');

        $tenant->run(function () {
            $logger = app(\App\Services\EventLogger::class);
            $userId = User::first()->id;
            $fakeId = (string) \Illuminate\Support\Str::uuid();

            $stockEvent = $logger->log(
                entityType: 'sku',
                entityId: $fakeId,
                operationType: 'stock_received',
                payload: ['quantity' => 100],
                performedBy: $userId,
                performedAt: now(),
            );
            expect($stockEvent->event_source)->toBe('inventory');

            $dryEvent = $logger->log(
                entityType: 'dry_goods',
                entityId: $fakeId,
                operationType: 'dry_goods_consumed',
                payload: ['quantity' => 50],
                performedBy: $userId,
                performedAt: now(),
            );
            expect($dryEvent->event_source)->toBe('inventory');

            $rawEvent = $logger->log(
                entityType: 'raw_material',
                entityId: $fakeId,
                operationType: 'raw_material_consumed',
                payload: ['quantity' => 10],
                performedBy: $userId,
                performedAt: now(),
            );
            expect($rawEvent->event_source)->toBe('inventory');

            $poEvent = $logger->log(
                entityType: 'purchase_order',
                entityId: $fakeId,
                operationType: 'purchase_order_created',
                payload: ['vendor' => 'Acme'],
                performedBy: $userId,
                performedAt: now(),
            );
            expect($poEvent->event_source)->toBe('inventory');

            $equipEvent = $logger->log(
                entityType: 'equipment',
                entityId: $fakeId,
                operationType: 'equipment_maintained',
                payload: ['type' => 'calibration'],
                performedBy: $userId,
                performedAt: now(),
            );
            expect($equipEvent->event_source)->toBe('inventory');
        });
    });

    it('defaults unknown operation types to production source', function () {
        [$tenant, $token] = createSkuTestTenant('evt-src-default');

        $tenant->run(function () {
            $logger = app(\App\Services\EventLogger::class);
            $fakeId = (string) \Illuminate\Support\Str::uuid();

            $event = $logger->log(
                entityType: 'lot',
                entityId: $fakeId,
                operationType: 'some_unknown_operation',
                payload: ['data' => 'test'],
                performedBy: User::first()->id,
                performedAt: now(),
            );

            expect($event->event_source)->toBe('production');
        });
    });
})->group('inventory');

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

describe('tenant isolation', function () {
    it('prevents cross-tenant SKU data access', function () {
        $tenantA = Tenant::create([
            'name' => 'Winery Alpha',
            'slug' => 'sku-iso-alpha',
            'plan' => 'pro',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Winery Beta',
            'slug' => 'sku-iso-beta',
            'plan' => 'pro',
        ]);

        $tenantA->run(function () {
            CaseGoodsSku::create([
                'wine_name' => '2024 Alpha Cabernet',
                'vintage' => 2024,
                'varietal' => 'Cabernet Sauvignon',
                'format' => '750ml',
                'case_size' => 12,
                'is_active' => true,
            ]);
        });

        $tenantB->run(function () {
            expect(CaseGoodsSku::count())->toBe(0);
        });

        $tenantA->run(function () {
            expect(CaseGoodsSku::count())->toBe(1);
        });
    });
})->group('inventory');

// ─── Tier 2: CRUD ────────────────────────────────────────────────

describe('SKU CRUD', function () {
    it('creates a case goods SKU with all fields', function () {
        [$tenant, $token] = createSkuTestTenant('sku-crud-create');

        $lotId = null;
        $tenant->run(function () use (&$lotId) {
            $lot = Lot::create([
                'name' => 'Origin Lot',
                'variety' => 'Pinot Noir',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 1000,
                'status' => 'bottled',
            ]);
            $lotId = $lot->id;
        });

        $response = test()->postJson('/api/v1/skus', [
            'wine_name' => '2024 Estate Pinot Noir',
            'vintage' => 2024,
            'varietal' => 'Pinot Noir',
            'format' => '750ml',
            'case_size' => 12,
            'upc_barcode' => '012345678901',
            'price' => 45.00,
            'tasting_notes' => 'Rich dark cherry and earthy spice.',
            'lot_id' => $lotId,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        expect($data['wine_name'])->toBe('2024 Estate Pinot Noir');
        expect($data['vintage'])->toBe(2024);
        expect($data['varietal'])->toBe('Pinot Noir');
        expect($data['format'])->toBe('750ml');
        expect($data['case_size'])->toBe(12);
        expect($data['upc_barcode'])->toBe('012345678901');
        expect($data['price'])->toBe('45.00');
        expect($data['tasting_notes'])->toBe('Rich dark cherry and earthy spice.');
        expect($data['lot_id'])->toBe($lotId);
        expect($data['is_active'])->toBeTrue();
        expect($data['lot'])->not->toBeNull();
        expect($data['lot']['name'])->toBe('Origin Lot');
    });

    it('creates a SKU with minimal required fields', function () {
        [$tenant, $token] = createSkuTestTenant('sku-crud-minimal');

        $response = test()->postJson('/api/v1/skus', [
            'wine_name' => '2023 Red Blend',
            'vintage' => 2023,
            'varietal' => 'Red Blend',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        expect($data['wine_name'])->toBe('2023 Red Blend');
        expect($data['format'])->toBe('750ml'); // default
        expect($data['case_size'])->toBe(12);    // default
        expect($data['is_active'])->toBeTrue();   // default
        expect($data['lot_id'])->toBeNull();
        expect($data['upc_barcode'])->toBeNull();
    });

    it('lists SKUs with pagination', function () {
        [$tenant, $token] = createSkuTestTenant('sku-crud-list');

        $tenant->run(function () {
            CaseGoodsSku::create([
                'wine_name' => '2024 Chardonnay',
                'vintage' => 2024,
                'varietal' => 'Chardonnay',
            ]);
            CaseGoodsSku::create([
                'wine_name' => '2024 Cabernet',
                'vintage' => 2024,
                'varietal' => 'Cabernet Sauvignon',
            ]);
            CaseGoodsSku::create([
                'wine_name' => '2023 Merlot',
                'vintage' => 2023,
                'varietal' => 'Merlot',
            ]);
        });

        $response = test()->getJson('/api/v1/skus', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);
        expect($response->json('meta.total'))->toBe(3);
    });

    it('filters SKUs by vintage', function () {
        [$tenant, $token] = createSkuTestTenant('sku-filter-vintage');

        $tenant->run(function () {
            CaseGoodsSku::create([
                'wine_name' => '2024 Cabernet',
                'vintage' => 2024,
                'varietal' => 'Cabernet Sauvignon',
            ]);
            CaseGoodsSku::create([
                'wine_name' => '2023 Merlot',
                'vintage' => 2023,
                'varietal' => 'Merlot',
            ]);
        });

        $response = test()->getJson('/api/v1/skus?vintage=2024', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.vintage'))->toBe(2024);
    });

    it('filters SKUs by varietal', function () {
        [$tenant, $token] = createSkuTestTenant('sku-filter-varietal');

        $tenant->run(function () {
            CaseGoodsSku::create([
                'wine_name' => '2024 Chardonnay',
                'vintage' => 2024,
                'varietal' => 'Chardonnay',
            ]);
            CaseGoodsSku::create([
                'wine_name' => '2024 Pinot Noir',
                'vintage' => 2024,
                'varietal' => 'Pinot Noir',
            ]);
        });

        $response = test()->getJson('/api/v1/skus?varietal=Chardonnay', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.varietal'))->toBe('Chardonnay');
    });

    it('filters SKUs by format', function () {
        [$tenant, $token] = createSkuTestTenant('sku-filter-format');

        $tenant->run(function () {
            CaseGoodsSku::create([
                'wine_name' => '2024 Standard Cab',
                'vintage' => 2024,
                'varietal' => 'Cabernet Sauvignon',
                'format' => '750ml',
            ]);
            CaseGoodsSku::create([
                'wine_name' => '2024 Magnum Cab',
                'vintage' => 2024,
                'varietal' => 'Cabernet Sauvignon',
                'format' => '1.5L',
            ]);
        });

        $response = test()->getJson('/api/v1/skus?format=1.5L', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.format'))->toBe('1.5L');
    });

    it('filters SKUs by active status', function () {
        [$tenant, $token] = createSkuTestTenant('sku-filter-active');

        $tenant->run(function () {
            CaseGoodsSku::create([
                'wine_name' => '2024 Active Wine',
                'vintage' => 2024,
                'varietal' => 'Merlot',
                'is_active' => true,
            ]);
            CaseGoodsSku::create([
                'wine_name' => '2022 Discontinued Wine',
                'vintage' => 2022,
                'varietal' => 'Zinfandel',
                'is_active' => false,
            ]);
        });

        $activeResponse = test()->getJson('/api/v1/skus?is_active=1', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $activeResponse->assertOk();
        expect($activeResponse->json('data'))->toHaveCount(1);
        expect($activeResponse->json('data.0.is_active'))->toBeTrue();
    });

    it('shows SKU detail with relationships', function () {
        [$tenant, $token] = createSkuTestTenant('sku-show');

        $skuId = null;
        $tenant->run(function () use (&$skuId) {
            $lot = Lot::create([
                'name' => 'Show Lot',
                'variety' => 'Syrah',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 800,
                'status' => 'bottled',
            ]);

            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Estate Syrah',
                'vintage' => 2024,
                'varietal' => 'Syrah',
                'format' => '750ml',
                'case_size' => 12,
                'price' => 38.00,
                'lot_id' => $lot->id,
            ]);
            $skuId = $sku->id;
        });

        $response = test()->getJson("/api/v1/skus/{$skuId}", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();

        $data = $response->json('data');
        expect($data['id'])->toBe($skuId);
        expect($data['wine_name'])->toBe('2024 Estate Syrah');
        expect($data['lot'])->not->toBeNull();
        expect($data['lot']['name'])->toBe('Show Lot');
    });

    it('updates an existing SKU', function () {
        [$tenant, $token] = createSkuTestTenant('sku-update');

        $skuId = null;
        $tenant->run(function () use (&$skuId) {
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Estate Cab',
                'vintage' => 2024,
                'varietal' => 'Cabernet Sauvignon',
                'price' => 35.00,
            ]);
            $skuId = $sku->id;
        });

        $response = test()->putJson("/api/v1/skus/{$skuId}", [
            'price' => 42.00,
            'tasting_notes' => 'Updated: Bold blackberry with oak.',
            'is_active' => false,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();

        $data = $response->json('data');
        expect($data['price'])->toBe('42.00');
        expect($data['tasting_notes'])->toBe('Updated: Bold blackberry with oak.');
        expect($data['is_active'])->toBeFalse();
        // Unchanged fields should persist
        expect($data['wine_name'])->toBe('2024 Estate Cab');
    });
})->group('inventory');

// ─── Tier 2: Validation ──────────────────────────────────────────

describe('SKU validation', function () {
    it('rejects SKU creation with missing required fields', function () {
        [$tenant, $token] = createSkuTestTenant('sku-val-missing');

        $response = test()->postJson('/api/v1/skus', [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('wine_name');
        expect($fields)->toContain('vintage');
        expect($fields)->toContain('varietal');
    });

    it('rejects invalid format value', function () {
        [$tenant, $token] = createSkuTestTenant('sku-val-format');

        $response = test()->postJson('/api/v1/skus', [
            'wine_name' => '2024 Bad Format',
            'vintage' => 2024,
            'varietal' => 'Merlot',
            'format' => '2.0L', // Not in FORMATS constant
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('format');
    });

    it('rejects invalid case_size value', function () {
        [$tenant, $token] = createSkuTestTenant('sku-val-casesize');

        $response = test()->postJson('/api/v1/skus', [
            'wine_name' => '2024 Bad Case Size',
            'vintage' => 2024,
            'varietal' => 'Merlot',
            'case_size' => 8, // Only 6 or 12 allowed
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('case_size');
    });

    it('rejects negative price', function () {
        [$tenant, $token] = createSkuTestTenant('sku-val-price');

        $response = test()->postJson('/api/v1/skus', [
            'wine_name' => '2024 Negative Price',
            'vintage' => 2024,
            'varietal' => 'Merlot',
            'price' => -10.00,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('price');
    });

    it('rejects non-existent lot_id', function () {
        [$tenant, $token] = createSkuTestTenant('sku-val-lot');

        $response = test()->postJson('/api/v1/skus', [
            'wine_name' => '2024 Bad Lot',
            'vintage' => 2024,
            'varietal' => 'Merlot',
            'lot_id' => '00000000-0000-0000-0000-000000000000',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('lot_id');
    });
})->group('inventory');

// ─── Tier 2: RBAC ────────────────────────────────────────────────

describe('SKU RBAC', function () {
    it('winemaker can create SKUs', function () {
        [$tenant, $token] = createSkuTestTenant('sku-rbac-wm', 'winemaker');

        test()->postJson('/api/v1/skus', [
            'wine_name' => '2024 Winemaker SKU',
            'vintage' => 2024,
            'varietal' => 'Pinot Noir',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    });

    it('read-only users cannot create SKUs', function () {
        [$tenant, $token] = createSkuTestTenant('sku-rbac-ro', 'read_only');

        test()->postJson('/api/v1/skus', [
            'wine_name' => '2024 Read Only SKU',
            'vintage' => 2024,
            'varietal' => 'Merlot',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });

    it('cellar_hand cannot create SKUs', function () {
        [$tenant, $token] = createSkuTestTenant('sku-rbac-ch', 'cellar_hand');

        test()->postJson('/api/v1/skus', [
            'wine_name' => '2024 Cellar Hand SKU',
            'vintage' => 2024,
            'varietal' => 'Merlot',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });

    it('read-only users can list and view SKUs', function () {
        [$tenant, $token] = createSkuTestTenant('sku-rbac-ro-view', 'read_only');

        $skuId = null;
        $tenant->run(function () use (&$skuId) {
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Viewable SKU',
                'vintage' => 2024,
                'varietal' => 'Cabernet Sauvignon',
            ]);
            $skuId = $sku->id;
        });

        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ];

        // Can list
        test()->getJson('/api/v1/skus', $headers)->assertOk();

        // Can view
        test()->getJson("/api/v1/skus/{$skuId}", $headers)->assertOk();
    });

    it('read-only users cannot update SKUs', function () {
        [$tenant, $token] = createSkuTestTenant('sku-rbac-ro-update', 'read_only');

        $skuId = null;
        $tenant->run(function () use (&$skuId) {
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 No Update SKU',
                'vintage' => 2024,
                'varietal' => 'Merlot',
            ]);
            $skuId = $sku->id;
        });

        test()->putJson("/api/v1/skus/{$skuId}", [
            'price' => 99.00,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });
})->group('inventory');

// ─── Tier 2: API Envelope ────────────────────────────────────────

describe('SKU API envelope', function () {
    it('returns responses in the standard API envelope format', function () {
        [$tenant, $token] = createSkuTestTenant('sku-envelope');

        $response = test()->postJson('/api/v1/skus', [
            'wine_name' => '2024 Envelope Test',
            'vintage' => 2024,
            'varietal' => 'Riesling',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'wine_name', 'vintage', 'varietal', 'format', 'case_size', 'is_active'],
            'meta',
            'errors',
        ]);
        expect($response->json('errors'))->toBe([]);
    });

    it('rejects unauthenticated access', function () {
        test()->getJson('/api/v1/skus', [
            'X-Tenant-ID' => 'fake-tenant',
        ])->assertStatus(401);
    });
})->group('inventory');
