<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CaseGoodsSkuResource\Pages;
use App\Filament\Resources\CaseGoodsSkuResource\RelationManagers;
use App\Models\CaseGoodsSku;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema;

class CaseGoodsSkuResource extends Resource
{
    protected static ?string $model = CaseGoodsSku::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Case Goods (SKUs)';

    protected static ?string $modelLabel = 'SKU';

    protected static ?string $pluralModelLabel = 'SKUs';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Wine Details')
                    ->schema([
                        Forms\Components\TextInput::make('wine_name')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('vintage')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(2100)
                            ->helperText('Use 0 for non-vintage items'),

                        Forms\Components\TextInput::make('varietal')
                            ->required()
                            ->maxLength(100),

                        Forms\Components\Select::make('format')
                            ->options(array_combine(
                                CaseGoodsSku::FORMATS,
                                CaseGoodsSku::FORMATS,
                            ))
                            ->default('750ml')
                            ->required(),

                        Forms\Components\Select::make('case_size')
                            ->options(array_combine(
                                array_map('strval', CaseGoodsSku::CASE_SIZES),
                                array_map(fn (int $s) => "{$s} bottles/case", CaseGoodsSku::CASE_SIZES),
                            ))
                            ->default('12')
                            ->required(),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Pricing & Barcode')
                    ->schema([
                        Forms\Components\TextInput::make('upc_barcode')
                            ->label('UPC Barcode')
                            ->maxLength(50),

                        Forms\Components\TextInput::make('price')
                            ->label('Retail Price')
                            ->numeric()
                            ->prefix('$')
                            ->step(0.01),

                        Forms\Components\TextInput::make('cost_per_bottle')
                            ->label('Cost Per Bottle')
                            ->numeric()
                            ->prefix('$')
                            ->step(0.01)
                            ->helperText('Populated by cost accounting module'),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Product Info')
                    ->schema([
                        Forms\Components\Textarea::make('tasting_notes')
                            ->rows(4)
                            ->maxLength(5000),

                        Forms\Components\FileUpload::make('image_path')
                            ->label('Product Image')
                            ->image()
                            ->directory('sku-images')
                            ->maxSize(5120),

                        Forms\Components\FileUpload::make('tech_sheet_path')
                            ->label('Tech Sheet (PDF)')
                            ->acceptedFileTypes(['application/pdf'])
                            ->directory('sku-tech-sheets')
                            ->maxSize(10240),
                    ]),

                Forms\Components\Section::make('Traceability')
                    ->schema([
                        Forms\Components\Select::make('lot_id')
                            ->label('Origin Lot')
                            ->relationship('lot', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),

                        Forms\Components\Select::make('bottling_run_id')
                            ->label('Bottling Run')
                            ->relationship('bottlingRun', 'id')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('wine_name')
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('vintage')
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'NV' : (string) $state),

                Tables\Columns\TextColumn::make('varietal')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('format')
                    ->sortable(),

                Tables\Columns\TextColumn::make('case_size')
                    ->label('Case')
                    ->formatStateUsing(fn (int $state): string => "{$state}pk"),

                Tables\Columns\TextColumn::make('price')
                    ->label('Retail')
                    ->money('usd')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),

                Tables\Columns\TextColumn::make('upc_barcode')
                    ->label('UPC')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('vintage')
                    ->options(function (): array {
                        if (! Schema::hasTable('case_goods_skus')) {
                            return [];
                        }

                        return CaseGoodsSku::query()
                            ->distinct()
                            ->orderByDesc('vintage')
                            ->pluck('vintage', 'vintage')
                            ->mapWithKeys(fn (int $v, int $k) => [$k => $v === 0 ? 'NV' : (string) $v])
                            ->toArray();
                    }),

                Tables\Filters\SelectFilter::make('varietal')
                    ->options(function (): array {
                        if (! Schema::hasTable('case_goods_skus')) {
                            return [];
                        }

                        return CaseGoodsSku::query()
                            ->distinct()
                            ->orderBy('varietal')
                            ->pluck('varietal', 'varietal')
                            ->toArray();
                    }),

                Tables\Filters\SelectFilter::make('format')
                    ->options(array_combine(
                        CaseGoodsSku::FORMATS,
                        CaseGoodsSku::FORMATS,
                    )),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\StockLevelsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCaseGoodsSkus::route('/'),
            'create' => Pages\CreateCaseGoodsSku::route('/create'),
            'view' => Pages\ViewCaseGoodsSku::route('/{record}'),
            'edit' => Pages\EditCaseGoodsSku::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
