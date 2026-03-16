<?php

declare(strict_types=1);

use App\Models\CaseGoodsSku;
use App\Models\Event;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with a user and return [tenant, user_id].
 */
function createMovementTestTenant(string $slug = 'mvmt-winery'): array
{
    if (function_exists('tenancy') && tenancy()->initialized) {
        tenancy()->end();
    }

    $tenant = Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'plan' => 'pro',
    ]);

    $userId = null;
    $tenant->run(function () use (&$userId) {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create([
            'name' => 'Test Winemaker',
            'email' => 'winemaker@example.com',
            'password' => 'SecurePass123!',
            'role' => 'winemaker',
            'is_active' => true,
        ]);
        $user->assignRole('winemaker');
        $userId = $user->id;
    });

    return [$tenant, $userId];
}

/**
 * Helper: create a SKU and location within the current tenant context.
 *
 * @return array{sku: CaseGoodsSku, location: Location}
 */
function createSkuAndLocation(): array
{
    $sku = CaseGoodsSku::create([
        'wine_name' => '2024 Test Wine',
        'vintage' => 2024,
        'varietal' => 'Cabernet Sauvignon',
    ]);

    $location = Location::create([
        'name' => 'Test Location',
        'is_active' => true,
    ]);

    return ['sku' => $sku, 'location' => $location];
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

describe('stock movement event logging', function () {
    it('writes stock_received event with inventory source', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-evt-recv');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();
            $service = app(InventoryService::class);

            $movement = $service->receive(
                skuId: $sku->id,
                locationId: $location->id,
                quantity: 100,
                performedBy: $userId,
            );

            $event = Event::where('entity_id', $movement->id)
                ->where('operation_type', 'stock_received')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['sku_id'])->toBe($sku->id);
            expect($event->payload['wine_name'])->toBe('2024 Test Wine');
            expect($event->payload['location_name'])->toBe('Test Location');
            expect($event->payload['quantity'])->toBe(100);
            expect($event->payload['movement_type'])->toBe('received');
        });
    });

    it('writes stock_adjusted event with inventory source', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-evt-adj');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();
            $service = app(InventoryService::class);

            $movement = $service->adjust(
                skuId: $sku->id,
                locationId: $location->id,
                quantity: -5,
                performedBy: $userId,
                options: ['notes' => 'Breakage'],
            );

            $event = Event::where('entity_id', $movement->id)
                ->where('operation_type', 'stock_adjusted')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['quantity'])->toBe(-5);
        });
    });

    it('writes stock_transferred event with inventory source', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-evt-xfer');

        $tenant->run(function () use ($userId) {
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Transfer Wine',
                'vintage' => 2024,
                'varietal' => 'Merlot',
            ]);
            $from = Location::create(['name' => 'Back Stock']);
            $to = Location::create(['name' => 'Tasting Room']);

            // Seed initial stock
            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $from->id,
                'on_hand' => 200,
            ]);

            $service = app(InventoryService::class);
            $result = $service->transfer(
                skuId: $sku->id,
                fromLocationId: $from->id,
                toLocationId: $to->id,
                quantity: 24,
                performedBy: $userId,
            );

            $event = Event::where('operation_type', 'stock_transferred')->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['from_location_name'])->toBe('Back Stock');
            expect($event->payload['to_location_name'])->toBe('Tasting Room');
            expect($event->payload['quantity'])->toBe(24);
            expect($event->payload['wine_name'])->toBe('2024 Transfer Wine');
        });
    });

    it('writes stock_sold event with inventory source', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-evt-sold');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'on_hand' => 50,
            ]);

            $service = app(InventoryService::class);
            $movement = $service->sell(
                skuId: $sku->id,
                locationId: $location->id,
                quantity: 3,
                performedBy: $userId,
                options: ['reference_type' => 'order', 'reference_id' => (string) \Illuminate\Support\Str::uuid()],
            );

            $event = Event::where('entity_id', $movement->id)
                ->where('operation_type', 'stock_sold')
                ->first();

            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('inventory');
            expect($event->payload['quantity'])->toBe(-3);
        });
    });
})->group('inventory');

// ─── Tier 1: Inventory Math (Data Integrity) ────────────────────

