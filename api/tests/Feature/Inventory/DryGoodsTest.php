<?php

declare(strict_types=1);

use App\Models\DryGoodsItem;
use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createDryGoodsTestTenant(string $slug = 'dg-winery', string $role = 'admin'): array
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

describe('dry goods event logging', function () {
    it('writes dry_goods_created event with inventory source', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-evt-create');

        $response = test()->postJson('/api/v1/dry-goods', [
            'name' => '750ml Burgundy Green',
            'item_type' => 'bottle',
            'unit_of_measure' => 'each',
            'on_hand' => 5000,
            'cost_per_unit' => 0.85,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $itemId = $response->json('data.id');

        $tenant->run(function () use ($itemId) {
            $event = Event::where('entity_id', $itemId)
                ->where('operation_type', 'dry_goods_created')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->entity_type)->toBe('dry_goods_item');
            expect($event->payload['name'])->toBe('750ml Burgundy Green');
            expect($event->payload['item_type'])->toBe('bottle');
            expect($event->payload['on_hand'])->toEqual(5000);
        });
    });

    it('writes dry_goods_updated event with inventory source', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-evt-update');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = DryGoodsItem::create([
                'name' => 'Natural Cork Grade A',
                'item_type' => 'cork',
                'unit_of_measure' => 'sleeve',
                'on_hand' => 200,
            ]);
            $itemId = $item->id;
        });

        test()->putJson("/api/v1/dry-goods/{$itemId}", [
            'on_hand' => 150,
            'is_active' => false,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertOk();

        $tenant->run(function () use ($itemId) {
            $event = Event::where('entity_id', $itemId)
                ->where('operation_type', 'dry_goods_updated')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['is_active'])->toBeFalse();
        });
    });
})->group('inventory');

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

describe('dry goods tenant isolation', function () {
    it('prevents cross-tenant dry goods access', function () {
        $tenantA = Tenant::create([
            'name' => 'Winery Alpha',
            'slug' => 'dg-iso-alpha',
            'plan' => 'pro',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Winery Beta',
            'slug' => 'dg-iso-beta',
            'plan' => 'pro',
        ]);

        $tenantA->run(function () {
            DryGoodsItem::create([
                'name' => 'Alpha Bottles',
                'item_type' => 'bottle',
                'unit_of_measure' => 'each',
                'on_hand' => 1000,
            ]);
        });

        $tenantB->run(function () {
            expect(DryGoodsItem::count())->toBe(0);
        });

        $tenantA->run(function () {
            expect(DryGoodsItem::count())->toBe(1);
        });
    });
})->group('inventory');

// ─── Tier 1: Data Integrity ─────────────────────────────────────

describe('dry goods data integrity', function () {
    it('correctly identifies items below reorder point', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-reorder');

        $tenant->run(function () {
            DryGoodsItem::create([
                'name' => 'Low Cork',
                'item_type' => 'cork',
                'unit_of_measure' => 'sleeve',
                'on_hand' => 10,
                'reorder_point' => 100,
            ]);

            DryGoodsItem::create([
                'name' => 'Full Cork',
                'item_type' => 'cork',
                'unit_of_measure' => 'sleeve',
                'on_hand' => 500,
                'reorder_point' => 100,
            ]);

            DryGoodsItem::create([
                'name' => 'No Threshold',
                'item_type' => 'bottle',
                'unit_of_measure' => 'each',
                'on_hand' => 5,
            ]);

            $belowReorder = DryGoodsItem::belowReorderPoint()->get();
            expect($belowReorder)->toHaveCount(1);
            expect($belowReorder->first()->name)->toBe('Low Cork');
        });
    });

    it('needsReorder returns correct boolean', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-needs-reorder');

        $tenant->run(function () {
            $lowItem = DryGoodsItem::create([
                'name' => 'Low Stock Item',
                'item_type' => 'capsule',
                'unit_of_measure' => 'each',
                'on_hand' => 50,
                'reorder_point' => 100,
            ]);
            expect($lowItem->needsReorder())->toBeTrue();

            $okItem = DryGoodsItem::create([
                'name' => 'OK Stock Item',
                'item_type' => 'capsule',
                'unit_of_measure' => 'each',
                'on_hand' => 500,
                'reorder_point' => 100,
            ]);
            expect($okItem->needsReorder())->toBeFalse();

            $noThreshold = DryGoodsItem::create([
                'name' => 'No Threshold',
                'item_type' => 'bottle',
                'unit_of_measure' => 'each',
                'on_hand' => 1,
            ]);
            expect($noThreshold->needsReorder())->toBeFalse();
        });
    });

    it('stores decimal quantities correctly', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-decimal');

        $tenant->run(function () {
            $item = DryGoodsItem::create([
                'name' => 'Partial Pallet',
                'item_type' => 'carton',
                'unit_of_measure' => 'pallet',
                'on_hand' => 2.5,
                'cost_per_unit' => 12.3456,
            ]);

            $item->refresh();
            expect((float) $item->on_hand)->toBe(2.50);
            expect((float) $item->cost_per_unit)->toBe(12.3456);
        });
    });
})->group('inventory');

