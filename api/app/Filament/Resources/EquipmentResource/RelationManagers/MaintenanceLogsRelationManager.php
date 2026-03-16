<?php

declare(strict_types=1);

namespace App\Filament\Resources\EquipmentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MaintenanceLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenanceLogs';

    protected static ?string $title = 'Maintenance History';

    protected static ?string $recordTitleAttribute = 'description';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                Tables\Columns\TextColumn::make('performed_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('maintenance_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', ucfirst($state)))
                    ->color(fn (string $state) => match ($state) {
                        'calibration' => 'info',
                        'cleaning', 'cip' => 'success',
                        'repair' => 'danger',
                        'inspection' => 'warning',
                        'preventive' => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->searchable(),

                Tables\Columns\TextColumn::make('performer.name')
                    ->label('Performed By')
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('passed')
                    ->label('Pass/Fail')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('cost')
                    ->label('Cost')
                    ->money('USD')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('next_due_date')
                    ->label('Next Due')
                    ->date()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('findings')
                    ->label('Findings')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('performed_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('maintenance_type')
                    ->label('Type')
                    ->options([
                        'cleaning' => 'Cleaning',
                        'cip' => 'CIP',
                        'calibration' => 'Calibration',
                        'repair' => 'Repair',
                        'inspection' => 'Inspection',
                        'preventive' => 'Preventive',
                    ]),
            ]);
    }
}
