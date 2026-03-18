<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\LabAnalysis;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventLogger;
use App\Services\TTB\PartFiveCalculator;
use App\Services\TTB\PartFourCalculator;
use App\Services\TTB\PartOneCalculator;
use App\Services\TTB\PartTwoCalculator;
use App\Services\TTB\TTBReportGenerator;
use App\Services\TTB\WineTypeClassifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with users for compliance tests.
 */
function seedAndGetComplianceTenant(string $slug = 'ttb-winery', string $role = 'admin'): array
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
    $tenant->run(function () use ($role, &$userId) {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create([
            'name' => 'Test '.ucfirst($role),
            'email' => "{$role}@example.com",
            'password' => 'SecurePass123!',
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $userId = $user->id;
    });

    $loginResponse = test()->postJson('/api/v1/auth/login', [
        'email' => "{$role}@example.com",
        'password' => 'SecurePass123!',
        'client_type' => 'portal',
        'device_name' => 'Test Browser',
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);

    return [$tenant, $loginResponse->json('data.token'), $userId];
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

// ─── Tier 1: Wine Type Classification ────────────────────────────────

describe('wine type classification', function () {
    it('classifies table wine when alcohol is 16% or below (CBMA threshold)', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-classify-1');

        $tenant->run(function () use ($userId) {
            $lot = Lot::create([
                'name' => 'Table Wine Lot',
                'variety' => 'Chardonnay',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 500,
            ]);

            LabAnalysis::create([
                'lot_id' => $lot->id,
                'test_date' => '2024-11-15',
                'test_type' => 'alcohol',
                'value' => '13.5',
                'unit' => '%v/v',
                'source' => 'manual',
                'performed_by' => $userId,
            ]);

            $classifier = app(WineTypeClassifier::class);
            $result = $classifier->classify($lot->id);

            expect($result['type'])->toBe('not_over_16');
            expect($result['alcohol_pct'])->toBe(13.5);
            expect($result['needs_review'])->toBeFalse();
            expect($result['source'])->toBe('lab_analysis');
        });
    });

    it('classifies dessert wine when alcohol is above 16%', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-classify-2');

        $tenant->run(function () use ($userId) {
            $lot = Lot::create([
                'name' => 'Port Lot',
                'variety' => 'Touriga Nacional',
                'vintage' => 2024,
                'source_type' => 'purchased',
                'volume_gallons' => 200,
            ]);

            LabAnalysis::create([
                'lot_id' => $lot->id,
                'test_date' => '2024-12-01',
                'test_type' => 'alcohol',
                'value' => '19.5',
                'unit' => '%v/v',
                'source' => 'manual',
                'performed_by' => $userId,
            ]);

            $classifier = app(WineTypeClassifier::class);
            $result = $classifier->classify($lot->id);

            expect($result['type'])->toBe('over_16_to_21');
            expect($result['alcohol_pct'])->toBe(19.5);
            expect($result['needs_review'])->toBeFalse();
        });
    });

    it('classifies exactly 16% as table wine (CBMA boundary)', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-classify-3');

        $tenant->run(function () use ($userId) {
            $lot = Lot::create([
                'name' => 'Boundary Lot',
                'variety' => 'Zinfandel',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 300,
            ]);

            LabAnalysis::create([
                'lot_id' => $lot->id,
                'test_date' => '2024-11-20',
                'test_type' => 'alcohol',
                'value' => '16.0',
                'unit' => '%v/v',
                'source' => 'manual',
                'performed_by' => $userId,
            ]);

            $classifier = app(WineTypeClassifier::class);
            $result = $classifier->classify($lot->id);

            expect($result['type'])->toBe('not_over_16');
        });
    });

    it('defaults to table wine with review flag when no lab data exists', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-classify-4');

        $tenant->run(function () {
            $lot = Lot::create([
                'name' => 'No Lab Data Lot',
                'variety' => 'Merlot',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 400,
            ]);

            $classifier = app(WineTypeClassifier::class);
            $result = $classifier->classify($lot->id);

            expect($result['type'])->toBe('not_over_16');
            expect($result['alcohol_pct'])->toBeNull();
            expect($result['needs_review'])->toBeTrue();
            expect($result['source'])->toBe('default_no_lab_data');
        });
    });

    it('classifies sparkling wine from event payload', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-classify-5');

        $tenant->run(function () {
            $lot = Lot::create([
                'name' => 'Sparkling Lot',
                'variety' => 'Chardonnay',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 200,
            ]);

            $classifier = app(WineTypeClassifier::class);
            $result = $classifier->classify($lot->id, ['wine_style' => 'sparkling']);

            expect($result['type'])->toBe('sparkling');
            expect($result['source'])->toBe('event_payload');
        });
    });

    it('uses most recent alcohol analysis when multiple exist', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-classify-6');

        $tenant->run(function () use ($userId) {
            $lot = Lot::create([
                'name' => 'Multi Analysis Lot',
                'variety' => 'Cabernet Sauvignon',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 500,
            ]);

            // Older reading: 12.5% (table)
            LabAnalysis::create([
                'lot_id' => $lot->id,
                'test_date' => '2024-10-01',
                'test_type' => 'alcohol',
                'value' => '12.5',
                'unit' => '%v/v',
                'source' => 'manual',
                'performed_by' => $userId,
            ]);

            // Newer reading: 18.5% (dessert — e.g., fortified)
            LabAnalysis::create([
                'lot_id' => $lot->id,
                'test_date' => '2024-12-01',
                'test_type' => 'alcohol',
                'value' => '18.5',
                'unit' => '%v/v',
                'source' => 'manual',
                'performed_by' => $userId,
            ]);

            $classifier = app(WineTypeClassifier::class);
            $result = $classifier->classify($lot->id);

            expect($result['type'])->toBe('over_16_to_21');
            expect($result['alcohol_pct'])->toBe(18.5);
        });
    });
})->group('compliance');

