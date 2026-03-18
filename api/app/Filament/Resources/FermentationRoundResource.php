<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\FermentationRoundResource\Pages;
use App\Models\FermentationRound;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class FermentationRoundResource extends Resource
{
    protected static ?string $model = FermentationRound::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-fire';

    protected static \UnitEnum|string|null $navigationGroup = 'Lab';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Fermentation';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Round Details')
                    ->schema([
                        Forms\Components\Select::make('lot_id')
                            ->relationship('lot', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('fermentation_type')
                            ->options([
                                'primary' => 'Primary Fermentation',
                                'malolactic' => 'Malolactic Fermentation',
                            ])
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('round_number')
                            ->numeric()
                            ->required()
                            ->default(1)
                            ->minValue(1),

                        Forms\Components\DatePicker::make('inoculation_date')
                            ->required()
                            ->default(now()),

                        Forms\Components\TextInput::make('yeast_strain')
                            ->maxLength(100)
                            ->visible(fn (Get $get): bool => $get('fermentation_type') === 'primary'),

                        Forms\Components\TextInput::make('ml_bacteria')
                            ->label('ML Bacteria Strain')
                            ->maxLength(100)
                            ->visible(fn (Get $get): bool => $get('fermentation_type') === 'malolactic'),

                        Forms\Components\TextInput::make('target_temp')
                            ->label('Target Temp (°F)')
                            ->numeric()
                            ->step(0.1),

                        Forms\Components\Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'completed' => 'Completed',
                                'stuck' => 'Stuck',
                            ])
                            ->default('active'),

                        Forms\Components\DatePicker::make('completion_date')
                            ->visible(fn (Get $get): bool => $get('status') === 'completed'),

                        Forms\Components\DatePicker::make('confirmation_date')
                            ->label('ML Dryness Confirmation')
                            ->visible(fn (Get $get): bool => $get('fermentation_type') === 'malolactic'),

                        Forms\Components\Textarea::make('notes')
                            ->nullable()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('lot.name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('fermentation_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'primary' => 'info', 'malolactic' => 'warning', default => 'gray'
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'primary' => 'Primary',
                        'malolactic' => 'ML',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('round_number')
                    ->label('Round #')
                    ->sortable(),

                Tables\Columns\TextColumn::make('inoculation_date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('yeast_strain')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ml_bacteria')
                    ->label('ML Bacteria')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('target_temp')
                    ->label('Target °F')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success', 'active' => 'warning', 'stuck' => 'danger', default => 'gray'
                    }),

                Tables\Columns\TextColumn::make('entries_count')
                    ->counts('entries')
                    ->label('Entries'),

                Tables\Columns\TextColumn::make('completion_date')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('inoculation_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('fermentation_type')
                    ->options([
                        'primary' => 'Primary',
                        'malolactic' => 'Malolactic',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'completed' => 'Completed',
                        'stuck' => 'Stuck',
                    ]),

                Tables\Filters\SelectFilter::make('lot')
                    ->relationship('lot', 'name')
                    ->searchable(),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFermentationRounds::route('/'),
            'create' => Pages\CreateFermentationRound::route('/create'),
            'view' => Pages\ViewFermentationRound::route('/{record}'),
            'edit' => Pages\EditFermentationRound::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
