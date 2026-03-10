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
 * @property string $plan free|basic|pro|max
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
     * Plan definitions with Stripe price IDs.
     * These will be set to real Stripe price IDs once products are created.
     */
    /**
     * Plan hierarchy — higher index = higher tier.
     * Used by planRank() for upgrade/downgrade comparison.
     */
    public const PLAN_HIERARCHY = ['free', 'basic', 'pro', 'max'];

    public const PLANS = [
        'free' => [
            'name' => 'Free',
            'stripe_price' => null, // Free tier — no Stripe subscription required
        ],
        'basic' => [
            'name' => 'Basic',
            'stripe_price' => null, // Set via STRIPE_PRICE_BASIC env
        ],
        'pro' => [
            'name' => 'Pro',
            'stripe_price' => null, // Set via STRIPE_PRICE_PRO env
        ],
        'max' => [
            'name' => 'Max',
            'stripe_price' => null, // Set via STRIPE_PRICE_MAX env
        ],
    ];

    /**
     * Default attribute values.
     * Mirrors the database default so the in-memory model is correct
     * even before a refresh from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'plan' => 'free',
    ];

    /**
     * Custom columns stored in the tenants table (not in the data JSON column).
     * stancl/tenancy stores anything not listed here in a JSON `data` column.
     *
     * @return array<int, string>
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
        $price = match ($plan) {
            'free' => null,
            'basic' => config('services.stripe.price_basic'),
            'pro' => config('services.stripe.price_pro'),
            'max' => config('services.stripe.price_max'),
            default => null,
        };

        return is_string($price) ? $price : null;
    }

    /**
     * Check if the tenant is on the free plan (no subscription required).
     */
    public function isFreePlan(): bool
    {
        return $this->plan === 'free';
    }

    /**
     * Check if the tenant has an active subscription or is on the free plan.
     */
    public function hasActiveAccess(): bool
    {
        return $this->isFreePlan() || $this->subscribed('default');
    }

    /**
     * Check if the tenant has an active subscription.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->subscribed('default');
    }

    /**
     * Get the numeric rank of the current plan (0=free, 1=basic, 2=pro, 3=max).
     * Used for upgrade/downgrade comparison.
     */
    public function planRank(): int
    {
        $index = array_search($this->plan, self::PLAN_HIERARCHY, true);

        return $index !== false ? $index : 0;
    }

    /**
     * Check if the given plan would be a downgrade from the current plan.
     */
    public function isDowngradeTo(string $plan): bool
    {
        $targetIndex = array_search($plan, self::PLAN_HIERARCHY, true);

        return $targetIndex !== false && $targetIndex < $this->planRank();
    }

    /**
     * Check if the tenant's plan meets or exceeds the required plan level.
     * Usage: $tenant->hasPlanAtLeast('pro') returns true for pro and max tenants.
     */
    public function hasPlanAtLeast(string $minimumPlan): bool
    {
        $minimumIndex = array_search($minimumPlan, self::PLAN_HIERARCHY, true);

        return $minimumIndex !== false && $this->planRank() >= $minimumIndex;
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
