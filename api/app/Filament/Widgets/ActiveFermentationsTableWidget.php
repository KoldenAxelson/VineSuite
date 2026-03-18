<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\FermentationRound;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ActiveFermentationsTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Active Fermentations';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                FermentationRound::query()
                    ->where('status', 'active')
                    ->with(['lot', 'entries' => fn ($q) => $q->orderByDesc('entry_date')->limit(1)])
            )
            ->columns([
                Tables\Columns\TextColumn::make('lot.name')
                    ->label('Lot')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('lot.variety')
                    ->label('Variety'),

                Tables\Columns\TextColumn::make('fermentation_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'primary' => 'info',
                        'malolactic' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'primary' => 'Primary',
                        'malolactic' => 'ML',
                        default => ucfirst($state),
                    }),

                Tables\Columns\TextColumn::make('yeast_strain')
                    ->label('Yeast/Bacteria')
                    ->limit(20),

                Tables\Columns\TextColumn::make('inoculation_date')
                    ->label('Days Active')
                    ->getStateUsing(fn (FermentationRound $record): string => (string) $record->inoculation_date->diffInDays(now()))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('latest_brix')
                    ->label('Latest Brix')
                    ->getStateUsing(function (FermentationRound $record): string {
                        $entry = $record->entries->first();

                        return $entry && $entry->brix_or_density !== null
                            ? number_format((float) $entry->brix_or_density, 1)
                            : '—';
                    })
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('latest_temp')
                    ->label('Temp (°F)')
                    ->getStateUsing(function (FermentationRound $record): string {
                        $entry = $record->entries->first();

                        return $entry && $entry->temperature !== null
                            ? number_format((float) $entry->temperature, 1)
                            : '—';
                    })
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->defaultSort('inoculation_date', 'asc')
            ->emptyStateHeading('No active fermentations')
            ->emptyStateDescription('Fermentation rounds with "active" status will appear here.')
            ->emptyStateIcon('heroicon-o-beaker');
    }
}
