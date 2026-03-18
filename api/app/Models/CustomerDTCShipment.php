<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer DTC Shipment — tracks individual shipments for per-state limit enforcement.
 *
 * @property string $id UUID
 * @property string $customer_id External customer reference
 * @property string $state_code
 * @property string|null $order_id
 * @property float $cases_shipped
 * @property float $gallons_shipped
 * @property Carbon $shipped_at
 * @property Carbon $created_at
 */
class CustomerDTCShipment extends Model
{
    use HasUuids;

    protected $table = 'customer_dtc_shipments';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'state_code',
        'order_id',
        'cases_shipped',
        'gallons_shipped',
        'shipped_at',
    ];

    protected function casts(): array
    {
        return [
            'cases_shipped' => 'decimal:2',
            'gallons_shipped' => 'decimal:1',
            'shipped_at' => 'datetime',
        ];
    }
}
