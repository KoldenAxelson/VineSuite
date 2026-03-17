<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LaborRateResource\Pages;
use App\Models\LaborRate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LaborRateResource extends Resource
{
    protected static ?string $model = LaborRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Labor Rates';

    protected static ?string $modelLabel = 'Labor Rate';

    protected static ?string $pluralModelLabel = 'Labor Rates';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Rate Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('role')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('e.g., cellar_hand, winemaker, forklift_operator'),

                        Forms\Components\TextInput::make('hourly_rate')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->prefix('$')
                            ->suffix('/hr'),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('role')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state))),

                Tables\Columns\TextColumn::make('hourly_rate')
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
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All')
                    ->trueLabel('Active Only')
                    ->falseLabel('Inactive Only'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLaborRates::route('/'),
            'create' => Pages\CreateLaborRate::route('/create'),
            'edit' => Pages\EditLaborRate::route('/{record}/edit'),
        ];
    }
}
