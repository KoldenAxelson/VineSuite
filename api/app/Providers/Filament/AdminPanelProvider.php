<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Leandrocfe\FilamentApexCharts\FilamentApexChartsPlugin;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/**
 * Filament Management Portal — accessible at /portal.
 *
 * Tenant-aware: uses InitializeTenancyByDomain to resolve the tenant
 * from the subdomain. All resources and pages operate within tenant context.
 *
 * Auth: integrates with existing Sanctum + RBAC. Session-based auth for
 * the portal (Filament's default), API uses token auth separately.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('portal')
            ->path('portal')
            ->login()
            ->colors([
                'primary' => Color::Purple,
                'danger' => Color::Rose,
                'gray' => Color::Slate,
                'info' => Color::Blue,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->brandName('VineSuite')
            ->navigationGroups([
                NavigationGroup::make('Production')
                    ->icon('heroicon-o-beaker')
                    ->collapsed(false),
                NavigationGroup::make('Inventory')
                    ->icon('heroicon-o-cube'),
                NavigationGroup::make('Compliance')
                    ->icon('heroicon-o-shield-check'),
                NavigationGroup::make('Sales')
                    ->icon('heroicon-o-shopping-cart'),
                NavigationGroup::make('Club')
                    ->icon('heroicon-o-user-group'),
                NavigationGroup::make('CRM')
                    ->icon('heroicon-o-users'),
                NavigationGroup::make('Settings')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(true),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                InitializeTenancyByDomain::class,
                PreventAccessFromCentralDomains::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->spa()
            ->plugins([
                FilamentApexChartsPlugin::make(),
            ]);
    }
}
