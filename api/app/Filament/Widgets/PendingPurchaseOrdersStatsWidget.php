<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\PurchaseOrder;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class PendingPurchaseOrdersStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $openOrders = PurchaseOrder::whereIn('status', ['ordered', 'partial'])->get();
        $openCount = $openOrders->count();
        $totalValue = $openOrders->sum('total_cost');

        $oldestOpen = $openOrders->min('order_date');
        $oldestDays = $oldestOpen
            ? (int) Carbon::parse($oldestOpen)->diffInDays(now())
            : 0;

        $expectedThisWeek = PurchaseOrder::whereIn('status', ['ordered', 'partial'])
            ->whereBetween('expected_date', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->count();

        return [
            Stat::make('Open POs', (string) $openCount)
                ->icon('heroicon-o-truck'),

            Stat::make('Total Value', '$'.number_format((float) $totalValue, 2))
                ->icon('heroicon-o-banknotes'),

            Stat::make('Oldest Open', $oldestDays > 0 ? $oldestDays.' days' : '—')
                ->icon('heroicon-o-clock')
                ->color($oldestDays > 30 ? 'warning' : 'gray'),

            Stat::make('Expected This Week', (string) $expectedThisWeek)
                ->icon('heroicon-o-calendar')
                ->color($expectedThisWeek > 0 ? 'info' : 'gray'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
