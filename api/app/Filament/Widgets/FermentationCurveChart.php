<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\FermentationEntry;
use App\Models\FermentationRound;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

/**
 * FermentationCurveChart — custom Livewire widget for fermentation curve visualization.
 *
 * Renders a dual-axis Chart.js chart:
 *   - Left Y axis: Brix (or specific gravity)
 *   - Right Y axis: Temperature (°F)
 *   - X axis: Date
 *
 * Used on the FermentationRound view page to visualize daily fermentation progress.
 * The chart is rendered via Alpine.js + Chart.js CDN integration.
 */
class FermentationCurveChart extends Widget
{
    protected static string $view = 'filament.widgets.fermentation-curve-chart';

    /**
     * Prevent Filament from auto-discovering this widget on the Dashboard.
     * It is only used explicitly on the ViewFermentationRound page footer.
     */
    protected static bool $isDiscovered = false;

    public ?string $roundId = null;

    /**
     * @var array<string, mixed>
     */
    public array $chartData = [];

    public function mount(?string $roundId = null): void
    {
        $this->roundId = $roundId;

        if ($this->roundId) {
            $this->loadChartData();
        }
    }

    public function loadChartData(): void
    {
        if (! $this->roundId) {
            return;
        }

        $round = FermentationRound::with('lot')->find($this->roundId);

        if (! $round) {
            return;
        }

        /** @var Collection<int, FermentationEntry> $entries */
        $entries = FermentationEntry::where('fermentation_round_id', $this->roundId)
            ->orderBy('entry_date')
            ->orderBy('created_at')
            ->get();

        $labels = $entries->map(fn (FermentationEntry $e) => $e->entry_date->format('M j'))->values()->toArray();

        $brixData = $entries->map(fn (FermentationEntry $e) => $e->brix_or_density !== null ? (float) $e->brix_or_density : null
        )->values()->toArray();

        $tempData = $entries->map(fn (FermentationEntry $e) => $e->temperature !== null ? (float) $e->temperature : null
        )->values()->toArray();

        // Determine measurement label
        $measurementTypes = $entries->pluck('measurement_type')->filter()->unique();
        $leftLabel = 'Brix';
        if ($measurementTypes->count() === 1 && $measurementTypes->first() === 'specific_gravity') {
            $leftLabel = 'Specific Gravity';
        }

        $this->chartData = [
            'labels' => $labels,
            'brix' => $brixData,
            'temperature' => $tempData,
            'leftLabel' => $leftLabel,
            'title' => sprintf(
                '%s — Round %d %s',
                $round->lot->name ?? 'Unknown Lot',
                $round->round_number,
                $round->fermentation_type === 'primary' ? '(Primary)' : '(ML)',
            ),
            'targetTemp' => $round->target_temp !== null ? (float) $round->target_temp : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'chartData' => $this->chartData,
            'hasData' => ! empty($this->chartData['labels'] ?? []),
        ];
    }
}
