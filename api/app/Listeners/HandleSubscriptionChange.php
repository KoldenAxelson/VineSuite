<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Listeners\Webhooks\PaymentFailedHandler;
use App\Listeners\Webhooks\PaymentSucceededHandler;
use App\Listeners\Webhooks\WebhookHandler;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Dispatches Stripe webhook events to dedicated handler classes.
 *
 * Registered in AppServiceProvider.
 *
 * To add a new webhook handler:
 * 1. Create a class implementing WebhookHandler in App\Listeners\Webhooks\
 * 2. Add the Stripe event type → handler class mapping to HANDLERS below
 *
 * The dispatcher never needs modification beyond the HANDLERS map.
 */
class HandleSubscriptionChange
{
    /**
     * Map of Stripe event types to their handler classes.
     *
     * @var array<string, class-string<WebhookHandler>>
     */
    private const HANDLERS = [
        'invoice.payment_succeeded' => PaymentSucceededHandler::class,
        'invoice.payment_failed' => PaymentFailedHandler::class,
        // Future handlers:
        // 'customer.subscription.updated' => SubscriptionUpdatedHandler::class,
        // 'customer.subscription.deleted' => SubscriptionDeletedHandler::class,
        // 'invoice.finalized' => InvoiceFinalizedHandler::class,
    ];

    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? '';

        $handlerClass = self::HANDLERS[$type] ?? null;

        if ($handlerClass === null) {
            return;
        }

        /** @var WebhookHandler $handler */
        $handler = app($handlerClass);
        $handler->handle($event->payload);
    }
}
