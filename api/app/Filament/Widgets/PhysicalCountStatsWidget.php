<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\PhysicalCount;
use App\Models\PhysicalCountLine;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PhysicalCountStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    public ?string $countId = null;

    protected function getStats(): array
    {
        if (! $this->countId) {
            return [];
        }

        $count = PhysicalCount::with(['location', 'starter', 'completer'])->find($this->countId);

        if (! $count) {
            return [];
        }

        $lines = PhysicalCountLine::where('physical_count_id', $count->id)->get();
        $counted = $lines->whereNotNull('counted_quantity')->count();
        $total = $lines->count();
        $varianceCount = $lines->where('variance', '!=', 0)->whereNotNull('variance')->count();

        $statusColor = match ($count->status) {
            'in_progress' => 'warning',
            'completed' => 'success',
            'cancelled' => 'danger',
            default => 'gray',
        };

        $stats = [
            Stat::make('Status', ucfirst(str_replace('_', ' ', $count->status)))
                ->color($statusColor),

            Stat::make('Progress', $counted.'/'.$total.' SKUs counted')
                ->icon('heroicon-o-clipboard-document-check'),

            Stat::make('Variances Found', (string) $varianceCount)
                ->color($varianceCount > 0 ? 'danger' : 'success')
                ->icon($varianceCount > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'),

            Stat::make('Started', $count->started_at->format('M j, Y g:i A'))
                ->description('by '.$count->starter->name),
        ];

        if ($count->completed_at) {
            $stats[] = Stat::make('Completed', $count->completed_at->format('M j, Y g:i A'))
                ->description('by '.$count->completer?->name)
                ->color('success');
        }

        return $stats;
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
