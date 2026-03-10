<?php

namespace App\Providers;

use App\Listeners\HandleSubscriptionChange;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookReceived;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Listen for Cashier webhook events
        Event::listen(WebhookReceived::class, HandleSubscriptionChange::class);
    }
}
