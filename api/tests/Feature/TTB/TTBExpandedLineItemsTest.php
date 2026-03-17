<?php

declare(strict_types=1);

use App\Models\LabAnalysis;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventLogger;
use App\Services\TTB\PartFiveCalculator;
use App\Services\TTB\PartFourCalculator;
use App\Services\TTB\PartThreeCalculator;
use App\Services\TTB\PartTwoCalculator;
use App\Services\TTB\TTBReportGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant for expanded TTB line item tests.
 */
function seedAndGetExpandedTenant(string $slug = 'ttb-expanded'): array
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
            'role' => 'admin',
            'is_active' => true,
        ]);
        $user->assignRole('admin');
        $userId = $user->id;
    });

    return [$tenant, $userId];
}

/**
 * Helper: create a lot with lab data and return its ID.
 */
function createLotWithAlcohol(string $name, string $variety, float $volume, float $alcohol, string $userId): string
{
    $lot = Lot::create([
        'name' => $name,
        'variety' => $variety,
        'vintage' => 2024,
        'source_type' => 'estate',
        'volume_gallons' => $volume,
    ]);

    LabAnalysis::create([
        'lot_id' => $lot->id,
        'test_date' => '2025-01-01',
        'test_type' => 'alcohol',
        'value' => (string) $alcohol,
        'unit' => '%v/v',
        'source' => 'manual',
        'performed_by' => $userId,
    ]);

    return $lot->id;
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

// ─── Part II: Additional Production Methods ──────────────────────────

describe('Part II expanded production methods', function () {
    it('aggregates sweetening_completed events on Line 3', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-sweet');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);
            $lotId = createLotWithAlcohol('Sweet Riesling', 'Riesling', 500, 10.5, $userId);

            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'sweetening_completed',
                payload: ['lot_id' => $lotId, 'volume_produced' => 25.0, 'sugar_type' => 'grape_concentrate'],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 15),
            );

            $partTwo = app(PartTwoCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partTwo->calculate($from, $to);

            $sweetLines = array_filter($lines, fn ($l) => $l['category'] === 'wine_produced_sweetening');
            expect(count($sweetLines))->toBe(1);

            $line = array_values($sweetLines)[0];
            expect($line['line_number'])->toBe(3);
            expect($line['section'])->toBe('A');
            expect($line['gallons'])->toBe(25.0);
            expect($line['wine_type'])->toBe('not_over_16');
        });
    });

    it('aggregates fortification_completed events on Line 4', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-fort');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);
            $lotId = createLotWithAlcohol('Port Base', 'Touriga Nacional', 300, 19.5, $userId);

            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'fortification_completed',
                payload: ['lot_id' => $lotId, 'volume_produced' => 50.0, 'spirit_proof' => 190],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 20),
            );

            $partTwo = app(PartTwoCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partTwo->calculate($from, $to);

            $fortLines = array_filter($lines, fn ($l) => $l['category'] === 'wine_produced_spirits');
            expect(count($fortLines))->toBe(1);

            $line = array_values($fortLines)[0];
            expect($line['line_number'])->toBe(4);
            expect($line['gallons'])->toBe(50.0);
            expect($line['wine_type'])->toBe('over_16_to_21');
        });
    });

    it('aggregates amelioration_completed events on Line 6', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-amel');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);
            $lotId = createLotWithAlcohol('Ameliorated Cab', 'Cabernet Sauvignon', 800, 14.0, $userId);

            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'amelioration_completed',
                payload: ['lot_id' => $lotId, 'volume_produced' => 40.0, 'water_gallons' => 30.0, 'sugar_lbs' => 50.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 18),
            );

            $partTwo = app(PartTwoCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partTwo->calculate($from, $to);

            $amelLines = array_filter($lines, fn ($l) => $l['category'] === 'wine_produced_amelioration');
            expect(count($amelLines))->toBe(1);

            $line = array_values($amelLines)[0];
            expect($line['line_number'])->toBe(6);
            expect($line['gallons'])->toBe(40.0);
        });
    });

    it('uses fixed line numbers — multiple wine types share the same line', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-fixed-ln');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            // Two lots with different alcohol — both fermentation (line 2)
            $lotId1 = createLotWithAlcohol('Table Lot', 'Chardonnay', 500, 12.0, $userId);
            $lotId2 = createLotWithAlcohol('Dessert Lot', 'Touriga Nacional', 200, 19.5, $userId);

            $logger->log(
                entityType: 'lot',
                entityId: $lotId1,
                operationType: 'lot_created',
                payload: ['name' => 'Table Lot', 'initial_volume' => 500.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 5),
            );

            $logger->log(
                entityType: 'lot',
                entityId: $lotId2,
                operationType: 'lot_created',
                payload: ['name' => 'Dessert Lot', 'initial_volume' => 200.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 10),
            );

            $partTwo = app(PartTwoCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partTwo->calculate($from, $to);

            $fermentationLines = array_filter($lines, fn ($l) => $l['category'] === 'wine_produced');
            expect(count($fermentationLines))->toBe(2);

            // Both should be on line 2 (the form line for fermentation)
            foreach ($fermentationLines as $line) {
                expect($line['line_number'])->toBe(2);
            }

            // Different wine types on the same line
            $wineTypes = array_column($fermentationLines, 'wine_type');
            expect($wineTypes)->toContain('not_over_16');
            expect($wineTypes)->toContain('over_16_to_21');
        });
    });
})->group('compliance');

