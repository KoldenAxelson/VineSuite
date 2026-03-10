<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe webhook handler — extends Cashier's webhook controller.
 *
 * Cashier automatically handles subscription lifecycle events.
 * We add custom handling for plan syncing and cancellation grace periods.
 */
class WebhookController extends CashierWebhookController
{
    /**
     * Handle customer.subscription.updated event.
     *
     * Syncs the plan column on the tenant when the subscription price changes.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleCustomerSubscriptionUpdated(array $payload): ?Response
    {
        $response = parent::handleCustomerSubscriptionUpdated($payload);

        $stripeSubscription = $payload['data']['object'];
        $stripeCustomerId = $stripeSubscription['customer'];

        $tenant = Tenant::where('stripe_customer_id', $stripeCustomerId)->first();

        if (! $tenant) {
            Log::warning('Webhook: tenant not found for subscription update', [
                'stripe_customer_id' => $stripeCustomerId,
            ]);

            return $response;
        }

        // Determine plan from the price ID
        $priceId = $stripeSubscription['items']['data'][0]['price']['id'] ?? null;
        $plan = $this->planFromStripePrice($priceId);

        if ($plan && $tenant->plan !== $plan) {
            $oldPlan = $tenant->plan;
            $tenant->update(['plan' => $plan]);

            Log::info('Webhook: tenant plan synced', [
                'tenant_id' => $tenant->id,
                'old_plan' => $oldPlan,
                'new_plan' => $plan,
                'stripe_price' => $priceId,
            ]);
        }

        // Handle cancellation — log grace period start
        if ($stripeSubscription['cancel_at_period_end'] ?? false) {
            Log::info('Webhook: subscription set to cancel at period end', [
                'tenant_id' => $tenant->id,
                'current_period_end' => $stripeSubscription['current_period_end'],
            ]);
        }

        return $response;
    }

    /**
     * Handle customer.subscription.deleted event.
     *
     * When a subscription is fully deleted (grace period expired),
     * the tenant retains read-only access for 30 days.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionDeleted($payload);

        $stripeCustomerId = $payload['data']['object']['customer'];
        $tenant = Tenant::where('stripe_customer_id', $stripeCustomerId)->first();

        if ($tenant) {
            Log::info('Webhook: subscription deleted, tenant entering read-only period', [
                'tenant_id' => $tenant->id,
                'plan' => $tenant->plan,
            ]);

            // Future: trigger read-only mode enforcement
            // The 30-day read-only window is tracked via Cashier's ends_at on the subscription
        }

        return $response;
    }

    /**
     * Resolve a plan name from a Stripe price ID.
     */
    protected function planFromStripePrice(?string $priceId): ?string
    {
        if (! $priceId) {
            return null;
        }

        return match ($priceId) {
            config('services.stripe.price_starter') => 'starter',
            config('services.stripe.price_growth') => 'growth',
            config('services.stripe.price_pro') => 'pro',
            default => null,
        };
    }
}
