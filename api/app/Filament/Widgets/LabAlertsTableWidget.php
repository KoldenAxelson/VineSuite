<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\LabAnalysis;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LabAlertsTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Lab Threshold Alerts';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                LabAnalysis::query()
                    ->join('lots', 'lab_analyses.lot_id', '=', 'lots.id')
                    ->join('lab_thresholds', function ($join) {
                        $join->on('lab_analyses.test_type', '=', 'lab_thresholds.test_type')
                            ->where(function ($q) {
                                $q->whereColumn('lab_thresholds.variety', 'lots.variety')
                                    ->orWhereNull('lab_thresholds.variety');
                            });
                    })
                    ->where(function ($q) {
                        $q->whereColumn('lab_analyses.value', '<', 'lab_thresholds.min_value')
                            ->orWhereColumn('lab_analyses.value', '>', 'lab_thresholds.max_value');
                    })
                    ->select([
                        'lab_analyses.*',
                        'lots.name as lot_name',
                        'lots.variety as lot_variety',
                        'lab_thresholds.min_value',
                        'lab_thresholds.max_value',
                        'lab_thresholds.alert_level',
                    ])
                    ->orderByDesc('lab_analyses.test_date')
            )
            ->columns([
                Tables\Columns\TextColumn::make('alert_level')
                    ->label('Level')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('lot_name')
                    ->label('Lot')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('test_type')
                    ->label('Parameter')
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                Tables\Columns\TextColumn::make('value')
                    ->label('Value')
                    ->numeric(2)
                    ->alignEnd()
                    ->weight('bold')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('threshold_range')
                    ->label('Threshold')
                    ->getStateUsing(function ($record): string {
                        $min = number_format((float) $record->min_value, 2);
                        $max = number_format((float) $record->max_value, 2);

                        return "{$min} – {$max}";
                    })
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('unit')
                    ->label('Unit'),

                Tables\Columns\TextColumn::make('test_date')
                    ->label('Date')
                    ->date('M j, Y'),
            ])
            ->paginated(false)
            ->emptyStateHeading('No threshold alerts')
            ->emptyStateDescription('Lab analyses within normal thresholds. All clear.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
