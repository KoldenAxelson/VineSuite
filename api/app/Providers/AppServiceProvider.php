<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AdditionServiceInterface;
use App\Contracts\BlendServiceInterface;
use App\Contracts\BottlingServiceInterface;
use App\Contracts\InventoryServiceInterface;
use App\Contracts\LotServiceInterface;
use App\Contracts\TransferServiceInterface;
use App\Listeners\HandleSubscriptionChange;
use App\Services\AdditionService;
use App\Services\BlendService;
use App\Services\BottlingService;
use App\Services\InventoryService;
use App\Services\LabImport\ETSLabsParser;
use App\Services\LabImport\GenericCSVParser;
use App\Services\LabImport\LabImportService;
use App\Services\LotService;
use App\Services\TransferService;
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
        // Service interface bindings — controllers type-hint interfaces,
        // concrete implementations are swappable here.
        $this->app->bind(LotServiceInterface::class, LotService::class);
        $this->app->bind(AdditionServiceInterface::class, AdditionService::class);
        $this->app->bind(TransferServiceInterface::class, TransferService::class);
        $this->app->bind(BlendServiceInterface::class, BlendService::class);
        $this->app->bind(BottlingServiceInterface::class, BottlingService::class);
        $this->app->bind(InventoryServiceInterface::class, InventoryService::class);

        // Register lab CSV parsers — most specific first, generic last.
        // To add a new lab format, implement LabCsvParser and add it here.
        $this->app->when(LabImportService::class)
            ->needs('$parsers')
            ->give(fn () => [
                $this->app->make(ETSLabsParser::class),
                $this->app->make(GenericCSVParser::class),
            ]);
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
