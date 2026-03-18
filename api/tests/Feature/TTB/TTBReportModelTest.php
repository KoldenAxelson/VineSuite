<?php

declare(strict_types=1);

use App\Jobs\GenerateMonthlyTTBReportJob;
use App\Models\Event;
use App\Models\LabAnalysis;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\TTBReport;
use App\Models\TTBReportLine;
use App\Models\User;
use App\Services\EventLogger;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

/*
 * Helper: create a tenant with users for TTB model tests.
 */
function seedAndGetTTBModelTenant(string $slug = 'ttb-model', string $role = 'admin'): array
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

// ─── Tier 1: TTB Report Model ────────────────────────────────────────

describe('TTB report model', function () {
    it('creates a report with correct attributes', function () {
        [$tenant, $token, $userId] = seedAndGetTTBModelTenant('ttb-model-1');

        $tenant->run(function () {
            $report = TTBReport::create([
                'report_period_month' => 1,
                'report_period_year' => 2025,
                'status' => 'draft',
                'generated_at' => now(),
                'data' => ['section_a' => ['summary' => ['closing_inventory' => 5000]], 'section_b' => ['summary' => ['closing_inventory' => 0]]],
            ]);

            expect($report->report_period_month)->toBe(1);
            expect($report->report_period_year)->toBe(2025);
            expect($report->status)->toBe('draft');
            expect($report->canRegenerate())->toBeTrue();
            expect($report->canReview())->toBeTrue();
            expect($report->periodLabel())->toBe('January 2025');
        });
    });

    it('enforces unique month/year constraint', function () {
        [$tenant, $token, $userId] = seedAndGetTTBModelTenant('ttb-model-2');

        $tenant->run(function () {
            TTBReport::create([
                'report_period_month' => 1,
                'report_period_year' => 2025,
                'status' => 'draft',
                'generated_at' => now(),
            ]);

            expect(fn () => TTBReport::create([
                'report_period_month' => 1,
                'report_period_year' => 2025,
                'status' => 'draft',
                'generated_at' => now(),
            ]))->toThrow(QueryException::class);
        });
    });

    it('has many line items', function () {
        [$tenant, $token, $userId] = seedAndGetTTBModelTenant('ttb-model-3');

        $tenant->run(function () {
            $report = TTBReport::create([
                'report_period_month' => 2,
                'report_period_year' => 2025,
                'status' => 'draft',
                'generated_at' => now(),
            ]);

            TTBReportLine::create([
                'ttb_report_id' => $report->id,
                'part' => 'I',
                'section' => 'A',
                'line_number' => 1,
                'category' => 'on_hand_beginning',
                'wine_type' => 'all',
                'description' => 'On hand beginning of period',
                'gallons' => 5000,
                'source_event_ids' => [],
            ]);

            TTBReportLine::create([
                'ttb_report_id' => $report->id,
                'part' => 'I',
                'section' => 'A',
                'line_number' => 2,
                'category' => 'wine_produced',
                'wine_type' => 'not_over_16',
                'description' => 'Wine produced by fermentation — Not Over 16%',
                'gallons' => 2000,
                'source_event_ids' => ['event-uuid-1', 'event-uuid-2'],
            ]);

            expect($report->lines()->count())->toBe(2);
            expect($report->lines()->where('section', 'A')->where('line_number', 2)->first()->wine_type)->toBe('not_over_16');
        });
    });

    it('prevents regeneration of reviewed reports', function () {
        [$tenant, $token, $userId] = seedAndGetTTBModelTenant('ttb-model-4');

        $tenant->run(function () use ($userId) {
            $report = TTBReport::create([
                'report_period_month' => 3,
                'report_period_year' => 2025,
                'status' => 'reviewed',
                'generated_at' => now(),
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
            ]);

            expect($report->canRegenerate())->toBeFalse();
            expect($report->canReview())->toBeFalse();
        });
    });

    it('cascades line deletion when report is deleted', function () {
        [$tenant, $token, $userId] = seedAndGetTTBModelTenant('ttb-model-5');

        $tenant->run(function () {
            $report = TTBReport::create([
                'report_period_month' => 4,
                'report_period_year' => 2025,
                'status' => 'draft',
                'generated_at' => now(),
            ]);

            TTBReportLine::create([
                'ttb_report_id' => $report->id,
                'part' => 'I',
                'section' => 'A',
                'line_number' => 1,
                'category' => 'on_hand_beginning',
                'wine_type' => 'all',
                'description' => 'On hand beginning of period',
                'gallons' => 1000,
                'source_event_ids' => [],
            ]);

            $reportId = $report->id;
            $report->delete();

            expect(TTBReportLine::where('ttb_report_id', $reportId)->count())->toBe(0);
        });
    });
})->group('compliance');

