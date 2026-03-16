<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Handle failed payment — log warning for follow-up.
 */
class PaymentFailedHandler implements WebhookHandler
{
    public function handle(array $payload): void
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
