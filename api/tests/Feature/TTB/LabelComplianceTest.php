<?php

declare(strict_types=1);

use App\Models\BlendTrial;
use App\Models\BlendTrialComponent;
use App\Models\LabelComplianceCheck;
use App\Models\LabelProfile;
use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TTB\LabelComplianceService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

function seedAndGetLabelTenant(string $slug = 'label-test'): array
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
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => 'SecurePass123!',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $user->assignRole('admin');
        $userId = $user->id;
    });

    return [$tenant, $userId];
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

/**
 * Helper: create a blend trial with component lots for testing.
 *
 * @param  array<int, array{variety: string, source_ava: string|null, vintage: int, volume_gallons: float, percentage: float}>  $components
 */
function createBlendWithComponents(string $userId, array $components, string $name = 'Test Blend'): BlendTrial
{
    $trial = BlendTrial::create([
        'name' => $name,
        'status' => 'draft',
        'version' => 1,
        'created_by' => $userId,
    ]);

    $totalVolume = array_sum(array_column($components, 'volume_gallons'));
    $varietyComposition = [];

    foreach ($components as $i => $comp) {
        $lot = Lot::create([
            'name' => "Component Lot {$i}",
            'variety' => $comp['variety'],
            'vintage' => $comp['vintage'],
            'source_type' => 'estate',
            'source_ava' => $comp['source_ava'] ?? null,
            'volume_gallons' => $comp['volume_gallons'] * 2, // source has double what we use
            'status' => 'in_progress',
        ]);

        BlendTrialComponent::create([
            'blend_trial_id' => $trial->id,
            'source_lot_id' => $lot->id,
            'percentage' => $comp['percentage'],
            'volume_gallons' => $comp['volume_gallons'],
        ]);

        $variety = $comp['variety'];
        $varietyComposition[$variety] = ($varietyComposition[$variety] ?? 0) + $comp['percentage'];
    }

    $trial->update([
        'total_volume_gallons' => $totalVolume,
        'variety_composition' => $varietyComposition,
    ]);

    return $trial;
}

// ─── Varietal 75% Rule ──────────────────────────────────────────────────

describe('varietal 75% rule', function () {
    it('passes when varietal exceeds 75%', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-var-pass-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 80.0, 'percentage' => 80.0],
                ['variety' => 'Grenache', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 20.0, 'percentage' => 20.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            $varietalCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_VARIETAL_75);

            expect($varietalCheck->passes)->toBeTrue();
            expect((float) $varietalCheck->actual_percentage)->toBe(80.0);
        });
    });

    it('fails when varietal is below 75%', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-var-fail-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 70.0, 'percentage' => 70.0],
                ['variety' => 'Grenache', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 30.0, 'percentage' => 30.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            expect($result['status'])->toBe('failing');

            $varietalCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_VARIETAL_75);

            expect($varietalCheck->passes)->toBeFalse();
            expect((float) $varietalCheck->actual_percentage)->toBe(70.0);
            expect($varietalCheck->details['remediation'])->toContain('Syrah');
        });
    });

    it('passes at exactly 75%', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-var-exact-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Cabernet Sauvignon', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 75.0, 'percentage' => 75.0],
                ['variety' => 'Merlot', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 25.0, 'percentage' => 25.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Cabernet Sauvignon',
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            $varietalCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_VARIETAL_75);

            expect($varietalCheck->passes)->toBeTrue();
            expect((float) $varietalCheck->actual_percentage)->toBe(75.0);
        });
    });

    it('provides remediation with gallons needed', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-var-remed-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 60.0, 'percentage' => 60.0],
                ['variety' => 'Grenache', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 40.0, 'percentage' => 40.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            $varietalCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_VARIETAL_75);

            expect($varietalCheck->passes)->toBeFalse();
            expect($varietalCheck->details['remediation'])->toContain('gallons');
            expect($varietalCheck->details['remediation'])->toContain('Syrah');
        });
    });
});

// ─── AVA 85% Rule ───────────────────────────────────────────────────────

describe('AVA 85% rule', function () {
    it('passes when AVA exceeds 85%', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-ava-pass-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Adelaida District', 'vintage' => 2024, 'volume_gallons' => 90.0, 'percentage' => 90.0],
                ['variety' => 'Grenache', 'source_ava' => 'Templeton Gap District', 'vintage' => 2024, 'volume_gallons' => 10.0, 'percentage' => 10.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'sub_ava_claim' => 'Adelaida District',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            $avaCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_AVA_85);

            expect($avaCheck->passes)->toBeTrue();
            expect((float) $avaCheck->actual_percentage)->toBe(90.0);
        });
    });

    it('fails when AVA is below 85%', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-ava-fail-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Adelaida District', 'vintage' => 2024, 'volume_gallons' => 80.0, 'percentage' => 80.0],
                ['variety' => 'Grenache', 'source_ava' => 'Templeton Gap District', 'vintage' => 2024, 'volume_gallons' => 20.0, 'percentage' => 20.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'sub_ava_claim' => 'Adelaida District',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            $avaCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_AVA_85);

            expect($avaCheck->passes)->toBeFalse();
            expect((float) $avaCheck->actual_percentage)->toBe(80.0);
            expect($avaCheck->details['remediation'])->toContain('Adelaida District');
        });
    });

    it('checks parent AVA when no sub-AVA is claimed', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-ava-parent-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 90.0, 'percentage' => 90.0],
                ['variety' => 'Syrah', 'source_ava' => 'Santa Barbara County', 'vintage' => 2024, 'volume_gallons' => 10.0, 'percentage' => 10.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            $avaCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_AVA_85);

            expect($avaCheck->passes)->toBeTrue();
            expect((float) $avaCheck->actual_percentage)->toBe(90.0);
        });
    });
});

