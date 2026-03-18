<?php

declare(strict_types=1);

use App\Models\DryGoodsItem;
use App\Models\Event;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\RawMaterial;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user of a given role and return [tenant, token].
 */
function createPurchaseOrderTestTenant(string $slug = 'po-winery', string $role = 'admin'): array
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

describe('purchase order event logging', function () {
    it('writes purchase_order_created event with inventory source', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-evt-create');

        $dryGoodsId = null;
        $tenant->run(function () use (&$dryGoodsId) {
            $item = DryGoodsItem::factory()->create(['name' => 'Cork #9']);
            $dryGoodsId = $item->id;
        });

        $response = test()->postJson('/api/v1/purchase-orders', [
            'vendor_name' => 'Cork Supply USA',
            'order_date' => '2026-03-15',
            'expected_date' => '2026-04-01',
            'lines' => [
                [
                    'item_type' => 'dry_goods',
                    'item_id' => $dryGoodsId,
                    'item_name' => 'Cork #9',
                    'quantity_ordered' => 5000,
                    'cost_per_unit' => 0.12,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $poId = $response->json('data.id');

        $tenant->run(function () use ($poId) {
            $event = Event::where('entity_id', $poId)
                ->where('operation_type', 'purchase_order_created')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['vendor_name'])->toBe('Cork Supply USA');
            expect($event->payload['line_count'])->toBe(1);
            expect((float) $event->payload['total_cost'])->toEqual(600.0); // 5000 * 0.12
        });
    })->group('inventory');

    it('writes purchase_order_received event with line details', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-evt-receive');

        $poId = null;
        $lineId = null;
        $tenant->run(function () use (&$poId, &$lineId) {
            $item = DryGoodsItem::factory()->create(['name' => 'Bottle 750ml', 'on_hand' => 100]);
            $po = PurchaseOrder::factory()->create(['vendor_name' => 'Pacific Bottles']);
            $line = PurchaseOrderLine::factory()->create([
                'purchase_order_id' => $po->id,
                'item_type' => 'dry_goods',
                'item_id' => $item->id,
                'item_name' => 'Bottle 750ml',
                'quantity_ordered' => 1000,
                'cost_per_unit' => 0.45,
            ]);
            $poId = $po->id;
            $lineId = $line->id;
        });

        $response = test()->postJson("/api/v1/purchase-orders/{$poId}/receive", [
            'lines' => [
                [
                    'line_id' => $lineId,
                    'quantity_received' => 500,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();

        $tenant->run(function () use ($poId) {
            $event = Event::where('entity_id', $poId)
                ->where('operation_type', 'purchase_order_received')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['vendor_name'])->toBe('Pacific Bottles');
            expect($event->payload['lines_received'])->toHaveCount(1);
            expect((float) $event->payload['lines_received'][0]['quantity_received'])->toEqual(500.0);
        });
    })->group('inventory');
})->group('inventory');

// ─── Tier 1: Tenant Isolation ───────────────────────────────────

describe('purchase order tenant isolation', function () {
    it('prevents cross-tenant purchase order access', function () {
        $tenantA = Tenant::create([
            'name' => 'Winery Alpha',
            'slug' => 'po-iso-alpha',
            'plan' => 'pro',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Winery Beta',
            'slug' => 'po-iso-beta',
            'plan' => 'pro',
        ]);

        $tenantA->run(function () {
            PurchaseOrder::factory()->create(['vendor_name' => 'Alpha Vendor']);
        });

        $tenantB->run(function () {
            expect(PurchaseOrder::count())->toBe(0);
        });

        $tenantA->run(function () {
            expect(PurchaseOrder::count())->toBe(1);
        });
    })->group('inventory');
})->group('inventory');

// ─── Tier 1: Inventory Math ────────────────────────────────────

describe('purchase order inventory integration', function () {
    it('increments dry goods on_hand when receiving', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-inv-dry');

        $poId = null;
        $lineId = null;
        $itemId = null;
        $tenant->run(function () use (&$poId, &$lineId, &$itemId) {
            $item = DryGoodsItem::factory()->create([
                'name' => 'Cork #9',
                'on_hand' => 200,
            ]);
            $po = PurchaseOrder::factory()->create();
            $line = PurchaseOrderLine::factory()->create([
                'purchase_order_id' => $po->id,
                'item_type' => 'dry_goods',
                'item_id' => $item->id,
                'item_name' => 'Cork #9',
                'quantity_ordered' => 1000,
                'cost_per_unit' => 0.10,
            ]);
            $poId = $po->id;
            $lineId = $line->id;
            $itemId = $item->id;
        });

        test()->postJson("/api/v1/purchase-orders/{$poId}/receive", [
            'lines' => [
                [
                    'line_id' => $lineId,
                    'quantity_received' => 500,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertOk();

        $tenant->run(function () use ($itemId) {
            $item = DryGoodsItem::find($itemId);
            // 200 + 500 = 700
            expect((float) $item->on_hand)->toEqual(700.0);
        });
    })->group('inventory');

    it('increments raw material on_hand when receiving', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-inv-rm');

        $poId = null;
        $lineId = null;
        $itemId = null;
        $tenant->run(function () use (&$poId, &$lineId, &$itemId) {
            $item = RawMaterial::factory()->create([
                'name' => 'Tartaric Acid',
                'on_hand' => 50,
            ]);
            $po = PurchaseOrder::factory()->create();
            $line = PurchaseOrderLine::factory()->create([
                'purchase_order_id' => $po->id,
                'item_type' => 'raw_material',
                'item_id' => $item->id,
                'item_name' => 'Tartaric Acid',
                'quantity_ordered' => 100,
                'cost_per_unit' => 3.50,
            ]);
            $poId = $po->id;
            $lineId = $line->id;
            $itemId = $item->id;
        });

        test()->postJson("/api/v1/purchase-orders/{$poId}/receive", [
            'lines' => [
                [
                    'line_id' => $lineId,
                    'quantity_received' => 75,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertOk();

        $tenant->run(function () use ($itemId) {
            $item = RawMaterial::find($itemId);
            // 50 + 75 = 125
            expect((float) $item->on_hand)->toEqual(125.0);
        });
    })->group('inventory');

    it('updates cost_per_unit on inventory item when received with cost', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-inv-cost');

        $poId = null;
        $lineId = null;
        $itemId = null;
        $tenant->run(function () use (&$poId, &$lineId, &$itemId) {
            $item = DryGoodsItem::factory()->create([
                'name' => 'Label Front',
                'on_hand' => 0,
                'cost_per_unit' => 0.05,
            ]);
            $po = PurchaseOrder::factory()->create();
            $line = PurchaseOrderLine::factory()->create([
                'purchase_order_id' => $po->id,
                'item_type' => 'dry_goods',
                'item_id' => $item->id,
                'item_name' => 'Label Front',
                'quantity_ordered' => 2000,
                'cost_per_unit' => 0.08,
            ]);
            $poId = $po->id;
            $lineId = $line->id;
            $itemId = $item->id;
        });

        test()->postJson("/api/v1/purchase-orders/{$poId}/receive", [
            'lines' => [
                [
                    'line_id' => $lineId,
                    'quantity_received' => 2000,
                    'cost_per_unit' => 0.09, // Actual cost at receipt overrides
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertOk();

        $tenant->run(function () use ($itemId) {
            $item = DryGoodsItem::find($itemId);
            expect((float) $item->cost_per_unit)->toEqual(0.09);
            expect((float) $item->on_hand)->toEqual(2000.0);
        });
    })->group('inventory');

    it('auto-sets PO status to received when all lines fully received', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-auto-recv');

        $poId = null;
        $lineId = null;
        $tenant->run(function () use (&$poId, &$lineId) {
            $item = DryGoodsItem::factory()->create(['on_hand' => 0]);
            $po = PurchaseOrder::factory()->create(['status' => 'ordered']);
            $line = PurchaseOrderLine::factory()->create([
                'purchase_order_id' => $po->id,
                'item_type' => 'dry_goods',
                'item_id' => $item->id,
                'item_name' => 'Test Item',
                'quantity_ordered' => 100,
            ]);
            $poId = $po->id;
            $lineId = $line->id;
        });

        test()->postJson("/api/v1/purchase-orders/{$poId}/receive", [
            'lines' => [['line_id' => $lineId, 'quantity_received' => 100]],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertOk();

        $tenant->run(function () use ($poId) {
            $po = PurchaseOrder::find($poId);
            expect($po->status)->toBe('received');
        });
    })->group('inventory');

    it('auto-sets PO status to partial when some lines partially received', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-auto-partial');

        $poId = null;
        $lineId = null;
        $tenant->run(function () use (&$poId, &$lineId) {
            $item = DryGoodsItem::factory()->create(['on_hand' => 0]);
            $po = PurchaseOrder::factory()->create(['status' => 'ordered']);
            $line = PurchaseOrderLine::factory()->create([
                'purchase_order_id' => $po->id,
                'item_type' => 'dry_goods',
                'item_id' => $item->id,
                'item_name' => 'Test Item',
                'quantity_ordered' => 100,
            ]);
            $poId = $po->id;
            $lineId = $line->id;
        });

        test()->postJson("/api/v1/purchase-orders/{$poId}/receive", [
            'lines' => [['line_id' => $lineId, 'quantity_received' => 50]],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertOk();

        $tenant->run(function () use ($poId) {
            $po = PurchaseOrder::find($poId);
            expect($po->status)->toBe('partial');
        });
    })->group('inventory');

    it('prevents receiving on a cancelled PO', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-recv-cancel');

        $poId = null;
        $tenant->run(function () use (&$poId) {
            $po = PurchaseOrder::factory()->cancelled()->create();
            PurchaseOrderLine::factory()->create([
                'purchase_order_id' => $po->id,
                'quantity_ordered' => 100,
            ]);
            $poId = $po->id;
        });

        test()->postJson("/api/v1/purchase-orders/{$poId}/receive", [
            'lines' => [['line_id' => 'fake-id', 'quantity_received' => 50]],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(422);
    })->group('inventory');

    it('prevents receiving on an already received PO', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-recv-done');

        $poId = null;
        $tenant->run(function () use (&$poId) {
            $po = PurchaseOrder::factory()->received()->create();
            PurchaseOrderLine::factory()->create([
                'purchase_order_id' => $po->id,
                'quantity_ordered' => 100,
                'quantity_received' => 100,
            ]);
            $poId = $po->id;
        });

        test()->postJson("/api/v1/purchase-orders/{$poId}/receive", [
            'lines' => [['line_id' => 'fake-id', 'quantity_received' => 50]],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(422);
    })->group('inventory');
})->group('inventory');

// ─── Tier 2: CRUD ──────────────────────────────────────────────

describe('purchase order CRUD', function () {
    it('creates a PO with line items', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-crud-create');

        $itemIds = [];
        $tenant->run(function () use (&$itemIds) {
            $itemIds['cork'] = DryGoodsItem::factory()->create(['name' => 'Cork #9'])->id;
            $itemIds['acid'] = RawMaterial::factory()->create(['name' => 'Tartaric Acid'])->id;
        });

        $response = test()->postJson('/api/v1/purchase-orders', [
            'vendor_name' => 'Multi Vendor',
            'order_date' => '2026-03-15',
            'lines' => [
                [
                    'item_type' => 'dry_goods',
                    'item_id' => $itemIds['cork'],
                    'item_name' => 'Cork #9',
                    'quantity_ordered' => 5000,
                    'cost_per_unit' => 0.12,
                ],
                [
                    'item_type' => 'raw_material',
                    'item_id' => $itemIds['acid'],
                    'item_name' => 'Tartaric Acid',
                    'quantity_ordered' => 25,
                    'cost_per_unit' => 3.50,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        expect($data['vendor_name'])->toBe('Multi Vendor');
        expect($data['status'])->toBe('ordered');
        expect($data['lines'])->toHaveCount(2);
        // 5000*0.12 + 25*3.50 = 600 + 87.50 = 687.50
        expect((float) $data['total_cost'])->toEqual(687.50);
    })->group('inventory');

    it('lists POs with pagination', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-crud-list');

        $tenant->run(function () {
            PurchaseOrder::factory()->count(3)->create();
        });

        $response = test()->getJson('/api/v1/purchase-orders?per_page=2', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(2);
        expect($response->json('meta.total'))->toBe(3);
    })->group('inventory');

    it('shows a PO with lines', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-crud-show');

        $poId = null;
        $tenant->run(function () use (&$poId) {
            $po = PurchaseOrder::factory()->create(['vendor_name' => 'Show Vendor']);
            PurchaseOrderLine::factory()->count(2)->create([
                'purchase_order_id' => $po->id,
            ]);
            $poId = $po->id;
        });

        $response = test()->getJson("/api/v1/purchase-orders/{$poId}", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data.vendor_name'))->toBe('Show Vendor');
        expect($response->json('data.lines'))->toHaveCount(2);
    })->group('inventory');

    it('updates PO header fields', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-crud-update');

        $poId = null;
        $tenant->run(function () use (&$poId) {
            $po = PurchaseOrder::factory()->create(['vendor_name' => 'Old Vendor', 'status' => 'ordered']);
            $poId = $po->id;
        });

        $response = test()->putJson("/api/v1/purchase-orders/{$poId}", [
            'vendor_name' => 'New Vendor',
            'status' => 'cancelled',
            'notes' => 'Cancelled by customer',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data.vendor_name'))->toBe('New Vendor');
        expect($response->json('data.status'))->toBe('cancelled');
        expect($response->json('data.notes'))->toBe('Cancelled by customer');
    })->group('inventory');

    it('filters by status', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-filter-status');

        $tenant->run(function () {
            PurchaseOrder::factory()->create(['status' => 'ordered']);
            PurchaseOrder::factory()->create(['status' => 'received']);
            PurchaseOrder::factory()->create(['status' => 'cancelled']);
        });

        $response = test()->getJson('/api/v1/purchase-orders?status=ordered', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.status'))->toBe('ordered');
    })->group('inventory');

    it('filters open POs only', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-filter-open');

        $tenant->run(function () {
            PurchaseOrder::factory()->create(['status' => 'ordered']);
            PurchaseOrder::factory()->create(['status' => 'partial']);
            PurchaseOrder::factory()->create(['status' => 'received']);
        });

        $response = test()->getJson('/api/v1/purchase-orders?open_only=1', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(2);
    })->group('inventory');

    it('filters by vendor', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-filter-vendor');

        $tenant->run(function () {
            PurchaseOrder::factory()->create(['vendor_name' => 'Cork Supply USA']);
            PurchaseOrder::factory()->create(['vendor_name' => 'Pacific Bottles']);
        });

        $response = test()->getJson('/api/v1/purchase-orders?vendor=cork', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.vendor_name'))->toBe('Cork Supply USA');
    })->group('inventory');
})->group('inventory');

// ─── Tier 2: Validation ────────────────────────────────────────

describe('purchase order validation', function () {
    it('requires vendor_name and order_date', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-val-req');

        $response = test()->postJson('/api/v1/purchase-orders', [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('vendor_name');
        expect($fields)->toContain('order_date');
        expect($fields)->toContain('lines');
    })->group('inventory');

    it('requires at least one line item', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-val-lines');

        $response = test()->postJson('/api/v1/purchase-orders', [
            'vendor_name' => 'Test Vendor',
            'order_date' => '2026-03-15',
            'lines' => [],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('lines');
    })->group('inventory');

    it('validates line item fields', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-val-line-fields');

        $response = test()->postJson('/api/v1/purchase-orders', [
            'vendor_name' => 'Test Vendor',
            'order_date' => '2026-03-15',
            'lines' => [
                [
                    'item_type' => 'invalid_type',
                    'item_id' => 'not-a-uuid',
                    'quantity_ordered' => -5,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('lines.0.item_type');
        expect($fields)->toContain('lines.0.item_id');
        expect($fields)->toContain('lines.0.item_name');
        expect($fields)->toContain('lines.0.quantity_ordered');
    })->group('inventory');

    it('rejects invalid status', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-val-status');

        $response = test()->postJson('/api/v1/purchase-orders', [
            'vendor_name' => 'Test',
            'order_date' => '2026-03-15',
            'status' => 'invalid',
            'lines' => [
                [
                    'item_type' => 'dry_goods',
                    'item_id' => Str::uuid()->toString(),
                    'item_name' => 'Test',
                    'quantity_ordered' => 10,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(422);
        $fields = array_column($response->json('errors'), 'field');
        expect($fields)->toContain('status');
    })->group('inventory');
})->group('inventory');

// ─── Tier 2: RBAC ──────────────────────────────────────────────

describe('purchase order RBAC', function () {
    it('requires authentication', function () {
        [$tenant] = createPurchaseOrderTestTenant('po-rbac-unauth');

        test()->getJson('/api/v1/purchase-orders', [
            'X-Tenant-ID' => $tenant->id,
        ])->assertUnauthorized();
    })->group('inventory');

    it('allows any authenticated user to list and view POs', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-rbac-read', 'read_only');

        $tenant->run(function () {
            PurchaseOrder::factory()->create();
        });

        test()->getJson('/api/v1/purchase-orders', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertOk();
    })->group('inventory');

    it('denies cellar_hand from creating POs', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-rbac-ch', 'cellar_hand');

        test()->postJson('/api/v1/purchase-orders', [
            'vendor_name' => 'Test',
            'order_date' => '2026-03-15',
            'lines' => [
                [
                    'item_type' => 'dry_goods',
                    'item_id' => Str::uuid()->toString(),
                    'item_name' => 'Test',
                    'quantity_ordered' => 10,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(403);
    })->group('inventory');

    it('allows winemaker to create POs', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-rbac-wm', 'winemaker');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $itemId = DryGoodsItem::factory()->create()->id;
        });

        test()->postJson('/api/v1/purchase-orders', [
            'vendor_name' => 'Winemaker Vendor',
            'order_date' => '2026-03-15',
            'lines' => [
                [
                    'item_type' => 'dry_goods',
                    'item_id' => $itemId,
                    'item_name' => 'Test Item',
                    'quantity_ordered' => 100,
                    'cost_per_unit' => 1.00,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ])->assertStatus(201);
    })->group('inventory');
})->group('inventory');

// ─── Tier 2: API Envelope ──────────────────────────────────────

describe('purchase order API envelope', function () {
    it('wraps list response in standard envelope with pagination', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-env');

        $response = test()->getJson('/api/v1/purchase-orders', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    })->group('inventory');

    it('wraps single PO in standard envelope with lines', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-env-show');

        $poId = null;
        $tenant->run(function () use (&$poId) {
            $po = PurchaseOrder::factory()->create();
            PurchaseOrderLine::factory()->create(['purchase_order_id' => $po->id]);
            $poId = $po->id;
        });

        $response = test()->getJson("/api/v1/purchase-orders/{$poId}", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'vendor_name',
                    'order_date',
                    'status',
                    'total_cost',
                    'lines' => [
                        '*' => [
                            'id',
                            'item_type',
                            'item_name',
                            'quantity_ordered',
                            'quantity_received',
                            'quantity_remaining',
                            'cost_per_unit',
                            'line_total',
                            'is_fully_received',
                        ],
                    ],
                    'is_fully_received',
                ],
                'meta',
            ]);
    })->group('inventory');
})->group('inventory');

// ─── Data Integrity ────────────────────────────────────────────

describe('purchase order data integrity', function () {
    it('recalculates total_cost from lines on create', function () {
        [$tenant, $token] = createPurchaseOrderTestTenant('po-int-cost');

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $itemId = DryGoodsItem::factory()->create()->id;
        });

        $response = test()->postJson('/api/v1/purchase-orders', [
            'vendor_name' => 'Cost Test Vendor',
            'order_date' => '2026-03-15',
            'lines' => [
                [
                    'item_type' => 'dry_goods',
                    'item_id' => $itemId,
                    'item_name' => 'Item A',
                    'quantity_ordered' => 100,
                    'cost_per_unit' => 2.50,
                ],
                [
                    'item_type' => 'dry_goods',
                    'item_id' => $itemId,
                    'item_name' => 'Item B',
                    'quantity_ordered' => 50,
                    'cost_per_unit' => 10.00,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(201);
        // 100*2.50 + 50*10.00 = 250 + 500 = 750
        expect((float) $response->json('data.total_cost'))->toEqual(750.0);
    })->group('inventory');

    it('cascades delete of lines when PO deleted', function () {
        [$tenant] = createPurchaseOrderTestTenant('po-int-cascade');

        $tenant->run(function () {
            $po = PurchaseOrder::factory()->create();
            PurchaseOrderLine::factory()->count(3)->create([
                'purchase_order_id' => $po->id,
            ]);

            expect(PurchaseOrderLine::count())->toBe(3);

            $po->delete();

            expect(PurchaseOrderLine::count())->toBe(0);
        });
    })->group('inventory');
})->group('inventory');
