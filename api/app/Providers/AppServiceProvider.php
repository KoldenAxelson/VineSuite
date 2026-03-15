<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\HandleSubscriptionChange;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookReceived;
use Livewire\Livewire;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

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

        // Livewire's /livewire/update route must go through tenancy middleware
        // so that Filament login and all Livewire requests resolve the tenant DB.
        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/livewire/update', $handle)
                ->middleware([
                    'web',
                    InitializeTenancyByDomain::class,
                    PreventAccessFromCentralDomains::class,
                ]);
        });
    }
}
