<?php

declare(strict_types=1);

namespace App\Filament\Resources\RawMaterialResource\RelationManagers;

use App\Filament\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrderLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PurchaseOrderLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'purchaseOrderLines';

    protected static ?string $title = 'Purchase Order History';

    protected static ?string $recordTitleAttribute = 'item_name';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('item_name')
            ->columns([
                Tables\Columns\TextColumn::make('purchaseOrder.po_number')
                    ->label('PO #')
                    ->sortable()
                    ->weight('bold')
                    ->url(fn (PurchaseOrderLine $record): ?string => $record->purchase_order_id
                        ? PurchaseOrderResource::getUrl('view', ['record' => $record->purchase_order_id])
                        : null
                    ),

                Tables\Columns\TextColumn::make('purchaseOrder.status')
                    ->label('PO Status')
                    ->badge()
                    ->color(fn (string $state, $record): string => $record->purchaseOrder->statusColor()),

                Tables\Columns\TextColumn::make('quantity_ordered')
                    ->label('Ordered')
                    ->numeric(2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('quantity_received')
                    ->label('Received')
                    ->numeric(2)
                    ->sortable()
                    ->color(fn (PurchaseOrderLine $record): string => $record->isFullyReceived()
                        ? 'success'
                        : ($record->quantity_received > 0 ? 'warning' : 'gray')
                    ),

                Tables\Columns\TextColumn::make('cost_per_unit')
                    ->label('Unit Cost')
                    ->money('USD')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('purchaseOrder.ordered_at')
                    ->label('Order Date')
                    ->date()
                    ->sortable(),
            ])
            ->defaultSort('purchaseOrder.ordered_at', 'desc');
    }
}
