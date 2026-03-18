<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Illuminate\Support\Facades\DB;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * Overview production volume chart — visual filler for the Overview dashboard tab.
 *
 * TODO: Low priority. This chart is here mainly for aesthetics to fill out
 * the Overview tab. Replace with a more meaningful visualization when
 * higher-priority dashboard work is complete (e.g., monthly revenue,
 * club shipment trends, or tasting room traffic).
 */
class OverviewProductionChart extends ApexChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'overviewProduction';

    protected static ?string $heading = 'Production Volume by Vintage';

    protected static ?int $contentHeight = 300;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        $data = DB::table('lots')
            ->whereNotNull('vintage')
            ->whereIn('status', ['in_progress', 'aging', 'bottled', 'completed'])
            ->select('vintage')
            ->selectRaw('COUNT(*) as lot_count')
            ->selectRaw('COALESCE(SUM(volume_gallons), 0) as total_gallons')
            ->groupBy('vintage')
            ->orderBy('vintage')
            ->get();

        if ($data->isEmpty()) {
            return [
                'chart' => ['type' => 'bar', 'height' => 300],
                'series' => [],
                'xaxis' => ['categories' => []],
                'noData' => ['text' => 'No production data recorded yet.'],
            ];
        }

        $labels = $data->pluck('vintage')->map(fn ($v) => (string) $v)->values()->toArray();
        $volumes = $data->pluck('total_gallons')->map(fn ($v) => round((float) $v, 0))->values()->toArray();
        $lotCounts = $data->pluck('lot_count')->map(fn ($v) => (int) $v)->values()->toArray();

        return [
            'chart' => [
                'type' => 'bar',
                'height' => 300,
                'toolbar' => ['show' => false],
            ],
            'series' => [
                [
                    'name' => 'Volume (gal)',
                    'data' => $volumes,
                    'type' => 'bar',
                ],
                [
                    'name' => 'Lots',
                    'data' => $lotCounts,
                    'type' => 'line',
                ],
            ],
            'xaxis' => [
                'categories' => $labels,
                'labels' => ['style' => ['fontFamily' => 'inherit']],
            ],
            'yaxis' => [
                [
                    'title' => ['text' => 'Gallons'],
                    'labels' => ['style' => ['fontFamily' => 'inherit']],
                ],
                [
                    'opposite' => true,
                    'title' => ['text' => 'Lot Count'],
                    'labels' => ['style' => ['fontFamily' => 'inherit']],
                ],
            ],
            'plotOptions' => [
                'bar' => [
                    'borderRadius' => 4,
                    'columnWidth' => '60%',
                ],
            ],
            'colors' => ['#7c3aed', '#f59e0b'],
            'legend' => [
                'position' => 'top',
                'labels' => ['useSeriesColors' => true],
            ],
            'tooltip' => [
                'shared' => true,
                'intersect' => false,
            ],
            'dataLabels' => [
                'enabled' => false,
            ],
        ];
    }
}
