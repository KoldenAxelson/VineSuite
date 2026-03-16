<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrderResource\RelationManagers;

use App\Filament\Resources\DryGoodsItemResource;
use App\Filament\Resources\RawMaterialResource;
use App\Models\PurchaseOrderLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Order Lines';

    protected static ?string $recordTitleAttribute = 'item_name';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('item_name')
            ->columns([
                Tables\Columns\TextColumn::make('item_name')
                    ->label('Item')
                    ->searchable()
                    ->weight('bold')
                    ->url(fn (PurchaseOrderLine $record): ?string => self::getInventoryItemUrl($record)),

                Tables\Columns\TextColumn::make('item_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', ucfirst($state)))
                    ->color(fn (string $state) => match ($state) {
                        'dry_goods' => 'info',
                        'raw_material' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('quantity_ordered')
                    ->label('Ordered')
                    ->numeric(2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('quantity_received')
                    ->label('Received')
                    ->numeric(2)
                    ->sortable()
                    ->color(fn (PurchaseOrderLine $record): string => $record->isFullyReceived() ? 'success' : ($record->quantity_received > 0 ? 'warning' : 'gray')),

                Tables\Columns\TextColumn::make('cost_per_unit')
                    ->label('Unit Cost')
                    ->money('USD')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('line_total')
                    ->label('Line Total')
                    ->getStateUsing(fn (PurchaseOrderLine $record): ?float => $record->cost_per_unit
                        ? round((float) $record->quantity_ordered * (float) $record->cost_per_unit, 2)
                        : null
                    )
                    ->money('USD')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('remaining')
                    ->label('Remaining')
                    ->getStateUsing(fn (PurchaseOrderLine $record): string => (string) $record->quantityRemaining())
                    ->numeric(2)
                    ->color(fn (PurchaseOrderLine $record): string => $record->isFullyReceived() ? 'success' : 'warning'),
            ])
            ->defaultSort('item_name');
    }

    /**
     * Generate a URL to the inventory item's view page based on item_type.
     */
    private static function getInventoryItemUrl(PurchaseOrderLine $record): ?string
    {
        if (! $record->item_id) {
            return null;
        }

        return match ($record->item_type) {
            'dry_goods' => DryGoodsItemResource::getUrl('view', ['record' => $record->item_id]),
            'raw_material' => RawMaterialResource::getUrl('view', ['record' => $record->item_id]),
            default => null,
        };
    }
}
