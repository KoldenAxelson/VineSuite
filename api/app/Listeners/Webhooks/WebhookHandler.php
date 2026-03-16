<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

/**
 * Contract for Stripe webhook event handlers.
 *
 * Each handler processes a specific Stripe webhook event type.
 * Register new handlers in HandleSubscriptionChange::HANDLERS.
 */
interface WebhookHandler
{
    /**
     * Handle the webhook payload.
     *
     * @param  array<string, mixed>  $payload  The full Stripe webhook payload
     */
    public function handle(array $payload): void;
}
