<?php

declare(strict_types=1);

use App\Models\LabAnalysis;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventLogger;
use App\Services\TTB\TTBReportGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant for TTB verification tests.
 */
function seedAndGetVerificationTenant(string $slug = 'ttb-verify'): array
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
 * Load a fixture scenario and seed the data into the tenant.
 *
 * @return array{
 *     expected: array<string, mixed>,
 *     period: array{month: int, year: int},
 *     opening_inventory: float,
 * }
 */
function loadAndSeedScenario(Tenant $tenant, string $userId, string $fixtureFile): array
{
    $fixturePath = base_path('tests/Fixtures/ttb/'.$fixtureFile);
    $scenario = json_decode((string) file_get_contents($fixturePath), true);

    $tenant->run(function () use ($scenario, $userId) {
        $logger = app(EventLogger::class);

        // Create lots and their lab analyses
        foreach ($scenario['lots'] as $lotData) {
            $lot = Lot::create([
                'name' => $lotData['name'],
                'variety' => $lotData['variety'],
                'vintage' => $lotData['vintage'],
                'source_type' => $lotData['source_type'],
                'volume_gallons' => $lotData['volume_gallons'],
            ]);

            // Map fixture lot ID to real UUID for event seeding
            app()->instance('ttb_lot_map.'.$lotData['id'], $lot->id);

            if (isset($lotData['alcohol_pct'])) {
                LabAnalysis::create([
                    'lot_id' => $lot->id,
                    'test_date' => Carbon::create(
                        $scenario['period']['year'],
                        $scenario['period']['month'],
                        1
                    )->toDateString(),
                    'test_type' => 'alcohol',
                    'value' => (string) $lotData['alcohol_pct'],
                    'unit' => '%v/v',
                    'source' => 'manual',
                    'performed_by' => $userId,
                ]);
            }
        }

        // Create events
        foreach ($scenario['events'] as $eventData) {
            // Resolve lot IDs in entity_id and payload
            $entityId = $eventData['entity_id'];
            $resolvedEntityId = app()->bound('ttb_lot_map.'.$entityId)
                ? app('ttb_lot_map.'.$entityId)
                : $entityId;

            $payload = $eventData['payload'];
            // Resolve lot_id and new_lot_id in payloads
            foreach (['lot_id', 'new_lot_id'] as $key) {
                if (isset($payload[$key]) && app()->bound('ttb_lot_map.'.$payload[$key])) {
                    $payload[$key] = app('ttb_lot_map.'.$payload[$key]);
                }
            }

            $logger->log(
                entityType: $eventData['entity_type'],
                entityId: $resolvedEntityId,
                operationType: $eventData['operation_type'],
                payload: $payload,
                performedBy: $userId,
                performedAt: Carbon::parse($eventData['performed_at']),
            );
        }
    });

    return [
        'expected' => $scenario['expected'],
        'period' => $scenario['period'],
        'opening_inventory' => $scenario['opening_inventory'],
    ];
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

// ─── TTB Verification Suite ──────────────────────────────────────────

describe('TTB verification — small estate winery', function () {
    it('produces correct report matching expected totals', function () {
        [$tenant, $userId] = seedAndGetVerificationTenant('ttb-verify-1');
        $fixture = loadAndSeedScenario($tenant, $userId, 'scenario_small_estate.json');

        $tenant->run(function () use ($fixture) {
            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(
                month: $fixture['period']['month'],
                year: $fixture['period']['year'],
                openingInventory: $fixture['opening_inventory'],
            );

            // Verify Part II: Wine Produced
            expect($report['part_two']['total_gallons'])->toBe($fixture['expected']['part_two_total']);

            // Verify Part III: Wine Received
            expect($report['part_three']['total_gallons'])->toBe($fixture['expected']['part_three_total']);

            // Verify Part IV: Wine Removed
            expect($report['part_four']['total_gallons'])->toBe($fixture['expected']['part_four_total']);

            // Verify Part V: Losses (580 * 2.5% = 14.5 bottling + 3.5 transfer = 18.0)
            expect($report['part_five']['total_gallons'])->toBe($fixture['expected']['part_five_total']);

            // Verify closing inventory
            expect($report['part_one']['summary']['closing_inventory'])->toBe($fixture['expected']['closing_inventory']);

            // Verify balance
            expect($report['part_one']['summary']['balanced'])->toBe($fixture['expected']['balance_check']);
        });
    });

    it('has all lines classified as table wine', function () {
        [$tenant, $userId] = seedAndGetVerificationTenant('ttb-verify-1b');
        $fixture = loadAndSeedScenario($tenant, $userId, 'scenario_small_estate.json');

        $tenant->run(function () use ($fixture) {
            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(
                month: $fixture['period']['month'],
                year: $fixture['period']['year'],
                openingInventory: $fixture['opening_inventory'],
            );

            $allWineTypes = collect($report['part_two']['lines'])
                ->pluck('wine_type')
                ->unique()
                ->values()
                ->toArray();

            expect($allWineTypes)->toBe($fixture['expected']['all_wine_types']);
        });
    });

    it('links source event IDs to every line item', function () {
        [$tenant, $userId] = seedAndGetVerificationTenant('ttb-verify-1c');
        $fixture = loadAndSeedScenario($tenant, $userId, 'scenario_small_estate.json');

        $tenant->run(function () use ($fixture) {
            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(
                month: $fixture['period']['month'],
                year: $fixture['period']['year'],
                openingInventory: $fixture['opening_inventory'],
            );

            // Every Part II-V line with non-zero gallons should have source events
            foreach (['part_two', 'part_four', 'part_five'] as $part) {
                foreach ($report[$part]['lines'] as $line) {
                    if ($line['gallons'] > 0) {
                        expect($line['source_event_ids'])->not->toBeEmpty(
                            "Line '{$line['description']}' in {$part} has no source events"
                        );
                    }
                }
            }
        });
    });
})->group('compliance');

describe('TTB verification — mixed wine types', function () {
    it('correctly separates table and dessert wine', function () {
        [$tenant, $userId] = seedAndGetVerificationTenant('ttb-verify-2');
        $fixture = loadAndSeedScenario($tenant, $userId, 'scenario_mixed_wine_types.json');

        $tenant->run(function () use ($fixture) {
            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(
                month: $fixture['period']['month'],
                year: $fixture['period']['year'],
                openingInventory: $fixture['opening_inventory'],
            );

            // Verify totals
            expect($report['part_two']['total_gallons'])->toBe($fixture['expected']['part_two_total']);
            expect($report['part_four']['total_gallons'])->toBe($fixture['expected']['part_four_total']);
            expect($report['part_five']['total_gallons'])->toBe($fixture['expected']['part_five_total']);

            // Verify both wine types present
            $wineTypes = collect($report['part_two']['lines'])
                ->pluck('wine_type')
                ->unique()
                ->sort()
                ->values()
                ->toArray();

            expect($wineTypes)->toBe($fixture['expected']['all_wine_types']);

            // Verify balance
            expect($report['part_one']['summary']['balanced'])->toBe($fixture['expected']['balance_check']);

            // Closing inventory should match
            expect($report['part_one']['summary']['closing_inventory'])->toBe($fixture['expected']['closing_inventory']);
        });
    });

    it('classifies Port-style as dessert wine (19.5% alcohol)', function () {
        [$tenant, $userId] = seedAndGetVerificationTenant('ttb-verify-2b');
        $fixture = loadAndSeedScenario($tenant, $userId, 'scenario_mixed_wine_types.json');

        $tenant->run(function () use ($fixture) {
            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(
                month: $fixture['period']['month'],
                year: $fixture['period']['year'],
                openingInventory: $fixture['opening_inventory'],
            );

            $dessertLines = collect($report['part_two']['lines'])
                ->where('wine_type', 'dessert');

            expect($dessertLines->count())->toBeGreaterThan(0);

            // Dessert wine produced should be 200 + 150 = 350 gallons (Port + Late Harvest Zin)
            $dessertGallons = $dessertLines->sum('gallons');
            expect($dessertGallons)->toBe(350.0);
        });
    });
})->group('compliance');

describe('TTB verification — high volume operations', function () {
    it('handles blending and multiple losses correctly', function () {
        [$tenant, $userId] = seedAndGetVerificationTenant('ttb-verify-3');
        $fixture = loadAndSeedScenario($tenant, $userId, 'scenario_high_volume.json');

        $tenant->run(function () use ($fixture) {
            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(
                month: $fixture['period']['month'],
                year: $fixture['period']['year'],
                openingInventory: $fixture['opening_inventory'],
            );

            // Part II: 2000 + 1500 + 1000 (lots) + 3000 (blend) = 7500
            expect($report['part_two']['total_gallons'])->toBe($fixture['expected']['part_two_total']);

            // Part IV: 2900 (bottling)
            expect($report['part_four']['total_gallons'])->toBe($fixture['expected']['part_four_total']);

            // Part V: 2900 * 2% = 58 (bottling waste) + 8 + 2 (transfer losses) = 68
            expect($report['part_five']['total_gallons'])->toBe($fixture['expected']['part_five_total']);

            // Verify closing inventory
            expect($report['part_one']['summary']['closing_inventory'])->toBe($fixture['expected']['closing_inventory']);

            // Verify balance
            expect($report['part_one']['summary']['balanced'])->toBe($fixture['expected']['balance_check']);
        });
    });

    it('verifies Part I balance equation: opening + produced + received = closing + removed + losses', function () {
        [$tenant, $userId] = seedAndGetVerificationTenant('ttb-verify-3b');
        $fixture = loadAndSeedScenario($tenant, $userId, 'scenario_high_volume.json');

        $tenant->run(function () use ($fixture) {
            $generator = app(TTBReportGenerator::class);
            $report = $generator->generate(
                month: $fixture['period']['month'],
                year: $fixture['period']['year'],
                openingInventory: $fixture['opening_inventory'],
            );

            $summary = $report['part_one']['summary'];

            // Left side: opening + produced + received
            $leftSide = $summary['opening_inventory']
                + $summary['total_produced']
                + $summary['total_received'];

            // Right side: closing + removed + losses
            $rightSide = $summary['closing_inventory']
                + $summary['total_removed']
                + $summary['total_losses'];

            // Must balance within 0.1 gallon (rounding tolerance)
            expect(abs($leftSide - $rightSide))->toBeLessThan(0.1);
        });
    });
})->group('compliance');
