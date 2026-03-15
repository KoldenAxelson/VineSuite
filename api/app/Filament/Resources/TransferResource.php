<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TransferResource\Pages;
use App\Models\Transfer;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransferResource extends Resource
{
    protected static ?string $model = Transfer::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Production';

    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Transfer Details')
                    ->schema([
                        Select::make('lot_id')
                            ->relationship('lot', 'name')
                            ->required(),
                        Select::make('from_vessel_id')
                            ->relationship('fromVessel', 'name')
                            ->label('From')
                            ->required(),
                        Select::make('to_vessel_id')
                            ->relationship('toVessel', 'name')
                            ->label('To')
                            ->required(),
                        TextInput::make('volume_gallons')
                            ->numeric()
                            ->required(),
                        Select::make('transfer_type')
                            ->options([
                                'gravity' => 'Gravity',
                                'pump' => 'Pump',
                                'filter' => 'Filter',
                                'press' => 'Press',
                            ])
                            ->required(),
                        TextInput::make('variance_gallons')
                            ->numeric()
                            ->default(0),
                        DateTimePicker::make('performed_at')
                            ->required(),
                        Textarea::make('notes')
                            ->nullable(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('lot.name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('fromVessel.name')
                    ->label('From')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('toVessel.name')
                    ->label('To')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('volume_gallons')
                    ->numeric()
                    ->sortable(),
                BadgeColumn::make('transfer_type')
                    ->sortable(),
                TextColumn::make('variance_gallons')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('performer.name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('performed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('transfer_type')
                    ->options([
                        'gravity' => 'Gravity',
                        'pump' => 'Pump',
                        'filter' => 'Filter',
                        'press' => 'Press',
                    ]),
                SelectFilter::make('lot')
                    ->relationship('lot', 'name'),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransfers::route('/'),
            'create' => Pages\CreateTransfer::route('/create'),
            'view' => Pages\ViewTransfer::route('/{record}'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
