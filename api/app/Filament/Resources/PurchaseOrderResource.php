<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Models\PurchaseOrder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Purchase Orders';

    protected static ?string $modelLabel = 'Purchase Order';

    protected static ?string $pluralModelLabel = 'Purchase Orders';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Order Details')
                    ->schema([
                        Forms\Components\TextInput::make('vendor_name')
                            ->required()
                            ->maxLength(200),

                        Forms\Components\DatePicker::make('order_date')
                            ->required()
                            ->default(now()),

                        Forms\Components\DatePicker::make('expected_date'),

                        Forms\Components\Select::make('status')
                            ->options(array_combine(
                                PurchaseOrder::STATUSES,
                                array_map(fn ($s) => ucfirst($s), PurchaseOrder::STATUSES),
                            ))
                            ->default('ordered')
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Cost & Notes')
                    ->schema([
                        Forms\Components\TextInput::make('total_cost')
                            ->numeric()
                            ->prefix('$')
                            ->disabled()
                            ->dehydrated(false),

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
                Tables\Columns\TextColumn::make('vendor_name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('order_date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('expected_date')
                    ->date()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'primary' => 'ordered',
                        'warning' => 'partial',
                        'success' => 'received',
                        'gray' => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('Items'),

                Tables\Columns\TextColumn::make('total_cost')
                    ->money('USD')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('order_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(array_combine(
                        PurchaseOrder::STATUSES,
                        array_map(fn ($s) => ucfirst($s), PurchaseOrder::STATUSES),
                    )),

                Tables\Filters\Filter::make('overdue')
                    ->label('Overdue')
                    ->query(fn ($query) => $query->overdue()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'view' => Pages\ViewPurchaseOrder::route('/{record}'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
