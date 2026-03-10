<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Listens for Cashier webhook events and handles subscription lifecycle changes.
 *
 * Registered in EventServiceProvider (or auto-discovered).
 * Handles plan syncing, cancellation tracking, and payment failures.
 */
class HandleSubscriptionChange
{
    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        $type = $payload['type'] ?? '';

        match ($type) {
            'invoice.payment_succeeded' => $this->handlePaymentSucceeded($payload),
            'invoice.payment_failed' => $this->handlePaymentFailed($payload),
            default => null,
        };
    }

    /**
     * Handle successful payment — log for audit trail.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handlePaymentSucceeded(array $payload): void
    {
        $invoice = $payload['data']['object'];
        $stripeCustomerId = $invoice['customer'];

        $tenant = Tenant::where('stripe_customer_id', $stripeCustomerId)->first();

        if ($tenant) {
            Log::info('Billing: payment succeeded', [
                'tenant_id' => $tenant->id,
                'amount' => $invoice['amount_paid'],
                'currency' => $invoice['currency'],
                'invoice_id' => $invoice['id'],
            ]);
        }
    }

    /**
     * Handle failed payment — log warning for follow-up.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handlePaymentFailed(array $payload): void
    {
        $invoice = $payload['data']['object'];
        $stripeCustomerId = $invoice['customer'];

        $tenant = Tenant::where('stripe_customer_id', $stripeCustomerId)->first();

        if ($tenant) {
            Log::warning('Billing: payment failed', [
                'tenant_id' => $tenant->id,
                'amount' => $invoice['amount_due'],
                'currency' => $invoice['currency'],
                'invoice_id' => $invoice['id'],
                'attempt_count' => $invoice['attempt_count'] ?? null,
            ]);

            // Future: send notification to tenant owner about payment failure
        }
    }
}
