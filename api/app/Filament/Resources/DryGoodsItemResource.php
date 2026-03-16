<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DryGoodsItemResource\Pages;
use App\Filament\Resources\DryGoodsItemResource\RelationManagers;
use App\Models\DryGoodsItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema;

class DryGoodsItemResource extends Resource
{
    protected static ?string $model = DryGoodsItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Dry Goods';

    protected static ?string $modelLabel = 'Dry Goods Item';

    protected static ?string $pluralModelLabel = 'Dry Goods';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Item Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(150),

                        Forms\Components\Select::make('item_type')
                            ->options(array_combine(
                                DryGoodsItem::ITEM_TYPES,
                                array_map(fn ($t) => str_replace('_', ' ', ucfirst($t)), DryGoodsItem::ITEM_TYPES),
                            ))
                            ->required(),

                        Forms\Components\Select::make('unit_of_measure')
                            ->options(array_combine(
                                DryGoodsItem::UNITS_OF_MEASURE,
                                array_map('ucfirst', DryGoodsItem::UNITS_OF_MEASURE),
                            ))
                            ->required(),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ]),

                Forms\Components\Section::make('Stock & Cost')
                    ->schema([
                        Forms\Components\TextInput::make('on_hand')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),

                        Forms\Components\TextInput::make('reorder_point')
                            ->numeric()
                            ->minValue(0),

                        Forms\Components\TextInput::make('cost_per_unit')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('$'),
                    ]),

                Forms\Components\Section::make('Vendor & Notes')
                    ->schema([
                        Forms\Components\TextInput::make('vendor_name')
                            ->maxLength(200),

                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->maxLength(2000),
                    ]),
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

                Tables\Columns\TextColumn::make('item_type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', ucfirst($state)))
                    ->sortable(),

                Tables\Columns\TextColumn::make('on_hand')
                    ->numeric(decimalPlaces: 0)
                    ->sortable(),

                Tables\Columns\TextColumn::make('unit_of_measure')
                    ->label('Unit')
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),

                Tables\Columns\TextColumn::make('reorder_point')
                    ->numeric(decimalPlaces: 0)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cost_per_unit')
                    ->money('USD')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('vendor_name')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('item_type')
                    ->label('Type')
                    ->options(fn () => Schema::hasTable('dry_goods_items')
                        ? array_combine(
                            DryGoodsItem::ITEM_TYPES,
                            array_map(fn ($t) => str_replace('_', ' ', ucfirst($t)), DryGoodsItem::ITEM_TYPES),
                        )
                        : []
                    ),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),

                Tables\Filters\Filter::make('below_reorder')
                    ->label('Below Reorder Point')
                    ->query(fn ($query) => $query->belowReorderPoint()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PurchaseOrderLinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDryGoodsItems::route('/'),
            'create' => Pages\CreateDryGoodsItem::route('/create'),
            'view' => Pages\ViewDryGoodsItem::route('/{record}'),
            'edit' => Pages\EditDryGoodsItem::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
