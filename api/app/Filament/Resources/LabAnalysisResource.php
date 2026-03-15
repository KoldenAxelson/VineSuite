<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LabAnalysisResource\Pages;
use App\Models\LabAnalysis;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LabAnalysisResource extends Resource
{
    protected static ?string $model = LabAnalysis::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Lab';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Lab Analyses';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Analysis Details')
                    ->schema([
                        Forms\Components\Select::make('lot_id')
                            ->relationship('lot', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\DatePicker::make('test_date')
                            ->required()
                            ->default(now()),

                        Forms\Components\Select::make('test_type')
                            ->options(array_combine(
                                LabAnalysis::TEST_TYPES,
                                array_map(fn (string $t): string => match ($t) {
                                    'pH' => 'pH',
                                    'TA' => 'Titratable Acidity (TA)',
                                    'VA' => 'Volatile Acidity (VA)',
                                    'free_SO2' => 'Free SO₂',
                                    'total_SO2' => 'Total SO₂',
                                    'residual_sugar' => 'Residual Sugar',
                                    'alcohol' => 'Alcohol',
                                    'malic_acid' => 'Malic Acid',
                                    'glucose_fructose' => 'Glucose + Fructose',
                                    'turbidity' => 'Turbidity',
                                    default => ucfirst($t),
                                }, LabAnalysis::TEST_TYPES),
                            ))
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                if ($state && isset(LabAnalysis::DEFAULT_UNITS[$state])) {
                                    $set('unit', LabAnalysis::DEFAULT_UNITS[$state]);
                                }
                            }),

                        Forms\Components\TextInput::make('value')
                            ->numeric()
                            ->required()
                            ->step(0.000001),

                        Forms\Components\TextInput::make('unit')
                            ->required()
                            ->maxLength(30),

                        Forms\Components\TextInput::make('method')
                            ->nullable()
                            ->maxLength(100),

                        Forms\Components\TextInput::make('analyst')
                            ->nullable()
                            ->maxLength(255),

                        Forms\Components\Select::make('source')
                            ->options([
                                'manual' => 'Manual Entry',
                                'ets_labs' => 'ETS Labs',
                                'oenofoss' => 'OenoFoss',
                                'wine_scan' => 'Wine Scan',
                                'csv_import' => 'CSV Import',
                            ])
                            ->default('manual'),

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

                Tables\Columns\TextColumn::make('test_date')
                    ->date()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('test_type')
                    ->colors([
                        'info' => 'pH',
                        'warning' => 'TA',
                        'danger' => 'VA',
                        'success' => fn (string $state): bool => in_array($state, ['free_SO2', 'total_SO2']),
                        'primary' => 'alcohol',
                        'secondary' => 'residual_sugar',
                        'gray' => fn (string $state): bool => ! in_array($state, ['pH', 'TA', 'VA', 'free_SO2', 'total_SO2', 'alcohol', 'residual_sugar']),
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pH' => 'pH',
                        'TA' => 'TA',
                        'VA' => 'VA',
                        'free_SO2' => 'Free SO₂',
                        'total_SO2' => 'Total SO₂',
                        'residual_sugar' => 'RS',
                        'alcohol' => 'Alcohol',
                        'malic_acid' => 'Malic',
                        'glucose_fructose' => 'G+F',
                        'turbidity' => 'Turbidity',
                        'color' => 'Color',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('value')
                    ->getStateUsing(fn (LabAnalysis $record): string => $record->value.' '.$record->unit)
                    ->label('Result'),

                Tables\Columns\TextColumn::make('method')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('analyst')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\BadgeColumn::make('source')
                    ->colors([
                        'gray' => 'manual',
                        'info' => fn (string $state): bool => $state !== 'manual',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'manual' => 'Manual',
                        'ets_labs' => 'ETS Labs',
                        'oenofoss' => 'OenoFoss',
                        'wine_scan' => 'Wine Scan',
                        'csv_import' => 'CSV Import',
                        default => $state,
                    }),
            ])
            ->defaultSort('test_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('test_type')
                    ->options(array_combine(
                        LabAnalysis::TEST_TYPES,
                        LabAnalysis::TEST_TYPES,
                    )),

                Tables\Filters\SelectFilter::make('lot')
                    ->relationship('lot', 'name')
                    ->searchable(),

                Tables\Filters\SelectFilter::make('source')
                    ->options([
                        'manual' => 'Manual',
                        'ets_labs' => 'ETS Labs',
                        'oenofoss' => 'OenoFoss',
                        'wine_scan' => 'Wine Scan',
                        'csv_import' => 'CSV Import',
                    ]),

                Tables\Filters\Filter::make('test_date')
                    ->form([
                        Forms\Components\DatePicker::make('date_from'),
                        Forms\Components\DatePicker::make('date_until'),
                    ])
                    ->query(function ($query, array $data): void {
                        $query
                            ->when(
                                $data['date_from'],
                                fn ($query, $date) => $query->whereDate('test_date', '>=', $date),
                            )
                            ->when(
                                $data['date_until'],
                                fn ($query, $date) => $query->whereDate('test_date', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLabAnalyses::route('/'),
            'create' => Pages\CreateLabAnalysis::route('/create'),
            'view' => Pages\ViewLabAnalysis::route('/{record}'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