describe('inventory math', function () {
    it('receive increases on_hand atomically', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-math-recv');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();
            $service = app(InventoryService::class);

            $service->receive($sku->id, $location->id, 100, $userId);
            $service->receive($sku->id, $location->id, 50, $userId);

            $stockLevel = StockLevel::where('sku_id', $sku->id)
                ->where('location_id', $location->id)
                ->first();

            expect($stockLevel->on_hand)->toBe(150);
            expect($stockLevel->available)->toBe(150);
        });
    });

    it('sell decreases on_hand', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-math-sell');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'on_hand' => 100,
            ]);

            $service = app(InventoryService::class);
            $service->sell($sku->id, $location->id, 12, $userId);

            $stockLevel = StockLevel::where('sku_id', $sku->id)
                ->where('location_id', $location->id)
                ->first();

            expect($stockLevel->on_hand)->toBe(88);
        });
    });

    it('adjustment can increase or decrease on_hand', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-math-adj');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'on_hand' => 100,
            ]);

            $service = app(InventoryService::class);

            // Positive adjustment
            $service->adjust($sku->id, $location->id, 10, $userId);
            $stockLevel = StockLevel::where('sku_id', $sku->id)->first();
            expect($stockLevel->on_hand)->toBe(110);

            // Negative adjustment
            $service->adjust($sku->id, $location->id, -30, $userId);
            $stockLevel->refresh();
            expect($stockLevel->on_hand)->toBe(80);
        });
    });

    it('transfer moves stock between locations atomically', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-math-xfer');

        $tenant->run(function () use ($userId) {
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Transfer Math',
                'vintage' => 2024,
                'varietal' => 'Syrah',
            ]);
            $from = Location::create(['name' => 'Source']);
            $to = Location::create(['name' => 'Dest']);

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $from->id,
                'on_hand' => 200,
            ]);

            $service = app(InventoryService::class);
            $result = $service->transfer($sku->id, $from->id, $to->id, 48, $userId);

            $fromLevel = StockLevel::where('sku_id', $sku->id)
                ->where('location_id', $from->id)->first();
            $toLevel = StockLevel::where('sku_id', $sku->id)
                ->where('location_id', $to->id)->first();

            expect($fromLevel->on_hand)->toBe(152);
            expect($toLevel->on_hand)->toBe(48);

            // Both movements share the same reference_id (transfer link)
            expect($result['from']->reference_id)->toBe($result['to']->reference_id);
            expect($result['from']->quantity)->toBe(-48);
            expect($result['to']->quantity)->toBe(48);
        });
    });

    it('creates stock level automatically if it does not exist', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-math-auto');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();

            // No StockLevel row exists yet
            expect(StockLevel::count())->toBe(0);

            $service = app(InventoryService::class);
            $service->receive($sku->id, $location->id, 50, $userId);

            // StockLevel should have been auto-created
            $stockLevel = StockLevel::where('sku_id', $sku->id)
                ->where('location_id', $location->id)->first();

            expect($stockLevel)->not->toBeNull();
            expect($stockLevel->on_hand)->toBe(50);
        });
    });

    it('allows on_hand to go negative via sell (overselling allowed)', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-math-oversell');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'on_hand' => 2,
            ]);

            $service = app(InventoryService::class);
            // Sell more than on_hand — spec says warn but don't hard-block
            $service->sell($sku->id, $location->id, 5, $userId);

            $stockLevel = StockLevel::where('sku_id', $sku->id)->first();
            expect($stockLevel->on_hand)->toBe(-3);
        });
    });
})->group('inventory');

// ─── Tier 1: Tenant Isolation ────────────────────────────────────

describe('stock movement tenant isolation', function () {
    it('prevents cross-tenant movement access', function () {
        $tenantA = Tenant::create([
            'name' => 'Winery Alpha',
            'slug' => 'mvmt-iso-alpha',
            'plan' => 'pro',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Winery Beta',
            'slug' => 'mvmt-iso-beta',
            'plan' => 'pro',
        ]);

        $tenantA->run(function () {
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Alpha Cab',
                'vintage' => 2024,
                'varietal' => 'Cabernet Sauvignon',
            ]);
            $location = Location::create(['name' => 'Alpha Room']);

            StockMovement::create([
                'sku_id' => $sku->id,
                'location_id' => $location->id,
                'movement_type' => 'received',
                'quantity' => 100,
                'performed_at' => now(),
            ]);
        });

        $tenantB->run(function () {
            expect(StockMovement::count())->toBe(0);
        });

        $tenantA->run(function () {
            expect(StockMovement::count())->toBe(1);
        });
    });
})->group('inventory');

// ─── Tier 2: Movement Ledger ────────────────────────────────────

