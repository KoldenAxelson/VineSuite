<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * LaborRate — configurable hourly rates by role for labor cost tracking.
 *
 * When a work order is completed with hours logged, the system looks up
 * the active labor rate for the completing user's role (or the work order's
 * assigned role) and calculates: hours × hourly_rate → cost entry.
 *
 * @property string $id UUID
 * @property string $role Role name (e.g., cellar_hand, winemaker)
 * @property string $hourly_rate Decimal hourly rate
 * @property bool $is_active Whether this rate is currently active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class LaborRate extends Model
{
    use HasUuids;

    protected $fillable = [
        'role',
        'hourly_rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * Filter by role.
     *
     * @param  Builder<LaborRate>  $query
     * @return Builder<LaborRate>
     */
    public function scopeForRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    /**
     * Filter active rates only.
     *
     * @param  Builder<LaborRate>  $query
     * @return Builder<LaborRate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the current active rate for a role, or null if none configured.
     */
    public static function getActiveRate(string $role): ?self
    {
        return static::active()->forRole($role)->first();
    }
}
