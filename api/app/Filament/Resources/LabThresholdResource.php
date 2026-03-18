<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LabThresholdResource\Pages;
use App\Models\LabAnalysis;
use App\Models\LabThreshold;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class LabThresholdResource extends Resource
{
    protected static ?string $model = LabThreshold::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static \UnitEnum|string|null $navigationGroup = 'Lab';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Threshold Alerts';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Threshold Configuration')
                    ->schema([
                        Forms\Components\Select::make('test_type')
                            ->options(array_combine(
                                LabAnalysis::TEST_TYPES,
                                LabAnalysis::TEST_TYPES,
                            ))
                            ->required(),

                        Forms\Components\TextInput::make('variety')
                            ->nullable()
                            ->maxLength(100)
                            ->helperText('Leave blank to apply to all varieties'),

                        Forms\Components\TextInput::make('min_value')
                            ->numeric()
                            ->nullable()
                            ->step(0.000001)
                            ->helperText('Leave blank for no lower bound'),

                        Forms\Components\TextInput::make('max_value')
                            ->numeric()
                            ->nullable()
                            ->step(0.000001)
                            ->helperText('Leave blank for no upper bound'),

                        Forms\Components\Select::make('alert_level')
                            ->options([
                                'warning' => 'Warning',
                                'critical' => 'Critical',
                            ])
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('test_type')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('variety')
                    ->default('All varieties')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('min_value')
                    ->label('Min')
                    ->default('—'),

                Tables\Columns\TextColumn::make('max_value')
                    ->label('Max')
                    ->default('—'),

                Tables\Columns\TextColumn::make('alert_level')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'warning' => 'warning', 'critical' => 'danger', default => 'gray'
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
            ])
            ->defaultSort('test_type')
            ->filters([
                Tables\Filters\SelectFilter::make('test_type')
                    ->options(array_combine(
                        LabAnalysis::TEST_TYPES,
                        LabAnalysis::TEST_TYPES,
                    )),

                Tables\Filters\SelectFilter::make('alert_level')
                    ->options([
                        'warning' => 'Warning',
                        'critical' => 'Critical',
                    ]),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLabThresholds::route('/'),
            'create' => Pages\CreateLabThreshold::route('/create'),
            'edit' => Pages\EditLabThreshold::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
