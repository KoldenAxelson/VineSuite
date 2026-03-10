<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SaaS billing controller — handles Stripe Checkout, Customer Portal, and plan changes.
 *
 * Billing is per-tenant. The Tenant model is the Cashier Billable.
 * All endpoints require authentication and owner/admin role.
 */
class BillingController extends Controller
{
    /**
     * Create a Stripe Checkout session for subscribing to a plan.
     *
     * POST /api/v1/billing/checkout
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:basic,pro,max'],
        ]);

        $tenant = tenant();
        $priceId = Tenant::stripePriceForPlan($validated['plan']);

        if (! $priceId) {
            return ApiResponse::error("Stripe price not configured for plan: {$validated['plan']}", 422);
        }

        // Create or retrieve the Stripe customer
        $tenant->createOrGetStripeCustomer([
            'name' => $tenant->name,
            'metadata' => [
                'tenant_id' => $tenant->id,
                'slug' => $tenant->slug,
            ],
        ]);

        $checkoutSession = $tenant->newSubscription('default', $priceId)
            ->checkout([
                'success_url' => config('app.frontend_url', config('app.url')).'/billing/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => config('app.frontend_url', config('app.url')).'/billing/cancel',
                'metadata' => [
                    'tenant_id' => $tenant->id,
                    'plan' => $validated['plan'],
                ],
            ]);

        Log::info('Billing: checkout session created', [
            'tenant_id' => $tenant->id,
            'plan' => $validated['plan'],
            'session_id' => $checkoutSession->id,
            'user_id' => $request->user()->id,
        ]);

        return ApiResponse::success([
            'checkout_url' => $checkoutSession->url,
            'session_id' => $checkoutSession->id,
        ]);
    }

    /**
     * Create a Stripe Customer Portal session for billing self-service.
     *
     * POST /api/v1/billing/portal
     */
    public function portal(Request $request): JsonResponse
    {
        $tenant = tenant();

        if (! $tenant->hasStripeId()) {
            return ApiResponse::error('No billing account found. Please subscribe to a plan first.', 422);
        }

        $portalSession = $tenant->redirectToBillingPortal(
            config('app.frontend_url', config('app.url')).'/settings/billing'
        );

        return ApiResponse::success([
            'portal_url' => $portalSession->url,
        ]);
    }

    /**
     * Change the current subscription plan.
     *
     * PUT /api/v1/billing/plan
     */
    public function changePlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:basic,pro,max'],
        ]);

        $tenant = tenant();
        $subscription = $tenant->subscription('default');

        if (! $subscription || ! $subscription->active()) {
            return ApiResponse::error('No active subscription found.', 422);
        }

        $newPriceId = Tenant::stripePriceForPlan($validated['plan']);

        if (! $newPriceId) {
            return ApiResponse::error("Stripe price not configured for plan: {$validated['plan']}", 422);
        }

        // Swap the subscription to the new price (prorate by default)
        $subscription->swap($newPriceId);

        // Update the plan column on the tenant
        $tenant->update(['plan' => $validated['plan']]);

        Log::info('Billing: plan changed', [
            'tenant_id' => $tenant->id,
            'new_plan' => $validated['plan'],
            'user_id' => $request->user()->id,
        ]);

        return ApiResponse::success(['plan' => $validated['plan']], meta: ['message' => 'Plan changed successfully.']);
    }

    /**
     * Get the current billing status.
     *
     * GET /api/v1/billing/status
     */
    public function status(): JsonResponse
    {
        $tenant = tenant();
        $subscription = $tenant->subscription('default');

        return ApiResponse::success([
            'plan' => $tenant->plan,
            'has_stripe_id' => $tenant->hasStripeId(),
            'subscribed' => $tenant->subscribed('default'),
            'on_trial' => $subscription?->onTrial() ?? false,
            'on_grace_period' => $subscription?->onGracePeriod() ?? false,
            'cancelled' => $subscription?->cancelled() ?? false,
            'ends_at' => $subscription?->ends_at?->toIso8601String(),
            'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
        ]);
    }
}
