<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\FermentationEntry;
use App\Models\FermentationRound;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * FermentationCurveChart — Apex Charts widget for fermentation curve visualization.
 *
 * Renders a dual-axis chart:
 *   - Left Y axis: Brix (or specific gravity)
 *   - Right Y axis: Temperature (°F)
 *   - X axis: Date
 *
 * Used on the FermentationRound view page to visualize daily fermentation progress.
 */
class FermentationCurveChart extends ApexChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'fermentationCurve';

    protected static ?string $heading = 'Fermentation Curve';

    protected static ?int $contentHeight = 350;

    public ?string $roundId = null;

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        if (! $this->roundId) {
            return $this->emptyChart();
        }

        $round = FermentationRound::with('lot')->find($this->roundId);

        if (! $round) {
            return $this->emptyChart();
        }

        $entries = FermentationEntry::where('fermentation_round_id', $this->roundId)
            ->orderBy('entry_date')
            ->orderBy('created_at')
            ->get();

        if ($entries->isEmpty()) {
            return $this->emptyChart();
        }

        $labels = $entries->map(fn (FermentationEntry $e) => $e->entry_date->format('M j'))->values()->toArray();
        $brixData = $entries->map(fn (FermentationEntry $e) => $e->brix_or_density !== null ? (float) $e->brix_or_density : null)->values()->toArray();
        $tempData = $entries->map(fn (FermentationEntry $e) => $e->temperature !== null ? (float) $e->temperature : null)->values()->toArray();

        $measurementTypes = $entries->pluck('measurement_type')->filter()->unique();
        $leftLabel = $measurementTypes->count() === 1 && $measurementTypes->first() === 'specific_gravity'
            ? 'Specific Gravity'
            : 'Brix';

        $series = [
            [
                'name' => $leftLabel,
                'data' => $brixData,
                'type' => 'line',
            ],
            [
                'name' => 'Temperature (°F)',
                'data' => $tempData,
                'type' => 'line',
            ],
        ];

        // Add target temp as a dashed line if set
        if ($round->target_temp !== null) {
            $series[] = [
                'name' => 'Target Temp',
                'data' => array_fill(0, count($labels), (float) $round->target_temp),
                'type' => 'line',
            ];
        }

        return [
            'chart' => [
                'type' => 'line',
                'height' => 350,
                'toolbar' => ['show' => true],
            ],
            'series' => $series,
            'xaxis' => [
                'categories' => $labels,
                'labels' => ['style' => ['fontFamily' => 'inherit']],
            ],
            'yaxis' => [
                [
                    'title' => ['text' => $leftLabel],
                    'labels' => ['style' => ['fontFamily' => 'inherit']],
                ],
                [
                    'opposite' => true,
                    'title' => ['text' => 'Temperature (°F)'],
                    'labels' => ['style' => ['fontFamily' => 'inherit']],
                ],
            ],
            'stroke' => [
                'curve' => 'smooth',
                'width' => [3, 3, 2],
                'dashArray' => $round->target_temp !== null ? [0, 0, 5] : [0, 0],
            ],
            'colors' => $round->target_temp !== null
                ? ['#7c3aed', '#f59e0b', '#94a3b8']
                : ['#7c3aed', '#f59e0b'],
            'legend' => [
                'position' => 'top',
                'labels' => ['useSeriesColors' => true],
            ],
            'tooltip' => [
                'shared' => true,
                'intersect' => false,
            ],
            'title' => [
                'text' => sprintf(
                    '%s — Round %d %s',
                    $round->lot->name ?? 'Unknown Lot',
                    $round->round_number,
                    $round->fermentation_type === 'primary' ? '(Primary)' : '(ML)',
                ),
                'style' => ['fontFamily' => 'inherit', 'fontWeight' => 600],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyChart(): array
    {
        return [
            'chart' => ['type' => 'line', 'height' => 350],
            'series' => [],
            'xaxis' => ['categories' => []],
            'noData' => ['text' => 'No fermentation data recorded yet.'],
        ];
    }
}
