<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SensoryNoteResource\Pages;
use App\Models\SensoryNote;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SensoryNoteResource extends Resource
{
    protected static ?string $model = SensoryNote::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-beaker';

    protected static \UnitEnum|string|null $navigationGroup = 'Lab';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Tasting Notes';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Tasting Details')
                    ->schema([
                        Forms\Components\Select::make('lot_id')
                            ->relationship('lot', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\DatePicker::make('date')
                            ->required()
                            ->default(now()),

                        Forms\Components\Select::make('rating_scale')
                            ->options([
                                'five_point' => '5-Point Scale',
                                'hundred_point' => '100-Point Scale',
                            ])
                            ->default('five_point')
                            ->live(),

                        Forms\Components\TextInput::make('rating')
                            ->numeric()
                            ->step(0.1)
                            ->minValue(0)
                            ->maxValue(fn (Get $get): float => $get('rating_scale') === 'hundred_point' ? 100.0 : 5.0)
                            ->helperText(fn (Get $get): string => $get('rating_scale') === 'hundred_point'
                                ? 'Rate 0–100'
                                : 'Rate 0–5'),
                    ])
                    ->columns(2),

                Section::make('Tasting Notes')
                    ->schema([
                        Forms\Components\Textarea::make('nose_notes')
                            ->label('Nose / Aroma')
                            ->rows(3)
                            ->maxLength(5000)
                            ->placeholder('Describe aromas: fruit, floral, spice, oak, earth...'),

                        Forms\Components\Textarea::make('palate_notes')
                            ->label('Palate / Taste')
                            ->rows(3)
                            ->maxLength(5000)
                            ->placeholder('Describe mouthfeel, tannins, acidity, body, finish...'),

                        Forms\Components\Textarea::make('overall_notes')
                            ->label('Overall Impressions')
                            ->rows(3)
                            ->maxLength(5000)
                            ->placeholder('General assessment, development stage, recommendations...'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('lot.name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('taster.name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('rating')
                    ->formatStateUsing(function (?string $state, SensoryNote $record): string {
                        if ($state === null) {
                            return '—';
                        }
                        $max = $record->rating_scale === 'hundred_point' ? '100' : '5';

                        return "{$state}/{$max}";
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('nose_notes')
                    ->limit(50)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('palate_notes')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('overall_notes')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('lot')
                    ->relationship('lot', 'name')
                    ->searchable(),

                Tables\Filters\SelectFilter::make('taster')
                    ->relationship('taster', 'name')
                    ->searchable(),

                Tables\Filters\SelectFilter::make('rating_scale')
                    ->options([
                        'five_point' => '5-Point',
                        'hundred_point' => '100-Point',
                    ]),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSensoryNotes::route('/'),
            'create' => Pages\CreateSensoryNote::route('/create'),
            'view' => Pages\ViewSensoryNote::route('/{record}'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
