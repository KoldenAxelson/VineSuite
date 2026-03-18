<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\License;
use App\Models\TTBReport;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class ComplianceStatusStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        // Next TTB report due: first day of next month after the latest report period
        $latestReport = TTBReport::orderByDesc('report_period_year')
            ->orderByDesc('report_period_month')
            ->first();

        if ($latestReport) {
            $nextDue = Carbon::create($latestReport->report_period_year, $latestReport->report_period_month, 1)
                ->addMonth()
                ->endOfMonth();
            $nextDueLabel = $nextDue->format('M j, Y');
            $daysUntilDue = (int) now()->diffInDays($nextDue, false);
            $dueColor = $daysUntilDue < 7 ? 'danger' : ($daysUntilDue < 14 ? 'warning' : 'success');
        } else {
            $nextDueLabel = 'No reports yet';
            $dueColor = 'gray';
        }

        $draftReports = TTBReport::where('status', 'draft')->count();

        $expiringLicenses = License::where('expiration_date', '<=', Carbon::now()->addDays(90))
            ->where('expiration_date', '>=', Carbon::today())
            ->count();

        $filedThisYear = TTBReport::where('status', 'filed')
            ->where('report_period_year', Carbon::now()->year)
            ->count();

        return [
            Stat::make('Next TTB Due', $nextDueLabel)
                ->icon('heroicon-o-calendar')
                ->color($dueColor),

            Stat::make('Draft Reports', (string) $draftReports)
                ->icon('heroicon-o-document-text')
                ->color($draftReports > 0 ? 'warning' : 'success'),

            Stat::make('Expiring Licenses', (string) $expiringLicenses)
                ->description('within 90 days')
                ->icon('heroicon-o-shield-exclamation')
                ->color($expiringLicenses > 0 ? 'danger' : 'success'),

            Stat::make('Filed This Year', (string) $filedThisYear)
                ->icon('heroicon-o-check-badge')
                ->color('success'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
