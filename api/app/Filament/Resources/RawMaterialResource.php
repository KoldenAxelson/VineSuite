<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\RawMaterialResource\Pages;
use App\Filament\Resources\RawMaterialResource\RelationManagers;
use App\Models\RawMaterial;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema as DatabaseSchema;

class RawMaterialResource extends Resource
{
    protected static ?string $model = RawMaterial::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-beaker';

    protected static \UnitEnum|string|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Raw Materials';

    protected static ?string $modelLabel = 'Raw Material';

    protected static ?string $pluralModelLabel = 'Raw Materials';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Material Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(150),

                        Forms\Components\Select::make('category')
                            ->options(array_combine(
                                RawMaterial::CATEGORIES,
                                array_map(fn ($c) => str_replace('_', ' ', ucfirst($c)), RawMaterial::CATEGORIES),
                            ))
                            ->required(),

                        Forms\Components\Select::make('unit_of_measure')
                            ->options(array_combine(
                                RawMaterial::UNITS_OF_MEASURE,
                                array_map('ucfirst', RawMaterial::UNITS_OF_MEASURE),
                            ))
                            ->required(),

                        Forms\Components\DatePicker::make('expiration_date'),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ]),

                Section::make('Stock & Cost')
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

                Section::make('Vendor & Notes')
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

                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', ucfirst($state)))
                    ->sortable(),

                Tables\Columns\TextColumn::make('on_hand')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('unit_of_measure')
                    ->label('Unit')
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),

                Tables\Columns\TextColumn::make('reorder_point')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cost_per_unit')
                    ->money('USD')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('expiration_date')
                    ->date()
                    ->sortable()
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
                Tables\Filters\SelectFilter::make('category')
                    ->label('Category')
                    ->options(fn () => DatabaseSchema::hasTable('raw_materials')
                        ? array_combine(
                            RawMaterial::CATEGORIES,
                            array_map(fn ($c) => str_replace('_', ' ', ucfirst($c)), RawMaterial::CATEGORIES),
                        )
                        : []
                    ),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),

                Tables\Filters\Filter::make('below_reorder')
                    ->label('Below Reorder Point')
                    ->query(fn ($query) => $query->belowReorderPoint()),

                Tables\Filters\Filter::make('expired')
                    ->label('Expired')
                    ->query(fn ($query) => $query->expired()),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
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
            'index' => Pages\ListRawMaterials::route('/'),
            'create' => Pages\CreateRawMaterial::route('/create'),
            'view' => Pages\ViewRawMaterial::route('/{record}'),
            'edit' => Pages\EditRawMaterial::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