// ─── Tier 2: CRUD ────────────────────────────────────────────────

describe('dry goods CRUD', function () {
    it('creates a dry goods item with all fields', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-crud-all');

        $response = test()->postJson('/api/v1/dry-goods', [
            'name' => '750ml Bordeaux Clear',
            'item_type' => 'bottle',
            'unit_of_measure' => 'each',
            'on_hand' => 10000,
            'reorder_point' => 2000,
            'cost_per_unit' => 0.92,
            'vendor_name' => 'Pacific Glass Co.',
            'is_active' => true,
            'notes' => 'Lead time: 6 weeks',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        expect($data['name'])->toBe('750ml Bordeaux Clear');
        expect($data['item_type'])->toBe('bottle');
        expect($data['unit_of_measure'])->toBe('each');
        expect($data['on_hand'])->toEqual(10000);
        expect($data['reorder_point'])->toEqual(2000);
        expect($data['cost_per_unit'])->toEqual(0.92);
        expect($data['vendor_name'])->toBe('Pacific Glass Co.');
        expect($data['is_active'])->toBeTrue();
        expect($data['notes'])->toBe('Lead time: 6 weeks');
        expect($data['needs_reorder'])->toBeFalse();
    });

    it('creates with minimal required fields', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-crud-min');

        $response = test()->postJson('/api/v1/dry-goods', [
            'name' => 'Basic Cork',
            'item_type' => 'cork',
            'unit_of_measure' => 'sleeve',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        expect($data['name'])->toBe('Basic Cork');
        expect($data['on_hand'])->toEqual(0); // default
        expect($data['reorder_point'])->toBeNull();
        expect($data['cost_per_unit'])->toBeNull();
        expect($data['vendor_name'])->toBeNull();
        expect($data['is_active'])->toBeTrue(); // default
    });

    it('lists dry goods with pagination', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-crud-list');

        $tenant->run(function () {
            DryGoodsItem::create(['name' => 'Item A', 'item_type' => 'bottle', 'unit_of_measure' => 'each']);
            DryGoodsItem::create(['name' => 'Item B', 'item_type' => 'cork', 'unit_of_measure' => 'sleeve']);
            DryGoodsItem::create(['name' => 'Item C', 'item_type' => 'capsule', 'unit_of_measure' => 'each']);
        });

        $response = test()->getJson('/api/v1/dry-goods', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);
        expect($response->json('meta.total'))->toBe(3);
    });

    it('filters by item_type', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-filter-type');

        $tenant->run(function () {
            DryGoodsItem::create(['name' => 'Bottle A', 'item_type' => 'bottle', 'unit_of_measure' => 'each']);
            DryGoodsItem::create(['name' => 'Cork A', 'item_type' => 'cork', 'unit_of_measure' => 'sleeve']);
        });

        $response = test()->getJson('/api/v1/dry-goods?item_type=bottle', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.item_type'))->toBe('bottle');
    });

    it('filters by active status', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-filter-active');

        $tenant->run(function () {
            DryGoodsItem::create(['name' => 'Active Item', 'item_type' => 'bottle', 'unit_of_measure' => 'each', 'is_active' => true]);
            DryGoodsItem::create(['name' => 'Inactive Item', 'item_type' => 'cork', 'unit_of_measure' => 'sleeve', 'is_active' => false]);
        });

        $response = test()->getJson('/api/v1/dry-goods?is_active=1', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.name'))->toBe('Active Item');
    });

    it('filters items below reorder point', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-filter-reorder');

        $tenant->run(function () {
            DryGoodsItem::create(['name' => 'Low Stock', 'item_type' => 'cork', 'unit_of_measure' => 'sleeve', 'on_hand' => 10, 'reorder_point' => 100]);
            DryGoodsItem::create(['name' => 'OK Stock', 'item_type' => 'bottle', 'unit_of_measure' => 'each', 'on_hand' => 5000, 'reorder_point' => 1000]);
        });

        $response = test()->getJson('/api/v1/dry-goods?below_reorder=1', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.name'))->toBe('Low Stock');
    });

    it('shows a single dry goods item', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-show');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = DryGoodsItem::create([
                'name' => 'Show Test Capsule',
                'item_type' => 'capsule',
                'unit_of_measure' => 'each',
                'on_hand' => 2500,
                'cost_per_unit' => 0.15,
            ]);
            $itemId = $item->id;
        });

        $response = test()->getJson("/api/v1/dry-goods/{$itemId}", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data.id'))->toBe($itemId);
        expect($response->json('data.name'))->toBe('Show Test Capsule');
        expect($response->json('data.cost_per_unit'))->toBe(0.15);
    });

    it('updates an existing dry goods item', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-update');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = DryGoodsItem::create([
                'name' => 'Old Cork',
                'item_type' => 'cork',
                'unit_of_measure' => 'sleeve',
                'on_hand' => 100,
                'vendor_name' => 'Cork Inc.',
            ]);
            $itemId = $item->id;
        });

        $response = test()->putJson("/api/v1/dry-goods/{$itemId}", [
            'name' => 'Premium Cork',
            'cost_per_unit' => 0.45,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data.name'))->toBe('Premium Cork');
        expect($response->json('data.cost_per_unit'))->toBe(0.45);
        // Unchanged fields persist
        expect($response->json('data.vendor_name'))->toBe('Cork Inc.');
        expect($response->json('data.item_type'))->toBe('cork');
    });
})->group('inventory');

