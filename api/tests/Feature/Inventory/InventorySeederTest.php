<?php

declare(strict_types=1);

use App\Models\CaseGoodsSku;
use App\Models\DryGoodsItem;
use App\Models\Equipment;
use App\Models\Location;
use App\Models\MaintenanceLog;
use App\Models\PhysicalCount;
use App\Models\PhysicalCountLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\RawMaterial;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Tenant;
use Database\Seeders\DemoWinerySeeder;
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

// ─── Helper ──────────────────────────────────────────────────────

function seedAndGetInventoryTenant(): Tenant
{
    test()->seed(DemoWinerySeeder::class);

    $tenant = Tenant::where('slug', 'paso-robles-cellars')->first();
    expect($tenant)->not->toBeNull();

    return $tenant;
}

// ─── Tier 1: Locations ───────────────────────────────────────────

it('seeds three inventory locations', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $locations = Location::all();
        expect($locations)->toHaveCount(3);

        $names = $locations->pluck('name')->toArray();
        expect($names)->toContain('Tasting Room Floor');
        expect($names)->toContain('Back Stock');
        expect($names)->toContain('Offsite Warehouse');
    });
})->group('inventory');

// ─── Tier 1: Case Goods SKUs ────────────────────────────────────

it('seeds at least 40 case goods SKUs', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $count = CaseGoodsSku::count();
        expect($count)->toBeGreaterThanOrEqual(40);
    });
})->group('inventory');

it('seeds case goods from completed bottling runs', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        // SKUs linked to bottling runs should exist
        $linked = CaseGoodsSku::whereNotNull('bottling_run_id')->count();
        expect($linked)->toBeGreaterThan(0);
    });
})->group('inventory');

it('seeds multiple wine formats including magnums and cans', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $formats = CaseGoodsSku::distinct()->pluck('format')->toArray();
        expect($formats)->toContain('750ml');
        expect($formats)->toContain('1.5L');
        expect($formats)->toContain('250ml');
    });
})->group('inventory');

it('seeds both active and inactive SKUs', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $active = CaseGoodsSku::where('is_active', true)->count();
        $inactive = CaseGoodsSku::where('is_active', false)->count();
        expect($active)->toBeGreaterThan(0);
        expect($inactive)->toBeGreaterThan(0);
    });
})->group('inventory');

// ─── Tier 1: Stock Levels ───────────────────────────────────────

it('seeds stock levels across multiple locations', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $count = StockLevel::count();
        expect($count)->toBeGreaterThan(50);

        // Stock should exist at multiple locations
        $locationCount = StockLevel::distinct()->count('location_id');
        expect($locationCount)->toBeGreaterThanOrEqual(2);
    });
})->group('inventory');

it('seeds stock movements for each stock level', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $movementCount = StockMovement::count();
        expect($movementCount)->toBeGreaterThan(50);

        // All movements should have a performed_by user
        $orphaned = StockMovement::whereNull('performed_by')->count();
        expect($orphaned)->toBe(0);
    });
})->group('inventory');

// ─── Tier 1: Dry Goods ──────────────────────────────────────────

it('seeds 22 dry goods items', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $count = DryGoodsItem::count();
        expect($count)->toBe(22);
    });
})->group('inventory');

it('seeds dry goods with expected item types', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $types = DryGoodsItem::distinct()->pluck('item_type')->toArray();
        expect($types)->toContain('bottle');
        expect($types)->toContain('cork');
        expect($types)->toContain('capsule');
        expect($types)->toContain('label_front');
        expect($types)->toContain('carton');
    });
})->group('inventory');

it('seeds dry goods with realistic quantities and costs', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        // All items should have positive on_hand and cost
        $items = DryGoodsItem::all();
        foreach ($items as $item) {
            expect((float) $item->on_hand)->toBeGreaterThan(0);
            expect((float) $item->cost_per_unit)->toBeGreaterThan(0);
            expect($item->vendor_name)->not->toBeNull();
        }
    });
})->group('inventory');

// ─── Tier 1: Raw Materials ──────────────────────────────────────

it('seeds 18 raw materials', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $count = RawMaterial::count();
        expect($count)->toBe(18);
    });
})->group('inventory');

it('seeds raw materials with expected categories', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $categories = RawMaterial::distinct()->pluck('category')->toArray();
        expect($categories)->toContain('additive');
        expect($categories)->toContain('acid');
        expect($categories)->toContain('yeast');
        expect($categories)->toContain('nutrient');
        expect($categories)->toContain('fining_agent');
        expect($categories)->toContain('enzyme');
        expect($categories)->toContain('oak_alternative');
    });
})->group('inventory');

it('seeds raw materials with expiration dates where applicable', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $withExpiry = RawMaterial::whereNotNull('expiration_date')->count();
        $withoutExpiry = RawMaterial::whereNull('expiration_date')->count();
        expect($withExpiry)->toBeGreaterThan(0);
        expect($withoutExpiry)->toBeGreaterThan(0); // Oak alternatives have no expiry
    });
})->group('inventory');

// ─── Tier 1: Equipment ──────────────────────────────────────────

it('seeds 6 equipment items', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $count = Equipment::count();
        expect($count)->toBe(6);
    });
})->group('inventory');

