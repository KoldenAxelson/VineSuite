<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Event;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentActivityTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Recent Activity';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Event::query()
                    ->with('performer')
                    ->orderByDesc('performed_at')
                    ->limit(15)
            )
            ->columns([
                Tables\Columns\TextColumn::make('performed_at')
                    ->label('When')
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('event_source')
                    ->label('Source')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'production' => 'info',
                        'lab' => 'warning',
                        'inventory' => 'success',
                        'accounting' => 'gray',
                        'compliance' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('entity_type')
                    ->label('Entity')
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                Tables\Columns\TextColumn::make('operation_type')
                    ->label('Action')
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                Tables\Columns\TextColumn::make('performer.name')
                    ->label('By')
                    ->default('System'),
            ])
            ->paginated(false)
            ->emptyStateHeading('No recent activity')
            ->emptyStateDescription('Events will appear here as operations are performed.')
            ->emptyStateIcon('heroicon-o-clock');
    }
}