// ─── Tier 2: Validation ──────────────────────────────────────────

describe('dry goods validation', function () {
    it('rejects missing required fields', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-val-missing');

        $response = test()->postJson('/api/v1/dry-goods', [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('name');
        expect($fields)->toContain('item_type');
        expect($fields)->toContain('unit_of_measure');
    });

    it('rejects invalid item_type', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-val-type');

        $response = test()->postJson('/api/v1/dry-goods', [
            'name' => 'Bad Type',
            'item_type' => 'invalid_type',
            'unit_of_measure' => 'each',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('item_type');
    });

    it('rejects invalid unit_of_measure', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-val-unit');

        $response = test()->postJson('/api/v1/dry-goods', [
            'name' => 'Bad Unit',
            'item_type' => 'bottle',
            'unit_of_measure' => 'invalid_unit',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('unit_of_measure');
    });

    it('rejects negative on_hand', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-val-neg');

        $response = test()->postJson('/api/v1/dry-goods', [
            'name' => 'Negative Stock',
            'item_type' => 'bottle',
            'unit_of_measure' => 'each',
            'on_hand' => -10,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
    });
})->group('inventory');

// ─── Tier 2: RBAC ────────────────────────────────────────────────

describe('dry goods RBAC', function () {
    it('admin can create dry goods', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-rbac-admin', 'admin');

        test()->postJson('/api/v1/dry-goods', [
            'name' => 'Admin Bottle',
            'item_type' => 'bottle',
            'unit_of_measure' => 'each',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    });

    it('winemaker cannot create dry goods', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-rbac-wm', 'winemaker');

        test()->postJson('/api/v1/dry-goods', [
            'name' => 'WM Bottle',
            'item_type' => 'bottle',
            'unit_of_measure' => 'each',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });

    it('read_only cannot create dry goods', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-rbac-ro', 'read_only');

        test()->postJson('/api/v1/dry-goods', [
            'name' => 'RO Bottle',
            'item_type' => 'bottle',
            'unit_of_measure' => 'each',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });

    it('any authenticated user can list and view dry goods', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-rbac-ro-view', 'read_only');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = DryGoodsItem::create([
                'name' => 'Viewable Item',
                'item_type' => 'cork',
                'unit_of_measure' => 'sleeve',
            ]);
            $itemId = $item->id;
        });

        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ];

        test()->getJson('/api/v1/dry-goods', $headers)->assertOk();
        test()->getJson("/api/v1/dry-goods/{$itemId}", $headers)->assertOk();
    });

    it('winemaker cannot update dry goods', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-rbac-wm-upd', 'winemaker');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = DryGoodsItem::create([
                'name' => 'No WM Update',
                'item_type' => 'bottle',
                'unit_of_measure' => 'each',
            ]);
            $itemId = $item->id;
        });

        test()->putJson("/api/v1/dry-goods/{$itemId}", [
            'name' => 'Updated Name',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });
})->group('inventory');

// ─── Tier 2: API Envelope ────────────────────────────────────────

describe('dry goods API envelope', function () {
    it('returns responses in the standard API envelope format', function () {
        [$tenant, $token] = createDryGoodsTestTenant('dg-envelope');

        $response = test()->postJson('/api/v1/dry-goods', [
            'name' => 'Envelope Test',
            'item_type' => 'bottle',
            'unit_of_measure' => 'each',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'name', 'item_type', 'unit_of_measure', 'on_hand', 'needs_reorder'],
            'meta',
            'errors',
        ]);
        expect($response->json('errors'))->toBe([]);
    });

    it('rejects unauthenticated access', function () {
        test()->getJson('/api/v1/dry-goods', [
            'X-Tenant-ID' => 'fake-tenant',
        ])->assertStatus(401);
    });
})->group('inventory');
