<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class BulkWineStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $stats = DB::table('lot_vessel')
            ->whereNull('emptied_at')
            ->selectRaw('COUNT(DISTINCT lot_id) as active_lot_count')
            ->selectRaw('COUNT(DISTINCT vessel_id) as active_vessel_count')
            ->selectRaw('COALESCE(SUM(volume_gallons), 0) as total_gallons_in_vessels')
            ->first();

        $lotBookTotal = DB::table('lots')
            ->whereIn('status', ['in_progress', 'aging'])
            ->sum('volume_gallons');

        $vesselVol = (float) $stats->total_gallons_in_vessels;
        $variance = round((float) $lotBookTotal - $vesselVol, 4);
        $hasVariance = $variance != 0;

        return [
            Stat::make('Vessel Volume', number_format($vesselVol, 1).' gal')
                ->icon('heroicon-o-beaker'),

            Stat::make('Book Volume', number_format((float) $lotBookTotal, 1).' gal')
                ->icon('heroicon-o-book-open'),

            Stat::make('Variance', number_format($variance, 1).' gal')
                ->icon($hasVariance ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($hasVariance ? 'warning' : 'success'),

            Stat::make('Active Lots', (string) (int) $stats->active_lot_count)
                ->icon('heroicon-o-rectangle-stack'),

            Stat::make('Active Vessels', (string) (int) $stats->active_vessel_count)
                ->icon('heroicon-o-cube'),
        ];
    }

    protected function getColumns(): int
    {
        return 5;
    }
}