// ─── Tier 1: Part I Balance Equation ─────────────────────────────────

describe('Part I Section A balance equation', function () {
    it('calculates correct closing inventory for bulk wines', function () {
        $partOne = new PartOneCalculator;

        $result = $partOne->calculateSectionA(
            openingInventory: 5000.0,
            totalProduced: 2000.0,
            totalReceived: 500.0,
            totalBottled: 1500.0,
            totalRemovedTaxpaid: 0.0,
            totalTransferred: 0.0,
            totalLosses: 100.0,
        );

        // Closing = round(5000 + 2000 + 500, 0) - round(1500 + 0 + 0 + 100, 0) = 7500 - 1600 = 5900
        expect($result['closing_inventory'])->toBe(5900.0);
        expect($result['total_increases'])->toBe(7500.0);
        expect($result['balanced'])->toBeTrue();
    });

    it('handles zero activity month', function () {
        $partOne = new PartOneCalculator;

        $result = $partOne->calculateSectionA(
            openingInventory: 3000.0,
            totalProduced: 0.0,
            totalReceived: 0.0,
            totalBottled: 0.0,
            totalRemovedTaxpaid: 0.0,
            totalTransferred: 0.0,
            totalLosses: 0.0,
        );

        expect($result['closing_inventory'])->toBe(3000.0);
        expect($result['balanced'])->toBeTrue();
    });

    it('generates correct Section A line items', function () {
        $partOne = new PartOneCalculator;

        $lines = $partOne->generateSectionALineItems(
            openingInventory: 5000.0,
            totalProduced: 2000.0,
            totalReceived: 0.0,
            totalBottled: 1000.0,
            totalRemovedTaxpaid: 0.0,
            totalTransferred: 0.0,
            totalLosses: 50.0,
        );

        expect($lines)->toHaveCount(4);
        expect($lines[0]['category'])->toBe('on_hand_beginning');
        expect($lines[0]['gallons'])->toBe(5000.0);
        expect($lines[0]['section'])->toBe('A');
        // Closing = 7000 - 1050 = 5950
        expect($lines[2]['category'])->toBe('on_hand_end');
        expect($lines[2]['gallons'])->toBe(5950.0);
    });
})->group('compliance');

// ─── Tier 1: Part II Wine Produced ───────────────────────────────────

