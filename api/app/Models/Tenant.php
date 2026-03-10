<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Cashier\Billable;
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
    use Billable;
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
    /**
     * Plan definitions with Stripe price IDs.
     * These will be set to real Stripe price IDs once products are created.
     */
    public const PLANS = [
        'starter' => [
            'name' => 'Starter',
            'stripe_price' => null, // Set via STRIPE_PRICE_STARTER env
        ],
        'growth' => [
            'name' => 'Growth',
            'stripe_price' => null, // Set via STRIPE_PRICE_GROWTH env
        ],
        'pro' => [
            'name' => 'Pro',
            'stripe_price' => null, // Set via STRIPE_PRICE_PRO env
        ],
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'plan',
            'stripe_customer_id',
            'stripe_subscription_id',
            'pm_type',
            'pm_last_four',
            'trial_ends_at',
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
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * Get the Stripe price ID for a given plan.
     */
    public static function stripePriceForPlan(string $plan): ?string
    {
        return match ($plan) {
            'starter' => env('STRIPE_PRICE_STARTER'),
            'growth' => env('STRIPE_PRICE_GROWTH'),
            'pro' => env('STRIPE_PRICE_PRO'),
            default => null,
        };
    }

    /**
     * Check if the tenant has an active subscription.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->subscribed('default');
    }

    /**
     * Check if the tenant is in a grace period (cancelled but not yet expired).
     */
    public function isInGracePeriod(): bool
    {
        $subscription = $this->subscription('default');

        return $subscription && $subscription->onGracePeriod();
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
