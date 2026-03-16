<?php

declare(strict_types=1);

use App\Models\Addition;
use App\Models\Event;
use App\Models\Lot;
use App\Models\RawMaterial;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createRawMaterialTestTenant(string $slug = 'rm-winery', string $role = 'admin'): array
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

// ─── Tier 1: Event Logging ──────────────────────────────────────

describe('raw material event logging', function () {
    it('writes raw_material_created event with inventory source', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-evt-create');

        $response = test()->postJson('/api/v1/raw-materials', [
            'name' => 'Potassium Metabisulfite',
            'category' => 'additive',
            'unit_of_measure' => 'kg',
            'on_hand' => 25,
            'cost_per_unit' => 12.50,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $itemId = $response->json('data.id');

        $tenant->run(function () use ($itemId) {
            $event = Event::where('entity_id', $itemId)
                ->where('operation_type', 'raw_material_created')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->entity_type)->toBe('raw_material');
            expect($event->payload['name'])->toBe('Potassium Metabisulfite');
            expect($event->payload['category'])->toBe('additive');
            expect($event->payload['on_hand'])->toEqual(25);
        });
    });

    it('writes raw_material_updated event with inventory source', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-evt-update');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = RawMaterial::create([
                'name' => 'EC-1118',
                'category' => 'yeast',
                'unit_of_measure' => 'g',
                'on_hand' => 500,
            ]);
            $itemId = $item->id;
        });

        test()->putJson("/api/v1/raw-materials/{$itemId}", [
            'on_hand' => 400,
            'is_active' => false,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertOk();

        $tenant->run(function () use ($itemId) {
            $event = Event::where('entity_id', $itemId)
                ->where('operation_type', 'raw_material_updated')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['is_active'])->toBeFalse();
        });
    });
})->group('inventory');

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

describe('raw material tenant isolation', function () {
    it('prevents cross-tenant raw material access', function () {
        $tenantA = Tenant::create([
            'name' => 'Winery Alpha',
            'slug' => 'rm-iso-alpha',
            'plan' => 'pro',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Winery Beta',
            'slug' => 'rm-iso-beta',
            'plan' => 'pro',
        ]);

        $tenantA->run(function () {
            RawMaterial::create([
                'name' => 'Alpha Yeast',
                'category' => 'yeast',
                'unit_of_measure' => 'g',
                'on_hand' => 500,
            ]);
        });

        $tenantB->run(function () {
            expect(RawMaterial::count())->toBe(0);
        });

        $tenantA->run(function () {
            expect(RawMaterial::count())->toBe(1);
        });
    });
})->group('inventory');

// ─── Tier 1: Data Integrity ─────────────────────────────────────