describe('Part II wine produced', function () {
    it('aggregates lot_created events into production totals', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-part2-1');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $lot1 = Lot::create([
                'name' => 'Cab Lot 2024',
                'variety' => 'Cabernet Sauvignon',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 1000,
            ]);

            LabAnalysis::create([
                'lot_id' => $lot1->id,
                'test_date' => '2024-12-15',
                'test_type' => 'alcohol',
                'value' => '13.8',
                'unit' => '%v/v',
                'source' => 'manual',
                'performed_by' => $userId,
            ]);

            $logger->log(
                entityType: 'lot',
                entityId: $lot1->id,
                operationType: 'lot_created',
                payload: [
                    'name' => 'Cab Lot 2024',
                    'variety' => 'Cabernet Sauvignon',
                    'initial_volume' => 1000.0,
                ],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 15),
            );

            $lot2 = Lot::create([
                'name' => 'Chard Lot 2024',
                'variety' => 'Chardonnay',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 800,
            ]);

            LabAnalysis::create([
                'lot_id' => $lot2->id,
                'test_date' => '2024-12-20',
                'test_type' => 'alcohol',
                'value' => '12.5',
                'unit' => '%v/v',
                'source' => 'manual',
                'performed_by' => $userId,
            ]);

            $logger->log(
                entityType: 'lot',
                entityId: $lot2->id,
                operationType: 'lot_created',
                payload: [
                    'name' => 'Chard Lot 2024',
                    'variety' => 'Chardonnay',
                    'initial_volume' => 800.0,
                ],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 20),
            );

            $partTwo = app(PartTwoCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partTwo->calculate($from, $to);

            $totalGallons = $partTwo->totalGallons($lines);

            expect($totalGallons)->toBe(1800.0);
            expect(count($lines))->toBeGreaterThanOrEqual(1);

            // All lines should be Not Over 16% wine (both lots under 16% CBMA threshold)
            foreach ($lines as $line) {
                if ($line['category'] === 'wine_produced') {
                    expect($line['wine_type'])->toBe('not_over_16');
                }
            }
        });
    });

    it('excludes events outside the reporting period', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-part2-2');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $lot = Lot::create([
                'name' => 'December Lot',
                'variety' => 'Pinot Noir',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 500,
            ]);

            // Event in December (should not be in January report)
            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'lot_created',
                payload: ['name' => 'December Lot', 'initial_volume' => 500.0],
                performedBy: $userId,
                performedAt: Carbon::create(2024, 12, 28),
            );

            $partTwo = app(PartTwoCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partTwo->calculate($from, $to);

            expect($partTwo->totalGallons($lines))->toBe(0.0);
        });
    });

    it('links source event IDs for audit trail', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-part2-3');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $lot = Lot::create([
                'name' => 'Audit Lot',
                'variety' => 'Syrah',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 600,
            ]);

            $event = $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'lot_created',
                payload: ['name' => 'Audit Lot', 'initial_volume' => 600.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 10),
            );

            $partTwo = app(PartTwoCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partTwo->calculate($from, $to);

            expect($lines)->not->toBeEmpty();
            expect($lines[0]['source_event_ids'])->toContain($event->id);
        });
    });
})->group('compliance');

// ─── Tier 1: Part IV Wine Removed ────────────────────────────────────

describe('Part IV wine removed from bond', function () {
    it('aggregates bottling_completed events', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-part4-1');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $lot = Lot::create([
                'name' => 'Bottling Lot',
                'variety' => 'Merlot',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 1000,
            ]);

            LabAnalysis::create([
                'lot_id' => $lot->id,
                'test_date' => '2025-01-05',
                'test_type' => 'alcohol',
                'value' => '13.2',
                'unit' => '%v/v',
                'source' => 'manual',
                'performed_by' => $userId,
            ]);

            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'bottling_completed',
                payload: [
                    'lot_id' => $lot->id,
                    'volume_bottled_gallons' => 950.5,
                    'bottles' => 4798,
                    'waste_pct' => 2.0,
                    'format' => '750ml',
                ],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 25),
            );

            $partFour = app(PartFourCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partFour->calculate($from, $to);

            // Part IV now produces Section A (decrease from bulk) and Section B (increase to bottled)
            $sectionALines = array_filter($lines, fn ($l) => $l['section'] === 'A');
            $sectionBLines = array_filter($lines, fn ($l) => $l['section'] === 'B');

            // Section A: bottled volume removed from bulk = 951 (rounded to whole gallons)
            expect($partFour->totalGallons($sectionALines))->toBe(951.0);
            // Section B: same volume received as bottled wine
            expect($partFour->totalGallons($sectionBLines))->toBe(951.0);
        });
    });
})->group('compliance');

// ─── Tier 1: Part V Losses ───────────────────────────────────────────

