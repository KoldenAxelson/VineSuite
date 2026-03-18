<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * DTC Compliance Rule — state-by-state direct-to-consumer shipping rules.
 *
 * @property string $id UUID
 * @property string $state_code 2-letter state code
 * @property string $state_name
 * @property bool $allows_dtc_shipping
 * @property int|null $annual_case_limit
 * @property float|null $annual_gallon_limit
 * @property bool $license_required
 * @property string|null $license_type_required
 * @property string|null $notes
 * @property Carbon|null $last_verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DTCComplianceRule extends Model
{
    use HasUuids;

    protected $table = 'dtc_compliance_rules';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'state_code',
        'state_name',
        'allows_dtc_shipping',
        'annual_case_limit',
        'annual_gallon_limit',
        'license_required',
        'license_type_required',
        'notes',
        'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'allows_dtc_shipping' => 'boolean',
            'annual_case_limit' => 'integer',
            'annual_gallon_limit' => 'decimal:1',
            'license_required' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }
}