describe('raw material data integrity', function () {
    it('correctly identifies items below reorder point', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-reorder');

        $tenant->run(function () {
            RawMaterial::create([
                'name' => 'Low Bentonite',
                'category' => 'fining_agent',
                'unit_of_measure' => 'kg',
                'on_hand' => 2,
                'reorder_point' => 10,
            ]);

            RawMaterial::create([
                'name' => 'Full Tartaric',
                'category' => 'acid',
                'unit_of_measure' => 'kg',
                'on_hand' => 50,
                'reorder_point' => 10,
            ]);

            RawMaterial::create([
                'name' => 'No Threshold Yeast',
                'category' => 'yeast',
                'unit_of_measure' => 'g',
                'on_hand' => 5,
            ]);

            $belowReorder = RawMaterial::belowReorderPoint()->get();
            expect($belowReorder)->toHaveCount(1);
            expect($belowReorder->first()->name)->toBe('Low Bentonite');
        });
    });

    it('needsReorder returns correct boolean', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-needs-reorder');

        $tenant->run(function () {
            $lowItem = RawMaterial::create([
                'name' => 'Low Stock Nutrient',
                'category' => 'nutrient',
                'unit_of_measure' => 'g',
                'on_hand' => 5,
                'reorder_point' => 50,
            ]);
            expect($lowItem->needsReorder())->toBeTrue();

            $okItem = RawMaterial::create([
                'name' => 'OK Stock Nutrient',
                'category' => 'nutrient',
                'unit_of_measure' => 'g',
                'on_hand' => 500,
                'reorder_point' => 50,
            ]);
            expect($okItem->needsReorder())->toBeFalse();

            $noThreshold = RawMaterial::create([
                'name' => 'No Threshold',
                'category' => 'acid',
                'unit_of_measure' => 'kg',
                'on_hand' => 1,
            ]);
            expect($noThreshold->needsReorder())->toBeFalse();
        });
    });

    it('expired scope identifies past-expiration items', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-expired');

        $tenant->run(function () {
            RawMaterial::create([
                'name' => 'Expired Yeast',
                'category' => 'yeast',
                'unit_of_measure' => 'g',
                'on_hand' => 100,
                'expiration_date' => now()->subDays(30),
            ]);

            RawMaterial::create([
                'name' => 'Fresh Yeast',
                'category' => 'yeast',
                'unit_of_measure' => 'g',
                'on_hand' => 100,
                'expiration_date' => now()->addDays(180),
            ]);

            RawMaterial::create([
                'name' => 'No Expiry',
                'category' => 'acid',
                'unit_of_measure' => 'kg',
                'on_hand' => 50,
            ]);

            $expired = RawMaterial::expired()->get();
            expect($expired)->toHaveCount(1);
            expect($expired->first()->name)->toBe('Expired Yeast');
        });
    });

    it('expiringSoon scope identifies items expiring within window', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-expiring-soon');

        $tenant->run(function () {
            RawMaterial::create([
                'name' => 'Expiring Soon Enzyme',
                'category' => 'enzyme',
                'unit_of_measure' => 'L',
                'on_hand' => 5,
                'expiration_date' => now()->addDays(15),
            ]);

            RawMaterial::create([
                'name' => 'Not Soon Enough',
                'category' => 'enzyme',
                'unit_of_measure' => 'L',
                'on_hand' => 5,
                'expiration_date' => now()->addDays(60),
            ]);

            $expiringSoon = RawMaterial::expiringSoon(30)->get();
            expect($expiringSoon)->toHaveCount(1);
            expect($expiringSoon->first()->name)->toBe('Expiring Soon Enzyme');
        });
    });

    it('stores decimal quantities correctly', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-decimal');

        $tenant->run(function () {
            $item = RawMaterial::create([
                'name' => 'Precise Chemical',
                'category' => 'additive',
                'unit_of_measure' => 'kg',
                'on_hand' => 2.75,
                'cost_per_unit' => 45.1234,
            ]);

            $item->refresh();
            expect((float) $item->on_hand)->toBe(2.75);
            expect((float) $item->cost_per_unit)->toBe(45.1234);
        });
    });
})->group('inventory');

// ─── Tier 2: CRUD ────────────────────────────────────────────────

