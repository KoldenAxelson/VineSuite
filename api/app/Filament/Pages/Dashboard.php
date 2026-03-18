<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\ActiveFermentationsTableWidget;
use App\Filament\Widgets\BulkWineStatsWidget;
use App\Filament\Widgets\ComplianceStatusStatsWidget;
use App\Filament\Widgets\CostByVintageTableWidget;
use App\Filament\Widgets\CostReportsStatsWidget;
use App\Filament\Widgets\LabAlertsTableWidget;
use App\Filament\Widgets\LowStockSkusTableWidget;
use App\Filament\Widgets\MarginReportTableWidget;
use App\Filament\Widgets\OpenWorkOrdersStatsWidget;
use App\Filament\Widgets\OverviewProductionChart;
use App\Filament\Widgets\PendingPurchaseOrdersStatsWidget;
use App\Filament\Widgets\RecentActivityTableWidget;
use App\Filament\Widgets\VesselUtilizationStatsWidget;
use App\Models\User;
use App\Models\WineryProfile;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * VineSuite persona-based dashboard.
 *
 * Uses Filament's HasFiltersForm to render persona tabs via ToggleButtons.
 * Super-admins (owner/admin) see all personas; other roles see only
 * the tabs relevant to their role.
 *
 * Widget sets per persona:
 *   - winemaker: fermentations, lab alerts, bulk wine stats
 *   - cellar:    work orders, vessel utilization, recent activity
 *   - business:  cost stats, cost by vintage, margin report
 *   - compliance: TTB status, licenses, draft reports
 *   - inventory:  bulk wine stats, low stock SKUs, pending POs
 *   - overview:   simplified read-only summary for non-production roles
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -2;

    public function getHeading(): string
    {
        $profile = WineryProfile::first();

        return $profile ? "Welcome to {$profile->name}" : 'Dashboard';
    }

    public function getSubheading(): ?string
    {
        return 'Your winery at a glance';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->schema([
                ToggleButtons::make('persona')
                    ->label('')
                    ->options($this->getPersonaOptions())
                    ->default($this->getDefaultPersona())
                    ->icons($this->getPersonaIcons())
                    ->inline()
                    ->live()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        $persona = (string) ($this->filters['persona'] ?? $this->getDefaultPersona());

        return match ($persona) {
            'winemaker' => $this->getWinemakerWidgets(),
            'cellar' => $this->getCellarWidgets(),
            'business' => $this->getBusinessWidgets(),
            'compliance' => $this->getComplianceWidgets(),
            'inventory' => $this->getInventoryWidgets(),
            default => $this->getOverviewWidgets(),
        };
    }

    /**
     * Available persona options filtered by the current user's role.
     *
     * @return array<string, string>
     */
    private function getPersonaOptions(): array
    {
        $role = $this->getCurrentUserRole();

        $all = [
            'overview' => 'Overview',
            'winemaker' => 'Winemaker',
            'cellar' => 'Cellar',
            'business' => 'Business',
            'compliance' => 'Compliance',
            'inventory' => 'Inventory',
        ];

        // Super-admins see all tabs
        if (in_array($role, ['owner', 'admin'])) {
            return $all;
        }

        $roleMap = [
            'winemaker' => ['winemaker', 'inventory'],
            'cellar_hand' => ['cellar', 'inventory'],
            'accountant' => ['business', 'compliance', 'inventory'],
            'tasting_room_staff' => ['overview'],
            'read_only' => ['overview'],
        ];

        $allowed = $roleMap[$role] ?? ['overview'];

        return array_intersect_key($all, array_flip($allowed));
    }

    /**
     * Icons for persona toggle buttons.
     *
     * @return array<string, string>
     */
    private function getPersonaIcons(): array
    {
        return [
            'winemaker' => 'heroicon-o-beaker',
            'cellar' => 'heroicon-o-cube',
            'business' => 'heroicon-o-currency-dollar',
            'compliance' => 'heroicon-o-shield-check',
            'inventory' => 'heroicon-o-archive-box',
            'overview' => 'heroicon-o-home',
        ];
    }

    /**
     * Default persona for the current user's role.
     */
    private function getDefaultPersona(): string
    {
        return 'overview';
    }

    private function getCurrentUserRole(): string
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->role ?? 'read_only';
    }

    // ──────────────────────────────────────────────
    // Widget sets per persona
    // ──────────────────────────────────────────────

    /**
     * @return array<class-string<Widget>>
     */
    private function getWinemakerWidgets(): array
    {
        return [
            ActiveFermentationsTableWidget::class,
            LabAlertsTableWidget::class,
            BulkWineStatsWidget::class,
        ];
    }

    /**
     * @return array<class-string<Widget>>
     */
    private function getCellarWidgets(): array
    {
        return [
            OpenWorkOrdersStatsWidget::class,
            VesselUtilizationStatsWidget::class,
            RecentActivityTableWidget::class,
        ];
    }

    /**
     * @return array<class-string<Widget>>
     */
    private function getBusinessWidgets(): array
    {
        return [
            CostReportsStatsWidget::class,
            CostByVintageTableWidget::class,
            MarginReportTableWidget::class,
        ];
    }

    /**
     * @return array<class-string<Widget>>
     */
    private function getComplianceWidgets(): array
    {
        return [
            ComplianceStatusStatsWidget::class,
        ];
    }

    /**
     * @return array<class-string<Widget>>
     */
    private function getInventoryWidgets(): array
    {
        return [
            BulkWineStatsWidget::class,
            LowStockSkusTableWidget::class,
            PendingPurchaseOrdersStatsWidget::class,
        ];
    }

    /**
     * @return array<class-string<Widget>>
     */
    private function getOverviewWidgets(): array
    {
        return [
            BulkWineStatsWidget::class,
            OpenWorkOrdersStatsWidget::class,
            OverviewProductionChart::class,
        ];
    }
}
