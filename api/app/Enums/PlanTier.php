<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * PlanTier — SaaS subscription tiers.
 *
 * Single source of truth for plan names, hierarchy, display labels,
 * and Stripe price ID resolution. Adding a tier = adding a case here.
 *
 * Usage:
 *   PlanTier::Pro->stripePrice()     // config('services.stripe.price_pro')
 *   PlanTier::Pro->rank()            // 2
 *   PlanTier::Pro->label()           // 'Pro'
 *   PlanTier::from('pro')            // PlanTier::Pro
 *   $tenant->plan_tier->isAtLeast(PlanTier::Pro) // bool
 */
enum PlanTier: string
{
    case Free = 'free';
    case Basic = 'basic';
    case Pro = 'pro';
    case Max = 'max';

    /**
     * Human-readable display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Basic => 'Basic',
            self::Pro => 'Pro',
            self::Max => 'Max',
        };
    }

    /**
     * Numeric rank for upgrade/downgrade comparison.
     * Higher = better plan.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Basic => 1,
            self::Pro => 2,
            self::Max => 3,
        };
    }

    /**
     * Stripe price ID for this tier, or null for free.
     */
    public function stripePrice(): ?string
    {
        $price = match ($this) {
            self::Free => null,
            self::Basic => config('services.stripe.price_basic'),
            self::Pro => config('services.stripe.price_pro'),
            self::Max => config('services.stripe.price_max'),
        };

        return is_string($price) ? $price : null;
    }

    /**
     * Check if this tier meets or exceeds a minimum tier.
     */
    public function isAtLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    /**
     * Check if moving to this tier from $current would be a downgrade.
     */
    public function isDowngradeFrom(self $current): bool
    {
        return $this->rank() < $current->rank();
    }
}