describe('raw material CRUD', function () {
    it('creates a raw material with all fields', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-crud-all');

        $response = test()->postJson('/api/v1/raw-materials', [
            'name' => 'Fermaid O',
            'category' => 'nutrient',
            'unit_of_measure' => 'g',
            'on_hand' => 2500,
            'reorder_point' => 500,
            'cost_per_unit' => 0.12,
            'expiration_date' => '2027-06-15',
            'vendor_name' => 'Lallemand Inc.',
            'is_active' => true,
            'notes' => 'Organic yeast nutrient',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        expect($data['name'])->toBe('Fermaid O');
        expect($data['category'])->toBe('nutrient');
        expect($data['unit_of_measure'])->toBe('g');
        expect($data['on_hand'])->toEqual(2500);
        expect($data['reorder_point'])->toEqual(500);
        expect($data['cost_per_unit'])->toEqual(0.12);
        expect($data['expiration_date'])->toBe('2027-06-15');
        expect($data['vendor_name'])->toBe('Lallemand Inc.');
        expect($data['is_active'])->toBeTrue();
        expect($data['notes'])->toBe('Organic yeast nutrient');
        expect($data['needs_reorder'])->toBeFalse();
        expect($data['is_expired'])->toBeFalse();
    });

    it('creates with minimal required fields', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-crud-min');

        $response = test()->postJson('/api/v1/raw-materials', [
            'name' => 'Basic Acid',
            'category' => 'acid',
            'unit_of_measure' => 'kg',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        expect($data['name'])->toBe('Basic Acid');
        expect($data['on_hand'])->toEqual(0);
        expect($data['reorder_point'])->toBeNull();
        expect($data['cost_per_unit'])->toBeNull();
        expect($data['expiration_date'])->toBeNull();
        expect($data['vendor_name'])->toBeNull();
        expect($data['is_active'])->toBeTrue();
    });

    it('lists raw materials with pagination', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-crud-list');

        $tenant->run(function () {
            RawMaterial::create(['name' => 'Item A', 'category' => 'yeast', 'unit_of_measure' => 'g']);
            RawMaterial::create(['name' => 'Item B', 'category' => 'acid', 'unit_of_measure' => 'kg']);
            RawMaterial::create(['name' => 'Item C', 'category' => 'nutrient', 'unit_of_measure' => 'g']);
        });

        $response = test()->getJson('/api/v1/raw-materials', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);
        expect($response->json('meta.total'))->toBe(3);
    });

    it('filters by category', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-filter-cat');

        $tenant->run(function () {
            RawMaterial::create(['name' => 'Yeast A', 'category' => 'yeast', 'unit_of_measure' => 'g']);
            RawMaterial::create(['name' => 'Acid A', 'category' => 'acid', 'unit_of_measure' => 'kg']);
        });

        $response = test()->getJson('/api/v1/raw-materials?category=yeast', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.category'))->toBe('yeast');
    });

    it('filters by active status', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-filter-active');

        $tenant->run(function () {
            RawMaterial::create(['name' => 'Active Item', 'category' => 'yeast', 'unit_of_measure' => 'g', 'is_active' => true]);
            RawMaterial::create(['name' => 'Inactive Item', 'category' => 'acid', 'unit_of_measure' => 'kg', 'is_active' => false]);
        });

        $response = test()->getJson('/api/v1/raw-materials?is_active=1', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.name'))->toBe('Active Item');
    });

    it('filters items below reorder point', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-filter-reorder');

        $tenant->run(function () {
            RawMaterial::create(['name' => 'Low Stock', 'category' => 'fining_agent', 'unit_of_measure' => 'kg', 'on_hand' => 2, 'reorder_point' => 20]);
            RawMaterial::create(['name' => 'OK Stock', 'category' => 'acid', 'unit_of_measure' => 'kg', 'on_hand' => 100, 'reorder_point' => 20]);
        });

        $response = test()->getJson('/api/v1/raw-materials?below_reorder=1', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.name'))->toBe('Low Stock');
    });

    it('filters expired items', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-filter-expired');

        $tenant->run(function () {
            RawMaterial::create([
                'name' => 'Expired Yeast',
                'category' => 'yeast',
                'unit_of_measure' => 'g',
                'expiration_date' => now()->subDays(10),
            ]);
            RawMaterial::create([
                'name' => 'Fresh Yeast',
                'category' => 'yeast',
                'unit_of_measure' => 'g',
                'expiration_date' => now()->addDays(180),
            ]);
        });

        $response = test()->getJson('/api/v1/raw-materials?expired=1', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.name'))->toBe('Expired Yeast');
    });

    it('shows a single raw material', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-show');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = RawMaterial::create([
                'name' => 'Show Test Enzyme',
                'category' => 'enzyme',
                'unit_of_measure' => 'L',
                'on_hand' => 10,
                'cost_per_unit' => 85.00,
            ]);
            $itemId = $item->id;
        });

        $response = test()->getJson("/api/v1/raw-materials/{$itemId}", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data.id'))->toBe($itemId);
        expect($response->json('data.name'))->toBe('Show Test Enzyme');
        expect($response->json('data.cost_per_unit'))->toEqual(85);
    });

    it('updates an existing raw material', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-update');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = RawMaterial::create([
                'name' => 'Old Acid',
                'category' => 'acid',
                'unit_of_measure' => 'kg',
                'on_hand' => 30,
                'vendor_name' => 'Chem Supply Co.',
            ]);
            $itemId = $item->id;
        });

        $response = test()->putJson("/api/v1/raw-materials/{$itemId}", [
            'name' => 'Premium Tartaric Acid',
            'cost_per_unit' => 18.50,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data.name'))->toBe('Premium Tartaric Acid');
        expect($response->json('data.cost_per_unit'))->toEqual(18.50);
        // Unchanged fields persist
        expect($response->json('data.vendor_name'))->toBe('Chem Supply Co.');
        expect($response->json('data.category'))->toBe('acid');
    });
})->group('inventory');

