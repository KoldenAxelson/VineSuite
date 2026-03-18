<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\LotCogsSummary;
use App\Models\LotCostEntry;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CostReportsStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $cogsCount = LotCogsSummary::count();

        $avgCostPerBottle = $cogsCount > 0
            ? LotCogsSummary::whereNotNull('cost_per_bottle')->avg('cost_per_bottle')
            : null;

        $totalBottlesProduced = LotCogsSummary::sum('bottles_produced');
        $totalCostTracked = LotCostEntry::sum('amount');
        $lotsWithCosts = LotCostEntry::distinct('lot_id')->count('lot_id');

        return [
            Stat::make('COGS Summaries', (string) $cogsCount)
                ->icon('heroicon-o-calculator'),

            Stat::make('Avg $/Bottle', $avgCostPerBottle !== null
                ? '$'.number_format((float) $avgCostPerBottle, 2)
                : 'N/A')
                ->icon('heroicon-o-currency-dollar'),

            Stat::make('Bottles Produced', number_format($totalBottlesProduced))
                ->icon('heroicon-o-archive-box'),

            Stat::make('Total Cost Tracked', '$'.number_format((float) $totalCostTracked, 2))
                ->icon('heroicon-o-banknotes'),

            Stat::make('Lots with Costs', (string) $lotsWithCosts)
                ->icon('heroicon-o-rectangle-stack'),
        ];
    }

    protected function getColumns(): int
    {
        return 5;
    }
}
