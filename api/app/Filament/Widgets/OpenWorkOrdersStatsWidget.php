<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\WorkOrder;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class OpenWorkOrdersStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $openCount = WorkOrder::whereIn('status', ['pending', 'in_progress'])->count();

        $overdueCount = WorkOrder::whereIn('status', ['pending', 'in_progress'])
            ->where('due_date', '<', Carbon::today())
            ->count();

        $completedToday = WorkOrder::where('status', 'completed')
            ->whereDate('completed_at', Carbon::today())
            ->count();

        $hoursThisWeek = WorkOrder::where('status', 'completed')
            ->whereBetween('completed_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->sum('hours');

        return [
            Stat::make('Open', (string) $openCount)
                ->icon('heroicon-o-clipboard-document-list')
                ->color($openCount > 10 ? 'warning' : 'primary'),

            Stat::make('Overdue', (string) $overdueCount)
                ->icon('heroicon-o-exclamation-triangle')
                ->color($overdueCount > 0 ? 'danger' : 'success'),

            Stat::make('Completed Today', (string) $completedToday)
                ->icon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Hours This Week', number_format((float) $hoursThisWeek, 1))
                ->icon('heroicon-o-clock'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