// ─── Tier 2: Validation ──────────────────────────────────────────

describe('raw material validation', function () {
    it('rejects missing required fields', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-val-missing');

        $response = test()->postJson('/api/v1/raw-materials', [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('name');
        expect($fields)->toContain('category');
        expect($fields)->toContain('unit_of_measure');
    });

    it('rejects invalid category', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-val-cat');

        $response = test()->postJson('/api/v1/raw-materials', [
            'name' => 'Bad Category',
            'category' => 'invalid_category',
            'unit_of_measure' => 'g',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('category');
    });

    it('rejects invalid unit_of_measure', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-val-unit');

        $response = test()->postJson('/api/v1/raw-materials', [
            'name' => 'Bad Unit',
            'category' => 'yeast',
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
        [$tenant, $token] = createRawMaterialTestTenant('rm-val-neg');

        $response = test()->postJson('/api/v1/raw-materials', [
            'name' => 'Negative Stock',
            'category' => 'acid',
            'unit_of_measure' => 'kg',
            'on_hand' => -10,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
    });
})->group('inventory');

// ─── Tier 2: RBAC ────────────────────────────────────────────────

describe('raw material RBAC', function () {
    it('admin can create raw materials', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-rbac-admin', 'admin');

        test()->postJson('/api/v1/raw-materials', [
            'name' => 'Admin Yeast',
            'category' => 'yeast',
            'unit_of_measure' => 'g',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    });

    it('winemaker cannot create raw materials', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-rbac-wm', 'winemaker');

        test()->postJson('/api/v1/raw-materials', [
            'name' => 'WM Yeast',
            'category' => 'yeast',
            'unit_of_measure' => 'g',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });

    it('read_only cannot create raw materials', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-rbac-ro', 'read_only');

        test()->postJson('/api/v1/raw-materials', [
            'name' => 'RO Yeast',
            'category' => 'yeast',
            'unit_of_measure' => 'g',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });

    it('any authenticated user can list and view raw materials', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-rbac-ro-view', 'read_only');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = RawMaterial::create([
                'name' => 'Viewable Material',
                'category' => 'acid',
                'unit_of_measure' => 'kg',
            ]);
            $itemId = $item->id;
        });

        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ];

        test()->getJson('/api/v1/raw-materials', $headers)->assertOk();
        test()->getJson("/api/v1/raw-materials/{$itemId}", $headers)->assertOk();
    });

    it('winemaker cannot update raw materials', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-rbac-wm-upd', 'winemaker');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = RawMaterial::create([
                'name' => 'No WM Update',
                'category' => 'yeast',
                'unit_of_measure' => 'g',
            ]);
            $itemId = $item->id;
        });

        test()->putJson("/api/v1/raw-materials/{$itemId}", [
            'name' => 'Updated Name',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    });
})->group('inventory');

// ─── Tier 2: API Envelope ────────────────────────────────────────

