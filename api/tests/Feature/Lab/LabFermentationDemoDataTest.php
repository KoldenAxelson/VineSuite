<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\FermentationEntry;
use App\Models\FermentationRound;
use App\Models\LabAnalysis;
use App\Models\SensoryNote;
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

// ─── Tier 1: Demo Data Existence ────────────────────────────────

it('seeds lab analysis records for demo lots', function () {
    test()->seed(DemoWinerySeeder::class);

    $tenant = Tenant::where('slug', 'paso-robles-cellars')->first();
    expect($tenant)->not->toBeNull();

    $tenant->run(function () {
        $labCount = LabAnalysis::count();
        expect($labCount)->toBeGreaterThan(30);

        // Check variety of test types
        $testTypes = LabAnalysis::distinct()->pluck('test_type')->toArray();
        expect($testTypes)->toContain('pH');
        expect($testTypes)->toContain('TA');
        expect($testTypes)->toContain('VA');
        expect($testTypes)->toContain('free_SO2');
        expect($testTypes)->toContain('alcohol');

        // Check multiple sources
        $sources = LabAnalysis::distinct()->pluck('source')->toArray();
        expect($sources)->toContain('manual');
        expect($sources)->toContain('ets_labs');
    });
});

it('seeds fermentation rounds with realistic Brix decrease curves', function () {
    test()->seed(DemoWinerySeeder::class);

    $tenant = Tenant::where('slug', 'paso-robles-cellars')->first();

    $tenant->run(function () {
        // At least 2 active fermentation rounds with entries
        $activeRounds = FermentationRound::where('status', 'active')->withCount('entries')->get();
        expect($activeRounds->count())->toBeGreaterThanOrEqual(2);

        // Each active round should have entries
        foreach ($activeRounds as $round) {
            expect($round->entries_count)->toBeGreaterThan(0);
        }

        // Check for both primary and malolactic fermentation types
        $types = FermentationRound::distinct()->pluck('fermentation_type')->toArray();
        expect($types)->toContain('primary');
        expect($types)->toContain('malolactic');

        // Verify a Brix curve decreases over time (primary fermentation)
        $primaryRound = FermentationRound::where('fermentation_type', 'primary')
            ->where('status', 'active')
            ->first();
        $entries = FermentationEntry::where('fermentation_round_id', $primaryRound->id)
            ->orderBy('entry_date')
            ->get();

        $firstBrix = (float) $entries->first()->brix_or_density;
        $lastBrix = (float) $entries->last()->brix_or_density;
        expect($firstBrix)->toBeGreaterThan($lastBrix);
        expect($firstBrix)->toBeGreaterThan(20.0);
        expect($lastBrix)->toBeLessThan(5.0);
    });
});

it('seeds a completed fermentation round with both primary and ML', function () {
    test()->seed(DemoWinerySeeder::class);

    $tenant = Tenant::where('slug', 'paso-robles-cellars')->first();

    $tenant->run(function () {
        $completedRounds = FermentationRound::where('status', 'completed')->get();
        expect($completedRounds->count())->toBeGreaterThanOrEqual(2);

        // Should have at least one completed ML round with confirmation date
        $mlRound = FermentationRound::where('fermentation_type', 'malolactic')
            ->where('status', 'completed')
            ->first();
        expect($mlRound)->not->toBeNull();
        expect($mlRound->confirmation_date)->not->toBeNull();
    });
});

it('seeds a stuck fermentation round', function () {
    test()->seed(DemoWinerySeeder::class);

    $tenant = Tenant::where('slug', 'paso-robles-cellars')->first();

    $tenant->run(function () {
        $stuckRound = FermentationRound::where('status', 'stuck')->first();
        expect($stuckRound)->not->toBeNull();

        // Stuck round should have entries showing Brix plateau
        $entries = FermentationEntry::where('fermentation_round_id', $stuckRound->id)
            ->orderBy('entry_date')
            ->get();
        expect($entries->count())->toBeGreaterThan(3);

        // Last few entries should show minimal Brix change (stuck)
        $lastTwo = $entries->slice(-2)->values();
        $brixDiff = abs((float) $lastTwo[0]->brix_or_density - (float) $lastTwo[1]->brix_or_density);
        expect($brixDiff)->toBeLessThan(1.0);
    });
});

