<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AdditionResource\Pages;
use App\Models\Addition;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AdditionResource extends Resource
{
    protected static ?string $model = Addition::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static \UnitEnum|string|null $navigationGroup = 'Production';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Addition Details')
                    ->schema([
                        Forms\Components\Select::make('lot_id')
                            ->relationship('lot', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('vessel_id')
                            ->relationship('vessel', 'name')
                            ->nullable()
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('addition_type')
                            ->options([
                                'sulfite' => 'Sulfite',
                                'nutrient' => 'Nutrient',
                                'fining' => 'Fining',
                                'acid' => 'Acid',
                                'enzyme' => 'Enzyme',
                                'tannin' => 'Tannin',
                                'other' => 'Other',
                            ])
                            ->required(),

                        Forms\Components\TextInput::make('product_name')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('rate')
                            ->numeric()
                            ->required()
                            ->step(0.0001),

                        Forms\Components\Select::make('rate_unit')
                            ->options([
                                'ppm' => 'ppm',
                                'g/L' => 'g/L',
                                'mg/L' => 'mg/L',
                                'g/hL' => 'g/hL',
                                'lb/1000gal' => 'lb/1000gal',
                                'mL/L' => 'mL/L',
                            ])
                            ->required(),

                        Forms\Components\TextInput::make('total_amount')
                            ->numeric()
                            ->required()
                            ->step(0.0001),

                        Forms\Components\Select::make('total_unit')
                            ->options([
                                'g' => 'g',
                                'kg' => 'kg',
                                'lb' => 'lb',
                                'oz' => 'oz',
                                'mL' => 'mL',
                                'L' => 'L',
                                'gal' => 'gal',
                            ])
                            ->required(),

                        Forms\Components\Textarea::make('reason')
                            ->nullable()
                            ->columnSpanFull(),

                        Forms\Components\DateTimePicker::make('performed_at')
                            ->required(),
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

                Tables\Columns\TextColumn::make('addition_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sulfite' => 'danger', 'nutrient' => 'info', 'fining' => 'warning', 'acid' => 'secondary', 'enzyme' => 'success', 'tannin' => 'primary', 'other' => 'gray', default => 'gray'
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('product_name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('rate')
                    ->getStateUsing(fn (Addition $record): string => $record->rate.' '.$record->rate_unit)
                    ->label('Rate'),

                Tables\Columns\TextColumn::make('total_amount')
                    ->getStateUsing(fn (Addition $record): string => $record->total_amount.' '.$record->total_unit)
                    ->label('Total'),

                Tables\Columns\TextColumn::make('performer.name')
                    ->label('Performed By')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('performed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('addition_type')
                    ->options([
                        'sulfite' => 'Sulfite',
                        'nutrient' => 'Nutrient',
                        'fining' => 'Fining',
                        'acid' => 'Acid',
                        'enzyme' => 'Enzyme',
                        'tannin' => 'Tannin',
                        'other' => 'Other',
                    ]),

                Tables\Filters\SelectFilter::make('lot')
                    ->relationship('lot', 'name')
                    ->searchable(),

                Tables\Filters\Filter::make('performed_at')
                    ->form([
                        Forms\Components\DatePicker::make('performed_from'),
                        Forms\Components\DatePicker::make('performed_until'),
                    ])
                    ->query(function ($query, array $data): void {
                        $query
                            ->when(
                                $data['performed_from'],
                                fn ($query, $date) => $query->whereDate('performed_at', '>=', $date),
                            )
                            ->when(
                                $data['performed_until'],
                                fn ($query, $date) => $query->whereDate('performed_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([
                // No bulk delete due to immutability
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdditions::route('/'),
            'create' => Pages\CreateAddition::route('/create'),
            'view' => Pages\ViewAddition::route('/{record}'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
