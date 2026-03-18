<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * OverheadRate — configurable overhead allocation rates.
 *
 * Fixed costs (rent, utilities, insurance) are spread across lots using
 * one of three allocation methods: per gallon, per case, or per labor hour.
 * Wineries set these rates annually; allocation is run manually.
 *
 * @property string $id UUID
 * @property string $name Human-readable name (e.g., "Winery Rent")
 * @property string $allocation_method per_gallon|per_case|per_labor_hour
 * @property string $rate Decimal rate
 * @property bool $is_active Whether this rate is currently active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OverheadRate extends Model
{
    use HasUuids;

    public const ALLOCATION_METHODS = [
        'per_gallon',
        'per_case',
        'per_labor_hour',
    ];

    protected $fillable = [
        'name',
        'allocation_method',
        'rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * @param  Builder<OverheadRate>  $query
     * @return Builder<OverheadRate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<OverheadRate>  $query
     * @return Builder<OverheadRate>
     */
    public function scopeOfMethod(Builder $query, string $method): Builder
    {
        return $query->where('allocation_method', $method);
    }
}
