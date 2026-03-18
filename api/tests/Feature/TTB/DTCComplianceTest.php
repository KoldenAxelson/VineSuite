<?php

declare(strict_types=1);

use App\Models\CustomerDTCShipment;
use App\Models\DTCComplianceRule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DTCComplianceService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

function seedAndGetDTCTenant(string $slug = 'dtc-test'): array
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
        app(PermissionRegistrar::class)->forgetCachedPermissions();

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

// ─── DTC Compliance Service ──────────────────────────────────────────

describe('DTC compliance checks', function () {
    it('allows shipment to a DTC-permitted state', function () {
        [$tenant, $userId] = seedAndGetDTCTenant('dtc-allow-1');

        $tenant->run(function () {
            DTCComplianceRule::create([
                'state_code' => 'CA',
                'state_name' => 'California',
                'allows_dtc_shipping' => true,
                'license_required' => true,
                'license_type_required' => 'wine_direct_shipper',
            ]);

            $service = app(DTCComplianceService::class);
            $result = $service->checkEligibility('customer-1', 'CA', casesToShip: 2);

            expect($result['allowed'])->toBeTrue();
        });
    });

    it('blocks shipment to a prohibited state', function () {
        [$tenant, $userId] = seedAndGetDTCTenant('dtc-block-1');

        $tenant->run(function () {
            DTCComplianceRule::create([
                'state_code' => 'UT',
                'state_name' => 'Utah',
                'allows_dtc_shipping' => false,
            ]);

            $service = app(DTCComplianceService::class);
            $result = $service->checkEligibility('customer-1', 'UT', casesToShip: 1);

            expect($result['allowed'])->toBeFalse();
            expect($result['reason'])->toContain('does not allow');
        });
    });

    it('blocks shipment exceeding annual case limit', function () {
        [$tenant, $userId] = seedAndGetDTCTenant('dtc-limit-1');

        $tenant->run(function () {
            DTCComplianceRule::create([
                'state_code' => 'GA',
                'state_name' => 'Georgia',
                'allows_dtc_shipping' => true,
                'annual_case_limit' => 12,
                'license_required' => true,
            ]);

            // Record 10 cases already shipped this year
            CustomerDTCShipment::create([
                'customer_id' => 'customer-1',
                'state_code' => 'GA',
                'cases_shipped' => 10,
                'gallons_shipped' => 19.8,
                'shipped_at' => now()->subMonth(),
            ]);

            $service = app(DTCComplianceService::class);

            // 3 more cases would exceed 12 limit
            $result = $service->checkEligibility('customer-1', 'GA', casesToShip: 3);
            expect($result['allowed'])->toBeFalse();
            expect($result['reason'])->toContain('exceed annual case limit');

            // 2 more cases should be fine
            $result2 = $service->checkEligibility('customer-1', 'GA', casesToShip: 2);
            expect($result2['allowed'])->toBeTrue();
        });
    });

    it('returns not found for unknown state codes', function () {
        [$tenant, $userId] = seedAndGetDTCTenant('dtc-unknown-1');

        $tenant->run(function () {
            $service = app(DTCComplianceService::class);
            $result = $service->checkEligibility('customer-1', 'XX', casesToShip: 1);

            expect($result['allowed'])->toBeFalse();
            expect($result['reason'])->toContain('No DTC compliance rule');
        });
    });

    it('records and tracks shipments', function () {
        [$tenant, $userId] = seedAndGetDTCTenant('dtc-track-1');

        $tenant->run(function () {
            $service = app(DTCComplianceService::class);

            $shipment = $service->recordShipment(
                customerId: 'customer-1',
                stateCode: 'CA',
                cases: 2,
                gallons: 3.96,
                orderId: 'order-123',
            );

            expect($shipment)->toBeInstanceOf(CustomerDTCShipment::class);
            expect($shipment->customer_id)->toBe('customer-1');
            expect($shipment->state_code)->toBe('CA');
            expect((float) $shipment->cases_shipped)->toBe(2.0);
        });
    });

    it('computes annual summary per customer', function () {
        [$tenant, $userId] = seedAndGetDTCTenant('dtc-summary-1');

        $tenant->run(function () {
            DTCComplianceRule::create([
                'state_code' => 'CA',
                'state_name' => 'California',
                'allows_dtc_shipping' => true,
            ]);

            DTCComplianceRule::create([
                'state_code' => 'OR',
                'state_name' => 'Oregon',
                'allows_dtc_shipping' => true,
            ]);

            $service = app(DTCComplianceService::class);
            $service->recordShipment('customer-1', 'CA', 3, 5.94);
            $service->recordShipment('customer-1', 'CA', 2, 3.96);
            $service->recordShipment('customer-1', 'OR', 1, 1.98);

            $summary = $service->getCustomerAnnualSummary('customer-1');

            expect($summary)->toHaveKeys(['CA', 'OR']);
            expect($summary['CA']['cases'])->toBe(5.0);
            expect($summary['OR']['cases'])->toBe(1.0);
        });
    });
})->group('compliance');