// ─── Tier 1: Report Generation Job ──────────────────────────────────

describe('monthly TTB report generation job', function () {
    it('generates a draft report with line items', function () {
        [$tenant, $token, $userId] = seedAndGetTTBModelTenant('ttb-job-1');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $lot = Lot::create([
                'name' => 'Job Test Lot',
                'variety' => 'Cabernet Sauvignon',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 1000,
            ]);

            LabAnalysis::create([
                'lot_id' => $lot->id,
                'test_date' => '2025-01-10',
                'test_type' => 'alcohol',
                'value' => '13.5',
                'unit' => '%v/v',
                'source' => 'manual',
                'performed_by' => $userId,
            ]);

            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'lot_created',
                payload: ['name' => 'Job Test Lot', 'initial_volume' => 1000.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 10),
            );
        });

        // Run the job (dispatched synchronously for testing)
        $job = new GenerateMonthlyTTBReportJob(
            tenantId: $tenant->id,
            month: 1,
            year: 2025,
            openingBulkInventory: 3000.0,
        );
        $job->handle();

        $tenant->run(function () {
            $report = TTBReport::where('report_period_month', 1)
                ->where('report_period_year', 2025)
                ->first();

            expect($report)->not->toBeNull();
            expect($report->status)->toBe('draft');
            expect($report->data)->not->toBeNull();
            expect($report->lines()->count())->toBeGreaterThan(0);

            // Verify Section A and B lines exist
            expect($report->lines()->where('section', 'A')->count())->toBeGreaterThan(0);

            // Verify event was logged
            $event = Event::where('operation_type', 'ttb_report_generated')
                ->where('entity_id', $report->id)
                ->first();
            expect($event)->not->toBeNull();
            expect($event->event_source)->toBe('compliance');
        });
    });

    it('replaces existing draft when regenerated', function () {
        [$tenant, $token, $userId] = seedAndGetTTBModelTenant('ttb-job-2');

        $tenant->run(function () use ($userId) {
            $logger = app(EventLogger::class);

            $lot = Lot::create([
                'name' => 'Regen Lot',
                'variety' => 'Merlot',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 500,
            ]);

            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'lot_created',
                payload: ['name' => 'Regen Lot', 'initial_volume' => 500.0],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 2, 5),
            );
        });

        // Generate first time
        $job1 = new GenerateMonthlyTTBReportJob(
            tenantId: $tenant->id,
            month: 2,
            year: 2025,
            openingBulkInventory: 1000.0,
        );
        $job1->handle();

        // Generate second time (should replace)
        $job2 = new GenerateMonthlyTTBReportJob(
            tenantId: $tenant->id,
            month: 2,
            year: 2025,
            openingBulkInventory: 1000.0,
        );
        $job2->handle();

        $tenant->run(function () {
            // Should only have one report for Feb 2025
            $reportCount = TTBReport::where('report_period_month', 2)
                ->where('report_period_year', 2025)
                ->count();

            expect($reportCount)->toBe(1);
        });
    });

    it('skips regeneration of reviewed reports', function () {
        [$tenant, $token, $userId] = seedAndGetTTBModelTenant('ttb-job-3');

        $tenant->run(function () use ($userId) {
            TTBReport::create([
                'report_period_month' => 3,
                'report_period_year' => 2025,
                'status' => 'reviewed',
                'generated_at' => now(),
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'data' => ['section_a' => ['summary' => ['closing_inventory' => 2000]], 'section_b' => ['summary' => ['closing_inventory' => 0]]],
            ]);
        });

        $job = new GenerateMonthlyTTBReportJob(
            tenantId: $tenant->id,
            month: 3,
            year: 2025,
        );
        $job->handle();

        $tenant->run(function () {
            $report = TTBReport::where('report_period_month', 3)
                ->where('report_period_year', 2025)
                ->first();

            // Should still be reviewed, not replaced
            expect($report->status)->toBe('reviewed');
        });
    });
})->group('compliance');
