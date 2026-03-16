<?php

declare(strict_types=1);

namespace App\Filament\Resources\LocationResource\RelationManagers;

use App\Filament\Resources\CaseGoodsSkuResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StockLevelsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockLevels';

    protected static ?string $title = 'Stock at This Location';

    protected static ?string $recordTitleAttribute = 'id';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('sku.wine_name')
                    ->label('Wine / SKU')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->url(fn ($record): ?string => $record->sku_id
                        ? CaseGoodsSkuResource::getUrl('view', ['record' => $record->sku_id])
                        : null
                    ),

                Tables\Columns\TextColumn::make('sku.varietal')
                    ->label('Varietal')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sku.format')
                    ->label('Format')
                    ->toggleable(),

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
            ->defaultSort('sku.wine_name')
            ->filters([
                Tables\Filters\Filter::make('in_stock')
                    ->label('In Stock Only')
                    ->query(fn ($query) => $query->where('on_hand', '>', 0)),
            ]);
    }
}
