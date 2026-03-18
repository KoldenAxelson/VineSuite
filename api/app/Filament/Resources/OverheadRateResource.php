<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OverheadRateResource\Pages;
use App\Models\OverheadRate;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class OverheadRateResource extends Resource
{
    protected static ?string $model = OverheadRate::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-calculator';

    protected static \UnitEnum|string|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Overhead Rates';

    protected static ?string $modelLabel = 'Overhead Rate';

    protected static ?string $pluralModelLabel = 'Overhead Rates';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Rate Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(150)
                            ->placeholder('e.g., Winery Rent, Utilities, Insurance'),

                        Forms\Components\Select::make('allocation_method')
                            ->options([
                                'per_gallon' => 'Per Gallon',
                                'per_case' => 'Per Case',
                                'per_labor_hour' => 'Per Labor Hour',
                            ])
                            ->required()
                            ->helperText('How this overhead cost is spread across lots'),

                        Forms\Components\TextInput::make('rate')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->prefix('$')
                            ->helperText('Cost per unit of the allocation method'),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->helperText('Only active rates are used in allocation runs'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('allocation_method')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'per_gallon' => 'Per Gallon',
                        'per_case' => 'Per Case',
                        'per_labor_hour' => 'Per Labor Hour',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'per_gallon' => 'info',
                        'per_case' => 'success',
                        'per_labor_hour' => 'warning',
                        default => 'secondary',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('rate')
                    ->money('usd')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('allocation_method')
                    ->options([
                        'per_gallon' => 'Per Gallon',
                        'per_case' => 'Per Case',
                        'per_labor_hour' => 'Per Labor Hour',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All')
                    ->trueLabel('Active Only')
                    ->falseLabel('Inactive Only'),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOverheadRates::route('/'),
            'create' => Pages\CreateOverheadRate::route('/create'),
            'edit' => Pages\EditOverheadRate::route('/{record}/edit'),
        ];
    }
}
