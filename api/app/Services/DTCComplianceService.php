<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CustomerDTCShipment;
use App\Models\DTCComplianceRule;
use Carbon\Carbon;

/**
 * DTCComplianceService — checks DTC shipping eligibility and tracks shipments.
 *
 * Used to validate whether a customer can receive a wine shipment in a given state,
 * and to track annual shipment volumes per customer per state for limit enforcement.
 */
class DTCComplianceService
{
    /**
     * Check if a shipment can be made to a state for a given customer.
     *
     * @return array{allowed: bool, reason: string, annual_cases_shipped: float, annual_gallons_shipped: float, case_limit: int|null, gallon_limit: float|null}
     */
    public function checkEligibility(
        string $customerId,
        string $stateCode,
        float $casesToShip = 0,
        float $gallonsToShip = 0,
    ): array {
        $rule = DTCComplianceRule::where('state_code', $stateCode)->first();

        if ($rule === null) {
            return [
                'allowed' => false,
                'reason' => 'No DTC compliance rule found for state '.$stateCode,
                'annual_cases_shipped' => 0,
                'annual_gallons_shipped' => 0,
                'case_limit' => null,
                'gallon_limit' => null,
            ];
        }

        if (! $rule->allows_dtc_shipping) {
            return [
                'allowed' => false,
                'reason' => $rule->state_name.' does not allow direct-to-consumer wine shipments',
                'annual_cases_shipped' => 0,
                'annual_gallons_shipped' => 0,
                'case_limit' => $rule->annual_case_limit,
                'gallon_limit' => $rule->annual_gallon_limit !== null ? (float) $rule->annual_gallon_limit : null,
            ];
        }

        // Calculate annual shipment totals for this customer + state
        $yearStart = Carbon::now()->startOfYear();
        /** @var object{total_cases: numeric-string, total_gallons: numeric-string}|null $annualShipments */
        $annualShipments = CustomerDTCShipment::where('customer_id', $customerId)
            ->where('state_code', $stateCode)
            ->where('shipped_at', '>=', $yearStart)
            ->selectRaw('COALESCE(SUM(cases_shipped), 0) as total_cases')
            ->selectRaw('COALESCE(SUM(gallons_shipped), 0) as total_gallons')
            ->first();

        $annualCases = $annualShipments !== null ? (float) $annualShipments->total_cases : 0.0;
        $annualGallons = $annualShipments !== null ? (float) $annualShipments->total_gallons : 0.0;

        // Check case limit
        if ($rule->annual_case_limit !== null) {
            if (($annualCases + $casesToShip) > $rule->annual_case_limit) {
                return [
                    'allowed' => false,
                    'reason' => sprintf(
                        'Shipment would exceed annual case limit for %s (limit: %d, shipped: %.1f, requested: %.1f)',
                        $rule->state_name,
                        $rule->annual_case_limit,
                        $annualCases,
                        $casesToShip,
                    ),
                    'annual_cases_shipped' => $annualCases,
                    'annual_gallons_shipped' => $annualGallons,
                    'case_limit' => $rule->annual_case_limit,
                    'gallon_limit' => $rule->annual_gallon_limit !== null ? (float) $rule->annual_gallon_limit : null,
                ];
            }
        }

        // Check gallon limit
        if ($rule->annual_gallon_limit !== null) {
            if (($annualGallons + $gallonsToShip) > (float) $rule->annual_gallon_limit) {
                return [
                    'allowed' => false,
                    'reason' => sprintf(
                        'Shipment would exceed annual gallon limit for %s (limit: %.1f, shipped: %.1f, requested: %.1f)',
                        $rule->state_name,
                        (float) $rule->annual_gallon_limit,
                        $annualGallons,
                        $gallonsToShip,
                    ),
                    'annual_cases_shipped' => $annualCases,
                    'annual_gallons_shipped' => $annualGallons,
                    'case_limit' => $rule->annual_case_limit,
                    'gallon_limit' => (float) $rule->annual_gallon_limit,
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => 'Shipment allowed',
            'annual_cases_shipped' => $annualCases,
            'annual_gallons_shipped' => $annualGallons,
            'case_limit' => $rule->annual_case_limit,
            'gallon_limit' => $rule->annual_gallon_limit !== null ? (float) $rule->annual_gallon_limit : null,
        ];
    }

    /**
     * Record a shipment for tracking.
     */
    public function recordShipment(
        string $customerId,
        string $stateCode,
        float $cases,
        float $gallons,
        ?string $orderId = null,
    ): CustomerDTCShipment {
        return CustomerDTCShipment::create([
            'customer_id' => $customerId,
            'state_code' => $stateCode,
            'order_id' => $orderId,
            'cases_shipped' => $cases,
            'gallons_shipped' => $gallons,
            'shipped_at' => now(),
        ]);
    }

    /**
     * Get annual shipment summary for a customer across all states.
     *
     * @return array<string, array{cases: float, gallons: float, state_name: string}>
     */
    public function getCustomerAnnualSummary(string $customerId): array
    {
        $yearStart = Carbon::now()->startOfYear();

        /** @var \Illuminate\Database\Eloquent\Collection<int, CustomerDTCShipment&object{total_cases: numeric-string, total_gallons: numeric-string}> $shipments */
        $shipments = CustomerDTCShipment::where('customer_id', $customerId)
            ->where('shipped_at', '>=', $yearStart)
            ->selectRaw('state_code, SUM(cases_shipped) as total_cases, SUM(gallons_shipped) as total_gallons')
            ->groupBy('state_code')
            ->get();

        $summary = [];
        foreach ($shipments as $row) {
            $rule = DTCComplianceRule::where('state_code', $row->state_code)->first();
            $summary[$row->state_code] = [
                'cases' => (float) $row->total_cases,
                'gallons' => (float) $row->total_gallons,
                'state_name' => $rule !== null ? $rule->state_name : $row->state_code,
            ];
        }

        return $summary;
    }
}