describe('Part V losses', function () {
    it('calculates bottling waste as a loss', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-part5-1');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $lot = Lot::create([
                'name' => 'Waste Lot',
                'variety' => 'Sangiovese',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 500,
            ]);

            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'bottling_completed',
                payload: [
                    'lot_id' => $lot->id,
                    'volume_bottled_gallons' => 500.0,
                    'waste_pct' => 3.0,
                ],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 20),
            );

            $partFive = app(PartFiveCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partFive->calculate($from, $to);

            // 500 gallons * 3% = 15 gallons waste
            $total = $partFive->totalGallons($lines);
            expect($total)->toBe(15.0);
        });
    });

    it('calculates transfer variance as a loss', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-part5-2');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $lot = Lot::create([
                'name' => 'Transfer Loss Lot',
                'variety' => 'Grenache',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 300,
            ]);

            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'transfer_executed',
                payload: [
                    'lot_id' => $lot->id,
                    'from_vessel' => 'Tank A',
                    'to_vessel' => 'Tank B',
                    'volume' => 300,
                    'variance' => -2.5,
                ],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 12),
            );

            $partFive = app(PartFiveCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partFive->calculate($from, $to);

            $total = $partFive->totalGallons($lines);
            expect($total)->toBe(3.0);
        });
    });

    it('ignores positive transfer variance (gains are not losses)', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-part5-3');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $lot = Lot::create([
                'name' => 'Gain Lot',
                'variety' => 'Viognier',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 200,
            ]);

            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'transfer_executed',
                payload: [
                    'lot_id' => $lot->id,
                    'from_vessel' => 'Tank C',
                    'to_vessel' => 'Tank D',
                    'volume' => 200,
                    'variance' => 1.0,
                ],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 15),
            );

            $partFive = app(PartFiveCalculator::class);
            $from = Carbon::create(2025, 1, 1)->startOfDay();
            $to = Carbon::create(2025, 1, 31)->endOfDay();
            $lines = $partFive->calculate($from, $to);

            expect($partFive->totalGallons($lines))->toBe(0.0);
        });
    });
})->group('compliance');

// ─── Tier 1: Full Report Generation (Integration) ───────────────────