// ─── Vintage 95% Rule ──────────────────────────────────────────────────

describe('vintage 95% rule', function () {
    it('passes when vintage exceeds 95%', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-vin-pass-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 97.0, 'percentage' => 97.0],
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2023, 'volume_gallons' => 3.0, 'percentage' => 3.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            $vintageCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_VINTAGE_95);

            expect($vintageCheck->passes)->toBeTrue();
            expect((float) $vintageCheck->actual_percentage)->toBe(97.0);
        });
    });

    it('fails when vintage is below 95%', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-vin-fail-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 90.0, 'percentage' => 90.0],
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2023, 'volume_gallons' => 10.0, 'percentage' => 10.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            $vintageCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_VINTAGE_95);

            expect($vintageCheck->passes)->toBeFalse();
            expect((float) $vintageCheck->actual_percentage)->toBe(90.0);
            expect($vintageCheck->details['remediation'])->toContain('2024');
        });
    });

    it('skips vintage check for non-vintage wine', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-vin-nv-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 50.0, 'percentage' => 50.0],
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2023, 'volume_gallons' => 50.0, 'percentage' => 50.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'vintage_claim' => null, // NV wine
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            $vintageCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_VINTAGE_95);

            expect($vintageCheck)->toBeNull();
        });
    });
});

// ─── Conjunctive Labeling ──────────────────────────────────────────────

describe('California conjunctive labeling', function () {
    it('passes when parent AVA is declared with sub-AVA', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-conj-pass-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Adelaida District', 'vintage' => 2024, 'volume_gallons' => 100.0, 'percentage' => 100.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'sub_ava_claim' => 'Adelaida District',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            $conjCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_CONJUNCTIVE_LABEL);

            expect($conjCheck->passes)->toBeTrue();
        });
    });

    it('fails when sub-AVA is declared without parent AVA', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-conj-fail-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Adelaida District', 'vintage' => 2024, 'volume_gallons' => 100.0, 'percentage' => 100.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => null, // Missing parent AVA!
                'sub_ava_claim' => 'Adelaida District',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            expect($result['status'])->toBe('failing');

            $conjCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_CONJUNCTIVE_LABEL);

            expect($conjCheck->passes)->toBeFalse();
            expect($conjCheck->details['remediation'])->toContain('Paso Robles');
        });
    });

    it('fails when wrong parent AVA is declared', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-conj-wrong-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Adelaida District', 'vintage' => 2024, 'volume_gallons' => 100.0, 'percentage' => 100.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Santa Barbara County', // Wrong parent
                'sub_ava_claim' => 'Adelaida District',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            $conjCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_CONJUNCTIVE_LABEL);

            expect($conjCheck->passes)->toBeFalse();
        });
    });

    it('skips conjunctive check when no sub-AVA claimed', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-conj-skip-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 100.0, 'percentage' => 100.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            $conjCheck = collect($result['checks'])
                ->firstWhere('rule_type', LabelComplianceCheck::RULE_CONJUNCTIVE_LABEL);

            expect($conjCheck)->toBeNull();
        });
    });
});

// ─── Full Integration ──────────────────────────────────────────────────