it('seeds sensory tasting notes for demo lots', function () {
    test()->seed(DemoWinerySeeder::class);

    $tenant = Tenant::where('slug', 'paso-robles-cellars')->first();

    $tenant->run(function () {
        $noteCount = SensoryNote::count();
        expect($noteCount)->toBeGreaterThanOrEqual(8);

        // Both rating scales should be used
        $scales = SensoryNote::distinct()->pluck('rating_scale')->toArray();
        expect($scales)->toContain('five_point');
        expect($scales)->toContain('hundred_point');

        // At least one note with no rating (early assessment)
        $noRating = SensoryNote::whereNull('rating')->count();
        expect($noRating)->toBeGreaterThanOrEqual(1);

        // Multiple tastings for same lot (development tracking)
        $lotCounts = SensoryNote::select('lot_id')
            ->selectRaw('count(*) as cnt')
            ->groupBy('lot_id')
            ->havingRaw('count(*) > 1')
            ->get();
        expect($lotCounts->count())->toBeGreaterThanOrEqual(1);
    });
});

// ─── Tier 1: Event Logging ──────────────────────────────────────

it('writes lab_analysis_entered events for seeded lab data', function () {
    test()->seed(DemoWinerySeeder::class);

    $tenant = Tenant::where('slug', 'paso-robles-cellars')->first();

    $tenant->run(function () {
        $labEvents = Event::where('operation_type', 'lab_analysis_entered')->count();
        expect($labEvents)->toBeGreaterThan(30);

        // Verify event payload structure
        $event = Event::where('operation_type', 'lab_analysis_entered')->first();
        expect($event->payload)->toHaveKey('lot_name');
        expect($event->payload)->toHaveKey('lot_variety');
        expect($event->payload)->toHaveKey('test_type');
        expect($event->payload)->toHaveKey('value');
    });
});

it('writes fermentation events for seeded fermentation data', function () {
    test()->seed(DemoWinerySeeder::class);

    $tenant = Tenant::where('slug', 'paso-robles-cellars')->first();

    $tenant->run(function () {
        $roundEvents = Event::where('operation_type', 'fermentation_round_created')->count();
        expect($roundEvents)->toBeGreaterThanOrEqual(8);

        // Verify event payload structure
        $event = Event::where('operation_type', 'fermentation_round_created')->first();
        expect($event->payload)->toHaveKey('lot_name');
        expect($event->payload)->toHaveKey('fermentation_type');
    });
});

it('writes sensory_note_recorded events for seeded tasting notes', function () {
    test()->seed(DemoWinerySeeder::class);

    $tenant = Tenant::where('slug', 'paso-robles-cellars')->first();

    $tenant->run(function () {
        $sensoryEvents = Event::where('operation_type', 'sensory_note_recorded')->count();
        expect($sensoryEvents)->toBeGreaterThanOrEqual(8);

        $event = Event::where('operation_type', 'sensory_note_recorded')->first();
        expect($event->payload)->toHaveKey('lot_name');
        expect($event->payload)->toHaveKey('taster_name');
        expect($event->payload)->toHaveKey('rating_scale');
    });
});

// ─── Tier 2: Data Realism ──────────────────────────────────────

it('seeds realistic temperature ranges for red vs white fermentations', function () {
    test()->seed(DemoWinerySeeder::class);

    $tenant = Tenant::where('slug', 'paso-robles-cellars')->first();

    $tenant->run(function () {
        // Find a Chardonnay (white) fermentation
        $chardRound = FermentationRound::whereHas('lot', function ($q) {
            $q->where('variety', 'Chardonnay');
        })->where('fermentation_type', 'primary')->first();

        if ($chardRound) {
            $avgTemp = (float) FermentationEntry::where('fermentation_round_id', $chardRound->id)
                ->avg('temperature');
            // White fermentation: 50-65°F
            expect($avgTemp)->toBeLessThan(65.0);
            expect($avgTemp)->toBeGreaterThan(50.0);
        }

        // Find a Cabernet (red) fermentation
        $cabRound = FermentationRound::whereHas('lot', function ($q) {
            $q->where('variety', 'Cabernet Sauvignon');
        })->where('fermentation_type', 'primary')->first();

        if ($cabRound) {
            $avgTemp = (float) FermentationEntry::where('fermentation_round_id', $cabRound->id)
                ->avg('temperature');
            // Red fermentation: 75-90°F
            expect($avgTemp)->toBeGreaterThan(74.0);
            expect($avgTemp)->toBeLessThan(92.0);
        }
    });
});