describe('full TTB report generation', function () {
    it('generates a complete report with Section A and Section B', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-full-1');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            // Create lots with production events for January 2025
            $lot = Lot::create([
                'name' => 'Full Report Cab',
                'variety' => 'Cabernet Sauvignon',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 2000,
            ]);

            LabAnalysis::create([
                'lot_id' => $lot->id,
                'test_date' => '2025-01-05',
                'test_type' => 'alcohol',
                'value' => '13.5',
                'unit' => '%v/v',
                'source' => 'manual',
                'performed_by' => $userId,
            ]);

            // Part II: lot created (2000 gallons produced)
            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'lot_created',
                payload: ['name' => 'Full Report Cab', 'initial_volume' => 2000.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 5),
            );

            // Part IV: bottling (500 gallons removed)
            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'bottling_completed',
                payload: [
                    'lot_id' => $lot->id,
                    'volume_bottled_gallons' => 500.0,
                    'bottles' => 2525,
                    'waste_pct' => 2.0,
                ],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 25),
            );

            // Part V: transfer with loss (5 gallon variance)
            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'transfer_executed',
                payload: [
                    'lot_id' => $lot->id,
                    'from_vessel' => 'Tank 1',
                    'to_vessel' => 'Tank 2',
                    'volume' => 1000,
                    'variance' => -5.0,
                ],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 15),
            );

            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(
                month: 1,
                year: 2025,
                openingBulkInventory: 3000.0,
            );

            // Verify structure
            expect($report)->toHaveKeys([
                'period', 'section_a', 'section_b',
                'needs_review', 'review_flags', 'generated_at',
            ]);

            // Verify period
            expect($report['period']['month'])->toBe(1);
            expect($report['period']['year'])->toBe(2025);

            // Verify Section A summary (bulk wine operations)
            $summary = $report['section_a']['summary'];
            expect($summary['opening_inventory'])->toBe(3000.0);
            expect($summary['total_produced'])->toBe(2000.0);
            expect($summary['total_received'])->toBe(0.0);
            expect($summary['total_bottled'])->toBe(500.0);
            // Losses: 500 * 2% = 10 bottling waste + 5 transfer = 15
            expect($summary['total_losses'])->toBe(15.0);
            // Closing = 3000 + 2000 + 0 - 500 - 0 - 0 - 15 = 4485
            expect($summary['closing_inventory'])->toBe(4485.0);
            expect($summary['balanced'])->toBeTrue();
        });
    });

    it('generates report with no activity (empty month)', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-full-2');

        $tenant->run(function () {
            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(
                month: 2,
                year: 2025,
                openingBulkInventory: 1000.0,
            );

            expect($report['section_a']['summary']['closing_inventory'])->toBe(1000.0);
            expect($report['section_a']['summary']['total_produced'])->toBe(0.0);
            expect($report['section_a']['summary']['total_received'])->toBe(0.0);
            expect($report['section_a']['summary']['total_bottled'])->toBe(0.0);
            expect($report['section_a']['summary']['total_losses'])->toBe(0.0);
            expect($report['section_a']['summary']['balanced'])->toBeTrue();
        });
    });

    it('separates table and dessert wine into distinct line items', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-full-3');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            // Table wine lot
            $tableLot = Lot::create([
                'name' => 'Table Chard',
                'variety' => 'Chardonnay',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 500,
            ]);

            LabAnalysis::create([
                'lot_id' => $tableLot->id,
                'test_date' => '2025-01-01',
                'test_type' => 'alcohol',
                'value' => '12.0',
                'unit' => '%v/v',
                'source' => 'manual',
                'performed_by' => $userId,
            ]);

            $logger->log(
                entityType: 'lot',
                entityId: $tableLot->id,
                operationType: 'lot_created',
                payload: ['name' => 'Table Chard', 'initial_volume' => 500.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 5),
            );

            // Dessert wine lot
            $dessertLot = Lot::create([
                'name' => 'Port Style',
                'variety' => 'Touriga Nacional',
                'vintage' => 2024,
                'source_type' => 'purchased',
                'volume_gallons' => 200,
            ]);

            LabAnalysis::create([
                'lot_id' => $dessertLot->id,
                'test_date' => '2025-01-01',
                'test_type' => 'alcohol',
                'value' => '18.5',
                'unit' => '%v/v',
                'source' => 'manual',
                'performed_by' => $userId,
            ]);

            $logger->log(
                entityType: 'lot',
                entityId: $dessertLot->id,
                operationType: 'lot_created',
                payload: ['name' => 'Port Style', 'initial_volume' => 200.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 10),
            );

            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(month: 1, year: 2025, openingBulkInventory: 0.0);

            // Section A lines should have both not_over_16 and over_16_to_21 wine types
            $productionLines = array_filter(
                $report['section_a']['lines'],
                fn ($l) => ($l['category'] ?? '') === 'wine_produced'
            );
            $wineTypes = array_column($productionLines, 'wine_type');

            expect($wineTypes)->toContain('not_over_16');
            expect($wineTypes)->toContain('over_16_to_21');
            expect($report['section_a']['summary']['total_produced'])->toBe(700.0);
        });
    });

    it('flags report for review when lots lack lab data', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-full-4');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $lot = Lot::create([
                'name' => 'No Lab Lot',
                'variety' => 'Tempranillo',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 300,
            ]);

            // No lab analysis created — should trigger review flag
            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'lot_created',
                payload: ['name' => 'No Lab Lot', 'initial_volume' => 300.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 5),
            );

            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(month: 1, year: 2025, openingBulkInventory: 0.0);

            expect($report['needs_review'])->toBeTrue();
            expect($report['review_flags'])->not->toBeEmpty();
        });
    });

    it('rounds all volumes to whole gallons per TTB practice', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-full-5');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $lot = Lot::create([
                'name' => 'Precision Lot',
                'variety' => 'Riesling',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 333.33,
            ]);

            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'lot_created',
                payload: ['name' => 'Precision Lot', 'initial_volume' => 333.33],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 5),
            );

            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(month: 1, year: 2025, openingBulkInventory: 0.0);

            // Volume should be rounded to whole gallons per TTB practice
            expect($report['section_a']['summary']['total_produced'])->toBe(333.0);
        });
    });
})->group('compliance');

// ─── Tier 1: Event Source Registration ───────────────────────────────

describe('event source registration', function () {
    it('maps ttb_ prefix to compliance source', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-source-1');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $event = $logger->log(
                entityType: 'ttb_report',
                entityId: (string) Str::uuid(),
                operationType: 'ttb_report_generated',
                payload: ['period_month' => 1, 'period_year' => 2025],
                performedBy: $userId,
                performedAt: now(),
            );

            expect($event->event_source)->toBe('compliance');
        });
    });

    it('maps license_ prefix to compliance source', function () {
        [$tenant, $token, $userId] = seedAndGetComplianceTenant('ttb-source-2');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $event = $logger->log(
                entityType: 'license',
                entityId: (string) Str::uuid(),
                operationType: 'license_renewed',
                payload: ['license_type' => 'ttb_permit'],
                performedBy: $userId,
                performedAt: now(),
            );

            expect($event->event_source)->toBe('compliance');
        });
    });
})->group('compliance');
