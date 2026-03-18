<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BarrelResource\Pages;
use App\Models\Barrel;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Barrel management resource.
 *
 * Manages barrel-specific metadata including cooperage, toast level, oak type,
 * and usage tracking. Each barrel is linked to a vessel record.
 */
class BarrelResource extends Resource
{
    protected static ?string $model = Barrel::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-archive-box';

    protected static \UnitEnum|string|null $navigationGroup = 'Production';

    protected static ?string $navigationLabel = 'Barrels';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Barrel';

    protected static ?string $pluralModelLabel = 'Barrels';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Barrel Details')
                    ->schema([
                        Forms\Components\Select::make('vessel_id')
                            ->relationship('vessel', 'name')
                            ->required()
                            ->native(false),
                        Forms\Components\TextInput::make('cooperage')
                            ->maxLength(255),
                        Forms\Components\Select::make('toast_level')
                            ->options(collect(Barrel::TOAST_LEVELS)->mapWithKeys(fn (string $t) => [$t => ucfirst(str_replace('_', ' ', $t))]))
                            ->native(false),
                        Forms\Components\Select::make('oak_type')
                            ->options(collect(Barrel::OAK_TYPES)->mapWithKeys(fn (string $o) => [$o => ucfirst(str_replace('_', ' ', $o))]))
                            ->native(false),
                        Forms\Components\TextInput::make('forest_origin')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('volume_gallons')
                            ->label('Volume (gallons)')
                            ->numeric()
                            ->minValue(0.0001)
                            ->maxValue(999999.9999),
                        Forms\Components\TextInput::make('years_used')
                            ->label('Years Used')
                            ->integer()
                            ->minValue(0),
                        Forms\Components\TextInput::make('qr_code')
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('vessel.name')
                    ->label('Vessel')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cooperage')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('toast_level')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst(str_replace('_', ' ', $state)) : '')
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('oak_type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst(str_replace('_', ' ', $state)) : '')
                    ->color('success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('volume_gallons')
                    ->label('Volume (gal)')
                    ->numeric(4)
                    ->sortable(),
                Tables\Columns\TextColumn::make('years_used')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('qr_code')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('toast_level')
                    ->options(collect(Barrel::TOAST_LEVELS)->mapWithKeys(fn (string $t) => [$t => ucfirst(str_replace('_', ' ', $t))])),
                Tables\Filters\SelectFilter::make('oak_type')
                    ->options(collect(Barrel::OAK_TYPES)->mapWithKeys(fn (string $o) => [$o => ucfirst(str_replace('_', ' ', $o))])),
                Tables\Filters\SelectFilter::make('cooperage')
                    ->options(fn () => Barrel::query()->distinct()->whereNotNull('cooperage')->pluck('cooperage', 'cooperage')->toArray())
                    ->searchable(),
                Tables\Filters\Filter::make('years_used')
                    ->form([
                        Forms\Components\TextInput::make('min_years')
                            ->label('Minimum Years Used')
                            ->integer(),
                        Forms\Components\TextInput::make('max_years')
                            ->label('Maximum Years Used')
                            ->integer(),
                    ])
                    ->query(function ($query, array $data): void {
                        $query
                            ->when($data['min_years'] ?? null, fn ($q) => $q->where('years_used', '>=', $data['min_years']))
                            ->when($data['max_years'] ?? null, fn ($q) => $q->where('years_used', '<=', $data['max_years']));
                    }),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('id');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBarrels::route('/'),
            'create' => Pages\CreateBarrel::route('/create'),
            'edit' => Pages\EditBarrel::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
