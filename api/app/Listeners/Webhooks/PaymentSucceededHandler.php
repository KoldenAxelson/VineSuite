<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Handle successful payment — log for audit trail.
 */
class PaymentSucceededHandler implements WebhookHandler
{
    public function handle(array $payload): void
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
}
