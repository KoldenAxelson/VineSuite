<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\WineryProfile;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * VineSuite portal dashboard.
 *
 * Placeholder — will display winery overview widgets, recent activity,
 * and quick action cards as modules are built.
 */
class Dashboard extends BaseDashboard
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -2;

    public function getHeading(): string
    {
        $profile = WineryProfile::first();

        return $profile ? "Welcome to {$profile->name}" : 'Dashboard';
    }

    public function getSubheading(): ?string
    {
        return 'Your winery at a glance';
    }
}