it('seeds equipment with maintenance logs', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $logCount = MaintenanceLog::count();
        expect($logCount)->toBeGreaterThanOrEqual(15);

        // All equipment should have at least one log
        $equipmentWithLogs = MaintenanceLog::distinct()->count('equipment_id');
        expect($equipmentWithLogs)->toBe(6);
    });
})->group('inventory');

it('seeds equipment with various maintenance types', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $types = MaintenanceLog::distinct()->pluck('maintenance_type')->toArray();
        expect($types)->toContain('cleaning');
        expect($types)->toContain('calibration');
        expect($types)->toContain('inspection');
        expect($types)->toContain('repair');
    });
})->group('inventory');

// ─── Tier 1: Purchase Orders ────────────────────────────────────

it('seeds 4 purchase orders', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $count = PurchaseOrder::count();
        expect($count)->toBe(4);
    });
})->group('inventory');

it('seeds purchase orders with all four statuses', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $statuses = PurchaseOrder::distinct()->pluck('status')->toArray();
        expect($statuses)->toContain('received');
        expect($statuses)->toContain('partial');
        expect($statuses)->toContain('ordered');
        expect($statuses)->toContain('cancelled');
    });
})->group('inventory');

it('seeds purchase order lines referencing inventory items', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $lineCount = PurchaseOrderLine::count();
        expect($lineCount)->toBeGreaterThanOrEqual(5);

        // Lines should reference both dry goods and raw materials
        $itemTypes = PurchaseOrderLine::distinct()->pluck('item_type')->toArray();
        expect($itemTypes)->toContain('dry_goods');
        expect($itemTypes)->toContain('raw_material');
    });
})->group('inventory');

it('seeds received PO lines with quantity_received matching quantity_ordered', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $receivedPo = PurchaseOrder::where('status', 'received')->first();
        expect($receivedPo)->not->toBeNull();

        $lines = PurchaseOrderLine::where('purchase_order_id', $receivedPo->id)->get();
        foreach ($lines as $line) {
            expect((float) $line->quantity_received)->toEqual((float) $line->quantity_ordered);
        }
    });
})->group('inventory');

it('seeds partial PO with at least one line not fully received', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $partialPo = PurchaseOrder::where('status', 'partial')->first();
        expect($partialPo)->not->toBeNull();

        $lines = PurchaseOrderLine::where('purchase_order_id', $partialPo->id)->get();
        $hasPartial = $lines->contains(fn ($line) => (float) $line->quantity_received < (float) $line->quantity_ordered);
        expect($hasPartial)->toBeTrue();
    });
})->group('inventory');

// ─── Tier 1: Physical Counts ────────────────────────────────────

it('seeds 2 physical counts', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $count = PhysicalCount::count();
        expect($count)->toBe(2);
    });
})->group('inventory');

it('seeds a completed physical count with variances', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $completed = PhysicalCount::where('status', 'completed')->first();
        expect($completed)->not->toBeNull();
        expect($completed->completed_by)->not->toBeNull();
        expect($completed->completed_at)->not->toBeNull();

        $lines = PhysicalCountLine::where('physical_count_id', $completed->id)->get();
        expect($lines->count())->toBeGreaterThan(0);

        // At least one line should have a non-zero variance
        $hasVariance = $lines->contains(fn ($line) => $line->variance !== 0);
        expect($hasVariance)->toBeTrue();

        // All lines in a completed count should have counted_quantity
        $uncounted = $lines->whereNull('counted_quantity')->count();
        expect($uncounted)->toBe(0);
    });
})->group('inventory');

it('seeds an in-progress physical count with some lines pending', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $inProgress = PhysicalCount::where('status', 'in_progress')->first();
        expect($inProgress)->not->toBeNull();
        expect($inProgress->completed_by)->toBeNull();
        expect($inProgress->completed_at)->toBeNull();

        $lines = PhysicalCountLine::where('physical_count_id', $inProgress->id)->get();
        expect($lines->count())->toBeGreaterThan(0);

        // Some lines should be counted, some still pending
        $counted = $lines->whereNotNull('counted_quantity')->count();
        $pending = $lines->whereNull('counted_quantity')->count();
        expect($counted)->toBeGreaterThan(0);
        expect($pending)->toBeGreaterThan(0);
    });
})->group('inventory');

// ─── Tier 2: Data Integrity ─────────────────────────────────────

it('seeds stock levels that reference valid SKUs and locations', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $stockLevels = StockLevel::all();

        foreach ($stockLevels as $sl) {
            $skuExists = CaseGoodsSku::where('id', $sl->sku_id)->exists();
            $locationExists = Location::where('id', $sl->location_id)->exists();
            expect($skuExists)->toBeTrue();
            expect($locationExists)->toBeTrue();
        }
    });
})->group('inventory');

it('seeds purchase order lines that reference existing inventory items', function () {
    $tenant = seedAndGetInventoryTenant();

    $tenant->run(function () {
        $lines = PurchaseOrderLine::all();

        foreach ($lines as $line) {
            if ($line->item_type === 'dry_goods') {
                expect(DryGoodsItem::where('id', $line->item_id)->exists())->toBeTrue();
            } elseif ($line->item_type === 'raw_material') {
                expect(RawMaterial::where('id', $line->item_id)->exists())->toBeTrue();
            }
        }
    });
})->group('inventory');
