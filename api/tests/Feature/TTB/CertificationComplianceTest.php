<?php

declare(strict_types=1);

use App\Models\Lot;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WineryProfile;
use App\Services\CertificationComplianceService;
use App\Services\EventLogger;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

function seedAndGetCertTenant(string $slug = 'cert-test'): array
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

// ─── Certification Compliance ────────────────────────────────────────

describe('certification compliance checks', function () {
    it('passes when winery has no certifications', function () {
        [$tenant, $userId] = seedAndGetCertTenant('cert-none-1');

        $tenant->run(function () {
            // No certification_types set on WineryProfile
            $service = app(CertificationComplianceService::class);
            $result = $service->checkAddition('Mega Purple');

            expect($result['compliant'])->toBeTrue();
            expect($result['violations'])->toBeEmpty();
        });
    });

    it('flags prohibited input for USDA Organic', function () {
        [$tenant, $userId] = seedAndGetCertTenant('cert-organic-1');

        $tenant->run(function () {
            $profile = WineryProfile::first();
            $profile->update(['certification_types' => ['usda_organic']]);

            $service = app(CertificationComplianceService::class);
            $result = $service->checkAddition('mega_purple');

            expect($result['compliant'])->toBeFalse();
            expect($result['violations'])->toHaveCount(1);
            expect($result['violations'][0]['certification'])->toBe('usda_organic');
            expect($result['violations'][0]['reason'])->toContain('not approved');
        });
    });

    it('allows approved inputs for USDA Organic', function () {
        [$tenant, $userId] = seedAndGetCertTenant('cert-organic-2');

        $tenant->run(function () {
            $profile = WineryProfile::first();
            $profile->update(['certification_types' => ['usda_organic']]);

            $service = app(CertificationComplianceService::class);

            // SO2 is generally allowed in organic winemaking (within limits)
            $result = $service->checkAddition('potassium_metabisulfite');
            expect($result['compliant'])->toBeTrue();
        });
    });

    it('flags violations for multiple certifications', function () {
        [$tenant, $userId] = seedAndGetCertTenant('cert-multi-1');

        $tenant->run(function () {
            $profile = WineryProfile::first();
            $profile->update(['certification_types' => ['usda_organic', 'demeter_biodynamic']]);

            $service = app(CertificationComplianceService::class);
            $result = $service->checkAddition('synthetic_yeast');

            expect($result['compliant'])->toBeFalse();
            // Should be flagged by both certifications
            expect(count($result['violations']))->toBeGreaterThanOrEqual(2);
        });
    });

    it('generates lot audit trail with flagged additions', function () {
        [$tenant, $userId] = seedAndGetCertTenant('cert-audit-1');

        $tenant->run(function () use ($userId) {
            $profile = WineryProfile::first();
            $profile->update(['certification_types' => ['usda_organic']]);

            $logger = app(EventLogger::class);
            $lot = Lot::create([
                'name' => 'Organic Lot',
                'variety' => 'Pinot Noir',
                'vintage' => 2024,
                'source_type' => 'estate',
                'volume_gallons' => 500,
            ]);

            // Approved addition
            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'addition_created',
                payload: ['product_name' => 'potassium_metabisulfite', 'amount' => '30 ppm'],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 10),
            );

            // Non-approved addition
            $logger->log(
                entityType: 'lot',
                entityId: $lot->id,
                operationType: 'addition_created',
                payload: ['product_name' => 'mega_purple', 'amount' => '0.5 gal'],
                performedBy: $userId,
                performedAt: Carbon::create(2025, 1, 15),
            );

            $service = app(CertificationComplianceService::class);
            $trail = $service->getLotAuditTrail($lot->id);

            expect($trail)->toHaveCount(2);
            expect($trail[0]['compliant'])->toBeTrue(); // metabisulfite
            expect($trail[1]['compliant'])->toBeFalse(); // mega_purple
        });
    });

    it('returns active certifications', function () {
        [$tenant, $userId] = seedAndGetCertTenant('cert-list-1');

        $tenant->run(function () {
            $profile = WineryProfile::first();
            $profile->update(['certification_types' => ['usda_organic', 'sip_certified']]);

            $service = app(CertificationComplianceService::class);
            $certs = $service->getActiveCertifications();

            expect($certs)->toHaveCount(2);
            expect($certs[0]['label'])->toBe('USDA Organic');
            expect($certs[1]['label'])->toBe('SIP Certified');
        });
    });
})->group('compliance');