describe('movement ledger', function () {
    it('creates an immutable movement record for each stock change', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-ledger');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();
            $service = app(InventoryService::class);

            $service->receive($sku->id, $location->id, 100, $userId);
            $service->sell($sku->id, $location->id, 12, $userId);
            $service->adjust($sku->id, $location->id, -3, $userId, ['notes' => 'Breakage']);

            $movements = StockMovement::where('sku_id', $sku->id)
                ->orderBy('created_at')
                ->get();

            expect($movements)->toHaveCount(3);
            expect($movements[0]->movement_type)->toBe('received');
            expect($movements[0]->quantity)->toBe(100);
            expect($movements[1]->movement_type)->toBe('sold');
            expect($movements[1]->quantity)->toBe(-12);
            expect($movements[2]->movement_type)->toBe('adjusted');
            expect($movements[2]->quantity)->toBe(-3);
            expect($movements[2]->notes)->toBe('Breakage');
        });
    });

    it('records reference_type and reference_id for traceability', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-ref');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();
            $orderId = (string) \Illuminate\Support\Str::uuid();

            $service = app(InventoryService::class);
            $movement = $service->sell(
                skuId: $sku->id,
                locationId: $location->id,
                quantity: 6,
                performedBy: $userId,
                options: [
                    'reference_type' => 'order',
                    'reference_id' => $orderId,
                ],
            );

            expect($movement->reference_type)->toBe('order');
            expect($movement->reference_id)->toBe($orderId);
        });
    });

    it('transfer creates paired movements with shared reference_id', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-xfer-pair');

        $tenant->run(function () use ($userId) {
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Paired Transfer',
                'vintage' => 2024,
                'varietal' => 'Pinot Noir',
            ]);
            $from = Location::create(['name' => 'Stock Room']);
            $to = Location::create(['name' => 'Floor']);

            StockLevel::create([
                'sku_id' => $sku->id,
                'location_id' => $from->id,
                'on_hand' => 100,
            ]);

            $service = app(InventoryService::class);
            $result = $service->transfer($sku->id, $from->id, $to->id, 12, $userId, 'Restock floor');

            // Both movements linked by reference_id
            expect($result['from']->reference_type)->toBe('transfer');
            expect($result['to']->reference_type)->toBe('transfer');
            expect($result['from']->reference_id)->toBe($result['to']->reference_id);

            // Notes propagated
            expect($result['from']->notes)->toBe('Restock floor');
            expect($result['to']->notes)->toBe('Restock floor');

            // Total movements = 2
            expect(StockMovement::count())->toBe(2);
        });
    });
})->group('inventory');

// ─── Tier 2: Validation ──────────────────────────────────────────

describe('inventory service validation', function () {
    it('rejects receive with zero or negative quantity', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-val-recv');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();
            $service = app(InventoryService::class);

            expect(fn () => $service->receive($sku->id, $location->id, 0, $userId))
                ->toThrow(\InvalidArgumentException::class, 'Receive quantity must be positive.');

            expect(fn () => $service->receive($sku->id, $location->id, -5, $userId))
                ->toThrow(\InvalidArgumentException::class, 'Receive quantity must be positive.');
        });
    });

    it('rejects sell with zero or negative quantity', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-val-sell');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();
            $service = app(InventoryService::class);

            expect(fn () => $service->sell($sku->id, $location->id, 0, $userId))
                ->toThrow(\InvalidArgumentException::class, 'Sell quantity must be positive');

            expect(fn () => $service->sell($sku->id, $location->id, -3, $userId))
                ->toThrow(\InvalidArgumentException::class, 'Sell quantity must be positive');
        });
    });

    it('rejects transfer with zero or negative quantity', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-val-xfer-qty');

        $tenant->run(function () use ($userId) {
            $sku = CaseGoodsSku::create([
                'wine_name' => '2024 Val Wine',
                'vintage' => 2024,
                'varietal' => 'Merlot',
            ]);
            $from = Location::create(['name' => 'A']);
            $to = Location::create(['name' => 'B']);
            $service = app(InventoryService::class);

            expect(fn () => $service->transfer($sku->id, $from->id, $to->id, 0, $userId))
                ->toThrow(\InvalidArgumentException::class, 'Transfer quantity must be positive.');
        });
    });

    it('rejects transfer to the same location', function () {
        [$tenant, $userId] = createMovementTestTenant('mvmt-val-xfer-same');

        $tenant->run(function () use ($userId) {
            ['sku' => $sku, 'location' => $location] = createSkuAndLocation();
            $service = app(InventoryService::class);

            expect(fn () => $service->transfer($sku->id, $location->id, $location->id, 10, $userId))
                ->toThrow(\InvalidArgumentException::class, 'Cannot transfer to the same location.');
        });
    });
})->group('inventory');