describe('full integration', function () {
    it('evaluates all four rules together — all passing', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-full-pass-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Adelaida District', 'vintage' => 2024, 'volume_gallons' => 96.0, 'percentage' => 96.0],
                ['variety' => 'Grenache', 'source_ava' => 'Adelaida District', 'vintage' => 2024, 'volume_gallons' => 4.0, 'percentage' => 4.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'sub_ava_claim' => 'Adelaida District',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            expect($result['status'])->toBe('passing');
            expect($result['checks'])->toHaveCount(4);

            // All four checks should pass
            foreach ($result['checks'] as $check) {
                expect($check->passes)->toBeTrue();
            }
        });
    });

    it('evaluates all four rules — varietal fails, rest pass', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-full-mix-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Adelaida District', 'vintage' => 2024, 'volume_gallons' => 70.0, 'percentage' => 70.0],
                ['variety' => 'Grenache', 'source_ava' => 'Adelaida District', 'vintage' => 2024, 'volume_gallons' => 30.0, 'percentage' => 30.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'sub_ava_claim' => 'Adelaida District',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            expect($result['status'])->toBe('failing');

            $varietalCheck = collect($result['checks'])->firstWhere('rule_type', LabelComplianceCheck::RULE_VARIETAL_75);
            $avaCheck = collect($result['checks'])->firstWhere('rule_type', LabelComplianceCheck::RULE_AVA_85);
            $vintageCheck = collect($result['checks'])->firstWhere('rule_type', LabelComplianceCheck::RULE_VINTAGE_95);
            $conjCheck = collect($result['checks'])->firstWhere('rule_type', LabelComplianceCheck::RULE_CONJUNCTIVE_LABEL);

            expect($varietalCheck->passes)->toBeFalse();
            expect($avaCheck->passes)->toBeTrue();
            expect($vintageCheck->passes)->toBeTrue();
            expect($conjCheck->passes)->toBeTrue();
        });
    });

    it('handles classic Paso Robles GSM blend correctly', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-gsm-1');

        $tenant->run(function () use ($userId) {
            // Classic GSM (Grenache-Syrah-Mourvèdre) — no single varietal ≥ 75%
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Grenache', 'source_ava' => 'Adelaida District', 'vintage' => 2024, 'volume_gallons' => 50.0, 'percentage' => 50.0],
                ['variety' => 'Syrah', 'source_ava' => 'Adelaida District', 'vintage' => 2024, 'volume_gallons' => 30.0, 'percentage' => 30.0],
                ['variety' => 'Mourvèdre', 'source_ava' => 'Templeton Gap District', 'vintage' => 2024, 'volume_gallons' => 20.0, 'percentage' => 20.0],
            ]);

            // Label does NOT claim a varietal (it's a "Red Blend"), but does claim AVA/vintage
            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => null, // No varietal claim for a blend
                'ava_claim' => 'Paso Robles',
                'sub_ava_claim' => 'Adelaida District',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            // No varietal check (none claimed)
            $varietalCheck = collect($result['checks'])->firstWhere('rule_type', LabelComplianceCheck::RULE_VARIETAL_75);
            expect($varietalCheck)->toBeNull();

            // AVA check: 80% from Adelaida District — fails the 85% threshold
            $avaCheck = collect($result['checks'])->firstWhere('rule_type', LabelComplianceCheck::RULE_AVA_85);
            expect($avaCheck->passes)->toBeFalse();
            expect((float) $avaCheck->actual_percentage)->toBe(80.0);

            // Vintage check: 100% 2024 — passes
            $vintageCheck = collect($result['checks'])->firstWhere('rule_type', LabelComplianceCheck::RULE_VINTAGE_95);
            expect($vintageCheck->passes)->toBeTrue();

            // Overall: failing (AVA doesn't meet threshold)
            expect($result['status'])->toBe('failing');
        });
    });
});

// ─── Locking ────────────────────────────────────────────────────────────

describe('profile locking', function () {
    it('locks profile with compliance snapshot', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-lock-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 100.0, 'percentage' => 100.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $lockedProfile = $service->lock($profile);

            expect($lockedProfile->isLocked())->toBeTrue();
            expect($lockedProfile->compliance_snapshot)->not->toBeNull();
            expect($lockedProfile->compliance_snapshot['status'])->toBe('passing');
            expect($lockedProfile->compliance_snapshot['checks'])->toHaveCount(3); // varietal, ava, vintage (no sub-AVA → no conjunctive)
        });
    });

    it('returns cached result for locked profile', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-lock-cached-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 100.0, 'percentage' => 100.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'ava_claim' => 'Paso Robles',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);
            $service->lock($profile);

            // Re-evaluate — should return existing checks without re-calculating
            $result = $service->evaluate($profile->fresh());
            expect($result['status'])->toBe('passing');
        });
    });
});

// ─── Edge Cases ─────────────────────────────────────────────────────────

describe('edge cases', function () {
    it('handles profile with no claims gracefully', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-edge-noclaim-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 100.0, 'percentage' => 100.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                // No claims at all
            ]);

            $service = app(LabelComplianceService::class);
            $result = $service->evaluate($profile);

            expect($result['status'])->toBe('unchecked');
            expect($result['checks'])->toHaveCount(0);
        });
    });

    it('re-evaluates and replaces previous checks', function () {
        [$tenant, $userId] = seedAndGetLabelTenant('label-edge-reeval-1');

        $tenant->run(function () use ($userId) {
            $trial = createBlendWithComponents($userId, [
                ['variety' => 'Syrah', 'source_ava' => 'Paso Robles', 'vintage' => 2024, 'volume_gallons' => 100.0, 'percentage' => 100.0],
            ]);

            $profile = LabelProfile::create([
                'blend_trial_id' => $trial->id,
                'varietal_claim' => 'Syrah',
                'vintage_claim' => 2024,
            ]);

            $service = app(LabelComplianceService::class);

            // First evaluation
            $service->evaluate($profile);
            expect(LabelComplianceCheck::where('label_profile_id', $profile->id)->count())->toBe(2);

            // Second evaluation — should replace, not duplicate
            $service->evaluate($profile);
            expect(LabelComplianceCheck::where('label_profile_id', $profile->id)->count())->toBe(2);
        });
    });
})->group('compliance', 'label-compliance');
