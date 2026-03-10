<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-scoped winery profile — one per tenant.
 *
 * Stores winery identity, location, TTB compliance info, and preferences
 * that affect calculations and reporting across all modules.
 *
 * @property string $id UUID
 * @property string $name Winery name
 * @property string|null $dba_name "Doing business as" name
 * @property string|null $description Winery description
 * @property string|null $logo_path Path to logo file
 * @property string|null $website Website URL
 * @property string|null $phone Phone number
 * @property string|null $email Contact email
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $city
 * @property string|null $state 2-letter state code
 * @property string|null $zip ZIP/postal code
 * @property string $country 2-letter country code
 * @property string $timezone IANA timezone
 * @property string|null $ttb_permit_number TTB Basic Permit Number
 * @property string|null $ttb_registry_number TTB Registry/Plant Number
 * @property string|null $state_license_number State alcohol license
 * @property string $unit_system 'imperial' or 'metric'
 * @property string $currency 3-letter currency code
 * @property int $fiscal_year_start_month 1-12
 * @property string $date_format PHP date format string
 * @property bool $onboarding_complete
 */
class WineryProfile extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'dba_name',
        'description',
        'logo_path',
        'website',
        'phone',
        'email',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'zip',
        'country',
        'timezone',
        'ttb_permit_number',
        'ttb_registry_number',
        'state_license_number',
        'unit_system',
        'currency',
        'fiscal_year_start_month',
        'date_format',
        'onboarding_complete',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year_start_month' => 'integer',
            'onboarding_complete' => 'boolean',
        ];
    }

    /**
     * Check if the winery uses imperial units (gallons).
     */
    public function usesImperial(): bool
    {
        return $this->unit_system === 'imperial';
    }

    /**
     * Check if the winery uses metric units (liters).
     */
    public function usesMetric(): bool
    {
        return $this->unit_system === 'metric';
    }
}
