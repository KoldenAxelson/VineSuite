<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaseGoodsSkuResource\RelationManagers;

use App\Filament\Resources\LocationResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StockLevelsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockLevels';

    protected static ?string $title = 'Stock by Location';

    protected static ?string $recordTitleAttribute = 'id';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Location')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->url(fn ($record): ?string => $record->location_id
                        ? LocationResource::getUrl('view', ['record' => $record->location_id])
                        : null
                    ),

                Tables\Columns\TextColumn::make('location.location_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', ucfirst($state))),

                Tables\Columns\TextColumn::make('on_hand')
                    ->label('On Hand')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('committed')
                    ->label('Committed')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('available')
                    ->label('Available')
                    ->numeric()
                    ->sortable()
                    ->color(fn ($record): string => $record->available < 0 ? 'danger' : 'success'),
            ])
            ->defaultSort('location.name')
            ->filters([
                Tables\Filters\Filter::make('in_stock')
                    ->label('In Stock Only')
                    ->query(fn ($query) => $query->where('on_hand', '>', 0)),
            ]);
    }
}
