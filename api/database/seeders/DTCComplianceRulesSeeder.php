<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DTCComplianceRule;
use Illuminate\Database\Seeder;

/**
 * Seeds DTC shipping compliance rules for all 50 states + DC.
 *
 * Data represents a representative snapshot of DTC shipping rules.
 * Rules change frequently — last_verified_at should be updated
 * when rules are manually reviewed.
 *
 * Key categories:
 *   - Full DTC: allows shipping, may have limits
 *   - Limited DTC: allows shipping with strict volume caps
 *   - Prohibited: does not allow DTC wine shipments
 *   - License required: DTC allowed but winery must hold state-specific license
 */
class DTCComplianceRulesSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Seeding DTC compliance rules for 50 states + DC...');

        $rules = $this->getRules();

        foreach ($rules as $rule) {
            DTCComplianceRule::updateOrCreate(
                ['state_code' => $rule['state_code']],
                array_merge($rule, ['last_verified_at' => now()]),
            );
        }

        $this->command?->info('Seeded '.count($rules).' DTC compliance rules.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRules(): array
    {
        return [
            ['state_code' => 'AL', 'state_name' => 'Alabama', 'allows_dtc_shipping' => false, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => false, 'license_type_required' => null, 'notes' => 'DTC wine shipping prohibited.'],
            ['state_code' => 'AK', 'state_name' => 'Alaska', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'Permit required. No volume limits.'],
            ['state_code' => 'AZ', 'state_name' => 'Arizona', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required. No volume limits.'],
            ['state_code' => 'AR', 'state_name' => 'Arkansas', 'allows_dtc_shipping' => false, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => false, 'license_type_required' => null, 'notes' => 'DTC wine shipping prohibited.'],
            ['state_code' => 'CA', 'state_name' => 'California', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'wine_direct_shipper', 'notes' => 'License required. No volume limits.'],
            ['state_code' => 'CO', 'state_name' => 'Colorado', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required. No volume limits.'],
            ['state_code' => 'CT', 'state_name' => 'Connecticut', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_permit', 'notes' => 'Permit required.'],
            ['state_code' => 'DE', 'state_name' => 'Delaware', 'allows_dtc_shipping' => false, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => false, 'license_type_required' => null, 'notes' => 'DTC wine shipping prohibited.'],
            ['state_code' => 'FL', 'state_name' => 'Florida', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required. No volume limits.'],
            ['state_code' => 'GA', 'state_name' => 'Georgia', 'allows_dtc_shipping' => true, 'annual_case_limit' => 12, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_wine_shipper', 'notes' => 'Limited to 12 cases per household per year.'],
            ['state_code' => 'HI', 'state_name' => 'Hawaii', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required.'],
            ['state_code' => 'ID', 'state_name' => 'Idaho', 'allows_dtc_shipping' => true, 'annual_case_limit' => 24, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'wine_direct_shipper', 'notes' => 'Limited to 24 cases per customer per year.'],
            ['state_code' => 'IL', 'state_name' => 'Illinois', 'allows_dtc_shipping' => true, 'annual_case_limit' => 12, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'wine_shipper_license', 'notes' => 'Limited to 12 cases per individual per year.'],
            ['state_code' => 'IN', 'state_name' => 'Indiana', 'allows_dtc_shipping' => true, 'annual_case_limit' => 24, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_wine_shipper', 'notes' => 'License required. 24 cases per customer per year.'],
            ['state_code' => 'IA', 'state_name' => 'Iowa', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'wine_direct_shipper', 'notes' => 'License required.'],
            ['state_code' => 'KS', 'state_name' => 'Kansas', 'allows_dtc_shipping' => true, 'annual_case_limit' => 12, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_permit', 'notes' => '12 cases per customer per year.'],
            ['state_code' => 'KY', 'state_name' => 'Kentucky', 'allows_dtc_shipping' => false, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => false, 'license_type_required' => null, 'notes' => 'DTC wine shipping prohibited.'],
            ['state_code' => 'LA', 'state_name' => 'Louisiana', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_permit', 'notes' => 'Permit required.'],
            ['state_code' => 'ME', 'state_name' => 'Maine', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required.'],
            ['state_code' => 'MD', 'state_name' => 'Maryland', 'allows_dtc_shipping' => true, 'annual_case_limit' => 18, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_wine_shipper', 'notes' => '18 cases per household per year.'],
            ['state_code' => 'MA', 'state_name' => 'Massachusetts', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required.'],
            ['state_code' => 'MI', 'state_name' => 'Michigan', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipper_license', 'notes' => 'License required.'],
            ['state_code' => 'MN', 'state_name' => 'Minnesota', 'allows_dtc_shipping' => true, 'annual_case_limit' => 24, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => '24 cases per customer per year.'],
            ['state_code' => 'MS', 'state_name' => 'Mississippi', 'allows_dtc_shipping' => false, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => false, 'license_type_required' => null, 'notes' => 'DTC wine shipping prohibited.'],
            ['state_code' => 'MO', 'state_name' => 'Missouri', 'allows_dtc_shipping' => true, 'annual_case_limit' => 24, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => '24 cases per individual per year.'],
            ['state_code' => 'MT', 'state_name' => 'Montana', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required.'],
            ['state_code' => 'NE', 'state_name' => 'Nebraska', 'allows_dtc_shipping' => true, 'annual_case_limit' => 12, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'shipping_license', 'notes' => '12 cases per customer per year.'],
            ['state_code' => 'NV', 'state_name' => 'Nevada', 'allows_dtc_shipping' => true, 'annual_case_limit' => 12, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'wine_shipper_permit', 'notes' => '12 cases per customer per year.'],
            ['state_code' => 'NH', 'state_name' => 'New Hampshire', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required.'],
            ['state_code' => 'NJ', 'state_name' => 'New Jersey', 'allows_dtc_shipping' => true, 'annual_case_limit' => 12, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => '12 cases per customer per year.'],
            ['state_code' => 'NM', 'state_name' => 'New Mexico', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'wine_direct_shipper', 'notes' => 'License required.'],
            ['state_code' => 'NY', 'state_name' => 'New York', 'allows_dtc_shipping' => true, 'annual_case_limit' => 36, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipper_license', 'notes' => '36 cases per customer per year.'],
            ['state_code' => 'NC', 'state_name' => 'North Carolina', 'allows_dtc_shipping' => true, 'annual_case_limit' => 24, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_permit', 'notes' => '24 cases per customer per year.'],
            ['state_code' => 'ND', 'state_name' => 'North Dakota', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required.'],
            ['state_code' => 'OH', 'state_name' => 'Ohio', 'allows_dtc_shipping' => true, 'annual_case_limit' => 24, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipper_permit', 'notes' => '24 cases per customer per year.'],
            ['state_code' => 'OK', 'state_name' => 'Oklahoma', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'winery_shipper_permit', 'notes' => 'Permit required.'],
            ['state_code' => 'OR', 'state_name' => 'Oregon', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required. No volume limits.'],
            ['state_code' => 'PA', 'state_name' => 'Pennsylvania', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_wine_shipper', 'notes' => 'License required.'],
            ['state_code' => 'RI', 'state_name' => 'Rhode Island', 'allows_dtc_shipping' => true, 'annual_case_limit' => 12, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => '12 cases per customer per year.'],
            ['state_code' => 'SC', 'state_name' => 'South Carolina', 'allows_dtc_shipping' => true, 'annual_case_limit' => 24, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'wine_shipper_permit', 'notes' => '24 cases per customer per year.'],
            ['state_code' => 'SD', 'state_name' => 'South Dakota', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required.'],
            ['state_code' => 'TN', 'state_name' => 'Tennessee', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipper_license', 'notes' => 'License required.'],
            ['state_code' => 'TX', 'state_name' => 'Texas', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'wine_direct_shipper', 'notes' => 'License required.'],
            ['state_code' => 'UT', 'state_name' => 'Utah', 'allows_dtc_shipping' => false, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => false, 'license_type_required' => null, 'notes' => 'DTC wine shipping prohibited.'],
            ['state_code' => 'VT', 'state_name' => 'Vermont', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required.'],
            ['state_code' => 'VA', 'state_name' => 'Virginia', 'allows_dtc_shipping' => true, 'annual_case_limit' => 24, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'wine_shipper_license', 'notes' => '24 cases per household per year.'],
            ['state_code' => 'WA', 'state_name' => 'Washington', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required. No volume limits.'],
            ['state_code' => 'WV', 'state_name' => 'West Virginia', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_permit', 'notes' => 'Permit required.'],
            ['state_code' => 'WI', 'state_name' => 'Wisconsin', 'allows_dtc_shipping' => true, 'annual_case_limit' => 12, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_wine_shipper', 'notes' => '12 cases per customer per year.'],
            ['state_code' => 'WY', 'state_name' => 'Wyoming', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required.'],
            ['state_code' => 'DC', 'state_name' => 'District of Columbia', 'allows_dtc_shipping' => true, 'annual_case_limit' => null, 'annual_gallon_limit' => null, 'license_required' => true, 'license_type_required' => 'direct_shipping_license', 'notes' => 'License required.'],
        ];
    }
}
