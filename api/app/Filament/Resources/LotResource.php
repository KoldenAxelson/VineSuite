<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LotResource\Pages;
use App\Models\Lot;
use App\Services\LotService;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Production lot management resource.
 *
 * Full CRUD for wine lots with timeline view of events,
 * filters by variety/vintage/status, and bulk status updates.
 */
class LotResource extends Resource
{
    protected static ?string $model = Lot::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-beaker';

    protected static \UnitEnum|string|null $navigationGroup = 'Production';

    protected static ?string $navigationLabel = 'Lots';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Lot';

    protected static ?string $pluralModelLabel = 'Lots';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Lot Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('variety')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('vintage')
                            ->required()
                            ->numeric()
                            ->minValue(1900)
                            ->maxValue(2099),
                        Forms\Components\Select::make('source_type')
                            ->options([
                                'estate' => 'Estate',
                                'purchased' => 'Purchased',
                            ])
                            ->required()
                            ->native(false),
                        Forms\Components\TextInput::make('volume_gallons')
                            ->label('Volume (gallons)')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(999999.9999),
                        Forms\Components\Select::make('status')
                            ->options(collect(Lot::STATUSES)->mapWithKeys(fn (string $s) => [$s => ucfirst(str_replace('_', ' ', $s))]))
                            ->required()
                            ->native(false),
                    ])
                    ->columns(2),

                Section::make('Source Details')
                    ->schema([
                        Forms\Components\KeyValue::make('source_details')
                            ->label('Source metadata')
                            ->keyLabel('Field')
                            ->valueLabel('Value'),
                    ])
                    ->collapsible()
                    ->collapsed(),
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
                Tables\Columns\TextColumn::make('variety')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vintage')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state, Lot $record): string => $record->statusColor())
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),
                Tables\Columns\TextColumn::make('volume_gallons')
                    ->label('Volume (gal)')
                    ->numeric(4)
                    ->sortable(),
                Tables\Columns\TextColumn::make('source_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'estate' => 'success', 'purchased' => 'info', default => 'gray'
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(Lot::STATUSES)->mapWithKeys(fn (string $s) => [$s => ucfirst(str_replace('_', ' ', $s))])),
                Tables\Filters\SelectFilter::make('variety')
                    ->options(fn () => Lot::query()->distinct()->pluck('variety', 'variety')->toArray())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('vintage')
                    ->options(fn () => Lot::query()->distinct()->orderByDesc('vintage')->pluck('vintage', 'vintage')->toArray()),
                Tables\Filters\SelectFilter::make('source_type')
                    ->options([
                        'estate' => 'Estate',
                        'purchased' => 'Purchased',
                    ]),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkAction::make('archive')
                    ->label('Archive Selected')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function ($records): void {
                        $lotService = app(LotService::class);
                        $userId = auth()->id();
                        $records->each(fn (Lot $lot) => $lotService->updateLot(
                            $lot,
                            ['status' => 'archived'],
                            $userId,
                        ));
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Lot Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')->weight('bold'),
                        Infolists\Components\TextEntry::make('variety'),
                        Infolists\Components\TextEntry::make('vintage'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state, Lot $record): string => $record->statusColor()),
                        Infolists\Components\TextEntry::make('volume_gallons')
                            ->label('Volume (gallons)')
                            ->numeric(4),
                        Infolists\Components\TextEntry::make('source_type')
                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                    ])
                    ->columns(3),

                Section::make('Event Timeline')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('events')
                            ->schema([
                                Infolists\Components\TextEntry::make('operation_type')
                                    ->label('Operation')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'addition' => 'info',
                                        'transfer' => 'warning',
                                        'rack' => 'success',
                                        'bottle' => 'primary',
                                        'blend' => 'danger',
                                        default => 'secondary',
                                    }),
                                Infolists\Components\TextEntry::make('performed_at')
                                    ->label('When')
                                    ->dateTime(),
                                Infolists\Components\TextEntry::make('performer.name')
                                    ->label('By')
                                    ->placeholder('System'),
                                Infolists\Components\KeyValueEntry::make('payload')
                                    ->label('Details'),
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
            'index' => Pages\ListLots::route('/'),
            'create' => Pages\CreateLot::route('/create'),
            'view' => Pages\ViewLot::route('/{record}'),
            'edit' => Pages\EditLot::route('/{record}/edit'),
        ];
    }

    /**
     * Production resources accessible to all authenticated winery staff.
     */
    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
