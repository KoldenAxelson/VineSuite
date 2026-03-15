<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\VesselResource\Pages;
use App\Models\Vessel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Vessel management resource.
 *
 * Manages tanks, barrels, flexitanks and other containers.
 * Shows current contents with fill percentage and lot assignments.
 */
class VesselResource extends Resource
{
    protected static ?string $model = Vessel::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Production';

    protected static ?string $navigationLabel = 'Vessels';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Vessel';

    protected static ?string $pluralModelLabel = 'Vessels';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Vessel Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('type')
                            ->options(collect(Vessel::TYPES)->mapWithKeys(fn (string $t) => [$t => ucfirst(str_replace('_', ' ', $t))]))
                            ->required()
                            ->native(false),
                        Forms\Components\TextInput::make('capacity_gallons')
                            ->label('Capacity (gallons)')
                            ->required()
                            ->numeric()
                            ->minValue(0.0001)
                            ->maxValue(999999.9999),
                        Forms\Components\TextInput::make('material')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('location')
                            ->maxLength(255),
                        Forms\Components\Select::make('status')
                            ->options(collect(Vessel::STATUSES)->mapWithKeys(fn (string $s) => [$s => ucfirst(str_replace('_', ' ', $s))]))
                            ->required()
                            ->native(false),
                        Forms\Components\DatePicker::make('purchase_date'),
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\BadgeColumn::make('type')
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('capacity_gallons')
                    ->label('Capacity (gal)')
                    ->numeric(4)
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_volume')
                    ->label('Current (gal)')
                    ->numeric(4)
                    ->sortable(false),
                Tables\Columns\TextColumn::make('fill_percent')
                    ->label('Fill %')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 1).'%')
                    ->color(fn ($state): string => match (true) {
                        (float) $state >= 90 => 'success',
                        (float) $state >= 50 => 'warning',
                        (float) $state > 0 => 'danger',
                        default => 'secondary',
                    })
                    ->sortable(false),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'in_use',
                        'secondary' => 'empty',
                        'warning' => 'cleaning',
                        'danger' => 'out_of_service',
                    ])
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),
                Tables\Columns\TextColumn::make('location')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('material')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(collect(Vessel::TYPES)->mapWithKeys(fn (string $t) => [$t => ucfirst(str_replace('_', ' ', $t))])),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(Vessel::STATUSES)->mapWithKeys(fn (string $s) => [$s => ucfirst(str_replace('_', ' ', $s))])),
                Tables\Filters\SelectFilter::make('location')
                    ->options(fn () => Vessel::query()->distinct()->whereNotNull('location')->pluck('location', 'location')->toArray())
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('name');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Vessel Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')->weight('bold'),
                        Infolists\Components\TextEntry::make('type')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),
                        Infolists\Components\TextEntry::make('capacity_gallons')
                            ->label('Capacity (gallons)')
                            ->numeric(4),
                        Infolists\Components\TextEntry::make('current_volume')
                            ->label('Current Volume (gallons)')
                            ->numeric(4),
                        Infolists\Components\TextEntry::make('fill_percent')
                            ->label('Fill %')
                            ->formatStateUsing(fn ($state): string => number_format((float) $state, 1).'%'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'in_use' => 'success',
                                'empty' => 'secondary',
                                'cleaning' => 'warning',
                                'out_of_service' => 'danger',
                                default => 'secondary',
                            }),
                        Infolists\Components\TextEntry::make('material'),
                        Infolists\Components\TextEntry::make('location'),
                        Infolists\Components\TextEntry::make('purchase_date')->date(),
                        Infolists\Components\TextEntry::make('notes')->columnSpanFull(),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Current Contents')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('lots')
                            ->schema([
                                Infolists\Components\TextEntry::make('name')
                                    ->label('Lot'),
                                Infolists\Components\TextEntry::make('variety'),
                                Infolists\Components\TextEntry::make('pivot.volume_gallons')
                                    ->label('Volume (gal)')
                                    ->numeric(4),
                                Infolists\Components\TextEntry::make('pivot.filled_at')
                                    ->label('Filled')
                                    ->dateTime(),
                            ])
                            ->columns(4),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVessels::route('/'),
            'create' => Pages\CreateVessel::route('/create'),
            'view' => Pages\ViewVessel::route('/{record}'),
            'edit' => Pages\EditVessel::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
