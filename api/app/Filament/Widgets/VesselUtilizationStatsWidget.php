<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Vessel;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class VesselUtilizationStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $vessels = Vessel::whereIn('status', ['active', 'in_use'])->get();

        $totalCapacity = $vessels->sum('capacity_gallons');

        $currentVolume = DB::table('lot_vessel')
            ->whereNull('emptied_at')
            ->sum('volume_gallons');

        $fillPercent = $totalCapacity > 0
            ? round(((float) $currentVolume / (float) $totalCapacity) * 100, 1)
            : 0;

        $atCapacity = DB::table('vessels')
            ->whereIn('status', ['active', 'in_use'])
            ->where('capacity_gallons', '>', 0)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('lot_vessel')
                    ->whereColumn('lot_vessel.vessel_id', 'vessels.id')
                    ->whereNull('lot_vessel.emptied_at')
                    ->havingRaw('SUM(lot_vessel.volume_gallons) >= vessels.capacity_gallons * 0.95')
                    ->groupBy('lot_vessel.vessel_id');
            })
            ->count();

        $emptyVessels = DB::table('vessels')
            ->whereIn('status', ['active', 'in_use'])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('lot_vessel')
                    ->whereColumn('lot_vessel.vessel_id', 'vessels.id')
                    ->whereNull('lot_vessel.emptied_at');
            })
            ->count();

        return [
            Stat::make('Total Capacity', number_format((float) $totalCapacity, 0).' gal')
                ->icon('heroicon-o-cube'),

            Stat::make('Fill Rate', $fillPercent.'%')
                ->icon('heroicon-o-chart-bar')
                ->color($fillPercent > 90 ? 'warning' : 'primary'),

            Stat::make('At Capacity', (string) $atCapacity)
                ->description('≥ 95% full')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($atCapacity > 0 ? 'warning' : 'success'),

            Stat::make('Empty', (string) $emptyVessels)
                ->description('available vessels')
                ->icon('heroicon-o-inbox'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