// ─── Part III: Additional Receipt Types ──────────────────────────────

describe('Part III expanded receipt types', function () {
    it('aggregates wine_returned_to_bulk events on Line 9', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-return');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);
            $lotId = createLotWithAlcohol('Returned Wine', 'Merlot', 100, 13.0, $userId);

            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'wine_returned_to_bulk',
                payload: ['lot_id' => $lotId, 'volume_gallons' => 50.0, 'reason' => 'label_defect'],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 22),
            );

            $partThree = app(PartThreeCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partThree->calculate($from, $to);

            $returnLines = array_filter($lines, fn ($l) => $l['category'] === 'wine_returned_to_bond');
            expect(count($returnLines))->toBe(1);

            $line = array_values($returnLines)[0];
            expect($line['line_number'])->toBe(9);
            expect($line['section'])->toBe('A');
            expect($line['gallons'])->toBe(50.0);
        });
    });
})->group('compliance');

// ─── Part IV: Additional Removal Categories ──────────────────────────

describe('Part IV expanded removal categories', function () {
    it('aggregates wine_transferred_bonded events on Section A Line 15', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-xfer');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);
            $lotId = createLotWithAlcohol('Transfer Lot', 'Pinot Noir', 300, 13.5, $userId);

            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'wine_transferred_bonded',
                payload: ['lot_id' => $lotId, 'volume_gallons' => 300.0, 'destination_permit' => 'BWC-CA-12345'],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 25),
            );

            $partFour = app(PartFourCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partFour->calculate($from, $to);

            $xferLines = array_filter($lines, fn ($l) => $l['category'] === 'transferred_bonded');
            expect(count($xferLines))->toBe(1);

            $line = array_values($xferLines)[0];
            expect($line['line_number'])->toBe(15);
            expect($line['section'])->toBe('A');
            expect($line['gallons'])->toBe(300.0);
        });
    });

    it('aggregates breakage_reported events on Section A Line 23', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-break');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);
            $lotId = createLotWithAlcohol('Breakage Lot', 'Syrah', 500, 14.0, $userId);

            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'breakage_reported',
                payload: ['lot_id' => $lotId, 'volume_gallons' => 15.0, 'cause' => 'tank_valve_failure'],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 12),
            );

            $partFour = app(PartFourCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partFour->calculate($from, $to);

            $breakLines = array_filter($lines, fn ($l) => $l['category'] === 'breakage_bulk');
            expect(count($breakLines))->toBe(1);

            $line = array_values($breakLines)[0];
            expect($line['line_number'])->toBe(23);
            expect($line['section'])->toBe('A');
            expect($line['gallons'])->toBe(15.0);
        });
    });

    it('aggregates bottled_breakage events on Section B Line 13', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-bbreak');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);
            $lotId = createLotWithAlcohol('Bottled Break Lot', 'Cabernet Sauvignon', 200, 13.5, $userId);

            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'bottled_breakage',
                payload: ['lot_id' => $lotId, 'volume_gallons' => 5.0, 'cases_broken' => 2],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 28),
            );

            $partFour = app(PartFourCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partFour->calculate($from, $to);

            $breakLines = array_filter($lines, fn ($l) => $l['category'] === 'bottled_breakage');
            expect(count($breakLines))->toBe(1);

            $line = array_values($breakLines)[0];
            expect($line['line_number'])->toBe(13);
            expect($line['section'])->toBe('B');
            expect($line['gallons'])->toBe(5.0);
        });
    });

    it('uses fixed line numbers for bottling — all wine types on Line 13', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-btl-ln');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $lotId1 = createLotWithAlcohol('Table Bottling', 'Chardonnay', 500, 12.0, $userId);
            $lotId2 = createLotWithAlcohol('Dessert Bottling', 'Touriga Nacional', 200, 19.5, $userId);

            $logger->log(
                entityType: 'lot',
                entityId: $lotId1,
                operationType: 'bottling_completed',
                payload: ['lot_id' => $lotId1, 'volume_bottled_gallons' => 450.0, 'bottles' => 2273, 'waste_pct' => 1.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 20),
            );

            $logger->log(
                entityType: 'lot',
                entityId: $lotId2,
                operationType: 'bottling_completed',
                payload: ['lot_id' => $lotId2, 'volume_bottled_gallons' => 180.0, 'bottles' => 909, 'waste_pct' => 1.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 22),
            );

            $partFour = app(PartFourCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partFour->calculate($from, $to);

            // Section A bottling lines should all be on line 13
            $sectionABottling = array_filter(
                $lines,
                fn ($l) => $l['section'] === 'A' && $l['category'] === 'wine_bottled'
            );
            expect(count($sectionABottling))->toBe(2);
            foreach ($sectionABottling as $line) {
                expect($line['line_number'])->toBe(13);
            }

            // Section B bottling lines should all be on line 2
            $sectionBBottling = array_filter(
                $lines,
                fn ($l) => $l['section'] === 'B' && $l['category'] === 'wine_bottled'
            );
            expect(count($sectionBBottling))->toBe(2);
            foreach ($sectionBBottling as $line) {
                expect($line['line_number'])->toBe(2);
            }
        });
    });
})->group('compliance');