describe('raw material API envelope', function () {
    it('returns responses in the standard API envelope format', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-envelope');

        $response = test()->postJson('/api/v1/raw-materials', [
            'name' => 'Envelope Test',
            'category' => 'yeast',
            'unit_of_measure' => 'g',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'name', 'category', 'unit_of_measure', 'on_hand', 'needs_reorder', 'is_expired', 'expiration_date'],
            'meta',
            'errors',
        ]);
        expect($response->json('errors'))->toBe([]);
    });

    it('rejects unauthenticated access', function () {
        test()->getJson('/api/v1/raw-materials', [
            'X-Tenant-ID' => 'fake-tenant',
        ])->assertStatus(401);
    });
})->group('inventory');

// ─── Tier 1: Auto-Deduct from AdditionService ───────────────────

describe('raw material auto-deduct', function () {
    it('deducts raw material on_hand when addition has inventory_item_id', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-deduct');

        $materialId = null;
        $lotId = null;
        $vesselId = null;

        $tenant->run(function () use (&$materialId, &$lotId, &$vesselId) {
            $material = RawMaterial::create([
                'name' => 'Potassium Metabisulfite',
                'category' => 'additive',
                'unit_of_measure' => 'g',
                'on_hand' => 500,
            ]);
            $materialId = $material->id;

            $lot = Lot::factory()->create([
                'name' => 'LOT-DEDUCT-001',
                'status' => 'active',
            ]);
            $lotId = $lot->id;

            $vessel = Vessel::factory()->create([
                'name' => 'Tank 1',
                'status' => 'available',
            ]);
            $vesselId = $vessel->id;
        });

        // Post an addition linked to the raw material
        $response = test()->postJson('/api/v1/additions', [
            'lot_id' => $lotId,
            'vessel_id' => $vesselId,
            'addition_type' => 'sulfite',
            'product_name' => 'Potassium Metabisulfite',
            'rate' => 25,
            'rate_unit' => 'ppm',
            'total_amount' => 50,
            'total_unit' => 'g',
            'inventory_item_id' => $materialId,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);

        // Check that on_hand was deducted
        $tenant->run(function () use ($materialId) {
            $material = RawMaterial::find($materialId);
            expect((float) $material->on_hand)->toEqual(450);

            // Verify the deduction event was logged
            $event = Event::where('entity_id', $materialId)
                ->where('operation_type', 'raw_material_deducted')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['deducted_amount'])->toEqual(50);
            expect($event->payload['previous_on_hand'])->toEqual(500);
            expect($event->payload['new_on_hand'])->toEqual(450);
        });
    });

    it('does not deduct when addition has no inventory_item_id', function () {
        [$tenant, $token] = createRawMaterialTestTenant('rm-no-deduct');

        $materialId = null;
        $lotId = null;
        $vesselId = null;

        $tenant->run(function () use (&$materialId, &$lotId, &$vesselId) {
            $material = RawMaterial::create([
                'name' => 'Tartaric Acid',
                'category' => 'acid',
                'unit_of_measure' => 'g',
                'on_hand' => 200,
            ]);
            $materialId = $material->id;

            $lot = Lot::factory()->create([
                'name' => 'LOT-NODEDUCT-001',
                'status' => 'active',
            ]);
            $lotId = $lot->id;

            $vessel = Vessel::factory()->create([
                'name' => 'Tank 2',
                'status' => 'available',
            ]);
            $vesselId = $vessel->id;
        });

        // Post addition WITHOUT inventory_item_id
        test()->postJson('/api/v1/additions', [
            'lot_id' => $lotId,
            'vessel_id' => $vesselId,
            'addition_type' => 'acid',
            'product_name' => 'Tartaric Acid',
            'total_amount' => 30,
            'total_unit' => 'g',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);

        // on_hand should remain unchanged
        $tenant->run(function () use ($materialId) {
            $material = RawMaterial::find($materialId);
            expect((float) $material->on_hand)->toEqual(200);
        });
    });
})->group('inventory');
