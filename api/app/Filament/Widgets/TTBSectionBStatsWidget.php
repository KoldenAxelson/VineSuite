<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TTBSectionBStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    /** @var array<string, mixed> */
    public array $sectionData = [];

    protected function getHeading(): ?string
    {
        return 'Part I — Section B: Bottled Wines';
    }

    protected function getStats(): array
    {
        $data = $this->sectionData;

        if (empty($data)) {
            return [];
        }

        return [
            Stat::make('Opening Inventory', number_format($data['opening_inventory'] ?? 0))
                ->description('gallons'),

            Stat::make('Bottled (from bulk)', number_format($data['total_bottled'] ?? 0))
                ->description('gallons')
                ->color('info'),

            Stat::make('Total (Lines 1-6)', number_format($data['total_increases'] ?? 0))
                ->description('gallons'),

            Stat::make('Removed (Taxpaid)', number_format($data['total_removed_taxpaid'] ?? 0))
                ->description('gallons')
                ->color('warning'),

            Stat::make('Closing Inventory', number_format($data['closing_inventory'] ?? 0))
                ->description('gallons'),

            Stat::make('Balance', ($data['balanced'] ?? false) ? 'Verified' : 'ERROR')
                ->color(($data['balanced'] ?? false) ? 'success' : 'danger')
                ->icon(($data['balanced'] ?? false) ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'),
        ];
    }

    protected function getColumns(): int
    {
        return 3;
    }
}