// ─── Part V: Evaporation Losses ──────────────────────────────────────

describe('Part V evaporation losses', function () {
    it('aggregates evaporation_measured events on Line 29', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-evap');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);
            $lotId = createLotWithAlcohol('Barrel Aging Lot', 'Cabernet Sauvignon', 500, 14.5, $userId);

            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'evaporation_measured',
                payload: ['lot_id' => $lotId, 'loss_gallons' => 8.0, 'vessel_type' => 'barrel'],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 30),
            );

            $partFive = app(PartFiveCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partFive->calculate($from, $to);

            $evapLines = array_filter($lines, fn ($l) => $l['category'] === 'evaporation_loss');
            expect(count($evapLines))->toBe(1);

            $line = array_values($evapLines)[0];
            expect($line['line_number'])->toBe(29);
            expect($line['section'])->toBe('A');
            expect($line['gallons'])->toBe(8.0);
            expect($line['description'])->toContain("angel's share");
        });
    });
})->group('compliance');

// ─── Full Report Integration with New Categories ─────────────────────

describe('full report with expanded line items', function () {
    it('includes transfers and breakage in Section A totals', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-full');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);
            $lotId = createLotWithAlcohol('Full Expanded', 'Pinot Noir', 2000, 13.5, $userId);

            // Production (fermentation)
            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'lot_created',
                payload: ['name' => 'Full Expanded', 'initial_volume' => 2000.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 5),
            );

            // Transfer to another bonded premises (300 gallons)
            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'wine_transferred_bonded',
                payload: ['lot_id' => $lotId, 'volume_gallons' => 300.0, 'destination_permit' => 'BWC-CA-99999'],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 20),
            );

            // Breakage (10 gallons)
            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'breakage_reported',
                payload: ['lot_id' => $lotId, 'volume_gallons' => 10.0, 'cause' => 'forklift_accident'],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 22),
            );

            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(
                month: 1,
                year: 2025,
                openingBulkInventory: 5000.0,
            );

            $summary = $report['section_a']['summary'];

            expect($summary['total_produced'])->toBe(2000.0);
            // Transferred includes both transfer_bonded (300) + breakage_bulk (10) = 310
            expect($summary['total_transferred'])->toBe(310.0);
            expect($summary['total_bottled'])->toBe(0.0);
            expect($summary['total_losses'])->toBe(0.0);

            // Closing = (5000 + 2000 + 0) - (0 + 0 + 310 + 0) = 6690
            expect($summary['closing_inventory'])->toBe(6690.0);
            expect($summary['balanced'])->toBeTrue();
        });
    });

    it('includes bottled breakage in Section B totals', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-full-b');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);
            $lotId = createLotWithAlcohol('Section B Test', 'Merlot', 1000, 13.0, $userId);

            // Bottling (500 gallons bulk → bottled)
            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'bottling_completed',
                payload: ['lot_id' => $lotId, 'volume_bottled_gallons' => 500.0, 'bottles' => 2525, 'waste_pct' => 0.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 15),
            );

            // Bottled breakage (12 gallons)
            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'bottled_breakage',
                payload: ['lot_id' => $lotId, 'volume_gallons' => 12.0, 'cases_broken' => 5],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 25),
            );

            // Sales (100 gallons)
            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'stock_sold',
                payload: ['lot_id' => $lotId, 'volume_gallons' => 100.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 28),
            );

            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(
                month: 1,
                year: 2025,
                openingBulkInventory: 0.0,
                openingBottledInventory: 200.0,
            );

            $summaryB = $report['section_b']['summary'];

            expect($summaryB['opening_inventory'])->toBe(200.0);
            expect($summaryB['total_bottled'])->toBe(500.0);
            expect($summaryB['total_removed_taxpaid'])->toBe(100.0);
            expect($summaryB['total_breakage'])->toBe(12.0);

            // Closing = (200 + 500 + 0) - (100 + 0 + 12 + 0) = 588
            expect($summaryB['closing_inventory'])->toBe(588.0);
            expect($summaryB['balanced'])->toBeTrue();
        });
    });

    it('includes sweetening and fortification in Section A production totals', function () {
        [$tenant, $userId] = seedAndGetExpandedTenant('ttb-exp-full-prod');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);
            $lotId = createLotWithAlcohol('Multi Production', 'Zinfandel', 1000, 14.0, $userId);

            // Fermentation
            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'lot_created',
                payload: ['name' => 'Multi Production', 'initial_volume' => 1000.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 5),
            );

            // Sweetening (20 gallons produced)
            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'sweetening_completed',
                payload: ['lot_id' => $lotId, 'volume_produced' => 20.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 15),
            );

            // Amelioration (30 gallons produced)
            $logger->log(
                entityType: 'lot',
                entityId: $lotId,
                operationType: 'amelioration_completed',
                payload: ['lot_id' => $lotId, 'volume_produced' => 30.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 18),
            );

            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(
                month: 1,
                year: 2025,
                openingBulkInventory: 0.0,
            );

            // Total produced = 1000 (fermentation) + 20 (sweetening) + 30 (amelioration) = 1050
            expect($report['section_a']['summary']['total_produced'])->toBe(1050.0);
            expect($report['section_a']['summary']['balanced'])->toBeTrue();
        });
    });
})->group('compliance');
