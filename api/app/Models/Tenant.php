<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Tenant model — represents a single winery on the platform.
 *
 * Each tenant gets its own PostgreSQL schema for complete data isolation.
 * Central fields (plan, stripe IDs) live in the central `tenants` table.
 *
 * @property string $id UUID
 * @property string $name Winery display name
 * @property string $slug URL-safe identifier (used in subdomains)
 * @property string $plan starter|growth|pro
 * @property string|null $stripe_customer_id
 * @property string|null $stripe_subscription_id
 * @property \Carbon\Carbon|null $launched_at When the tenant completed onboarding
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;
    use HasUuids;

    /**
     * The "type" of the primary key ID.
     */
    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Custom columns stored in the tenants table (not in the data JSON column).
     * stancl/tenancy stores anything not listed here in a JSON `data` column.
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'plan',
            'stripe_customer_id',
            'stripe_subscription_id',
            'launched_at',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'launched_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant's database name (schema name in PostgreSQL).
     * Uses the slug for readability: tenant_mountainview
     */
    public function getTenantKeyName(): string
    {
        return 'id';
    }
}
