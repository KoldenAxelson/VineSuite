<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\EquipmentResource\Pages;
use App\Filament\Resources\EquipmentResource\RelationManagers;
use App\Models\Equipment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema;

class EquipmentResource extends Resource
{
    protected static ?string $model = Equipment::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Equipment';

    protected static ?string $modelLabel = 'Equipment';

    protected static ?string $pluralModelLabel = 'Equipment';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Equipment Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(150),

                        Forms\Components\Select::make('equipment_type')
                            ->options(array_combine(
                                Equipment::EQUIPMENT_TYPES,
                                array_map(fn ($t) => str_replace('_', ' ', ucfirst($t)), Equipment::EQUIPMENT_TYPES),
                            ))
                            ->required(),

                        Forms\Components\Select::make('status')
                            ->options(array_combine(
                                Equipment::STATUSES,
                                array_map('ucfirst', Equipment::STATUSES),
                            ))
                            ->default('operational'),

                        Forms\Components\TextInput::make('serial_number')
                            ->maxLength(100),

                        Forms\Components\TextInput::make('manufacturer')
                            ->maxLength(150),

                        Forms\Components\TextInput::make('model_number')
                            ->maxLength(100),

                        Forms\Components\TextInput::make('location')
                            ->maxLength(150),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ]),

                Forms\Components\Section::make('Purchase & Maintenance')
                    ->schema([
                        Forms\Components\DatePicker::make('purchase_date'),

                        Forms\Components\TextInput::make('purchase_value')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('$'),

                        Forms\Components\DatePicker::make('next_maintenance_due'),
                    ]),

                Forms\Components\Section::make('Notes')
                    ->schema([
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

                Tables\Columns\TextColumn::make('equipment_type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', ucfirst($state)))
                    ->sortable(),

                Tables\Columns\TextColumn::make('serial_number')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'operational' => 'success',
                        'maintenance' => 'warning',
                        'retired' => 'gray',
                        default => 'secondary',
                    })
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('location')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('next_maintenance_due')
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('maintenance_logs_count')
                    ->counts('maintenanceLogs')
                    ->label('Logs')
                    ->toggleable(),

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
                Tables\Filters\SelectFilter::make('equipment_type')
                    ->label('Type')
                    ->options(fn () => Schema::hasTable('equipment')
                        ? array_combine(
                            Equipment::EQUIPMENT_TYPES,
                            array_map(fn ($t) => str_replace('_', ' ', ucfirst($t)), Equipment::EQUIPMENT_TYPES),
                        )
                        : []
                    ),

                Tables\Filters\SelectFilter::make('status')
                    ->options(fn () => array_combine(
                        Equipment::STATUSES,
                        array_map('ucfirst', Equipment::STATUSES),
                    )),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),

                Tables\Filters\Filter::make('maintenance_overdue')
                    ->label('Maintenance Overdue')
                    ->query(fn ($query) => $query->maintenanceDue()),
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
            RelationManagers\MaintenanceLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEquipment::route('/'),
            'create' => Pages\CreateEquipment::route('/create'),
            'view' => Pages\ViewEquipment::route('/{record}'),
            'edit' => Pages\EditEquipment::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
