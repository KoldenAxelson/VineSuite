<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BottlingRunResource\Pages;
use App\Models\BottlingRun;
use App\Services\BottlingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BottlingRunResource extends Resource
{
    protected static ?string $model = BottlingRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Production';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Bottling Runs';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Bottling Run Details')
                    ->schema([
                        Forms\Components\Select::make('lot_id')
                            ->relationship('lot', 'name')
                            ->required(),
                        Forms\Components\Select::make('bottle_format')
                            ->options([
                                '187ml' => '187ml',
                                '375ml' => '375ml',
                                '500ml' => '500ml',
                                '750ml' => '750ml',
                                '1.0L' => '1.0L',
                                '1.5L' => '1.5L',
                                '3.0L' => '3.0L',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('bottles_filled')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('bottles_breakage')
                            ->numeric()
                            ->nullable(),
                        Forms\Components\TextInput::make('waste_percent')
                            ->numeric()
                            ->required()
                            ->step(0.01),
                        Forms\Components\TextInput::make('volume_bottled_gallons')
                            ->numeric()
                            ->required()
                            ->step(0.0001),
                        Forms\Components\Select::make('status')
                            ->options([
                                'planned' => 'Planned',
                                'in_progress' => 'In Progress',
                                'completed' => 'Completed',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('sku')
                            ->disabled()
                            ->nullable(),
                        Forms\Components\TextInput::make('cases_produced')
                            ->numeric()
                            ->nullable(),
                        Forms\Components\TextInput::make('bottles_per_case')
                            ->numeric()
                            ->nullable(),
                        Forms\Components\DateTimePicker::make('bottled_at')
                            ->label('Bottled At')
                            ->nullable(),
                        Forms\Components\Textarea::make('notes')
                            ->nullable(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('lot.name')
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('bottle_format'),
                Tables\Columns\TextColumn::make('bottles_filled')
                    ->numeric(),
                Tables\Columns\TextColumn::make('volume_bottled_gallons')
                    ->numeric(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'planned',
                        'info' => 'in_progress',
                        'success' => 'completed',
                    ]),
                Tables\Columns\TextColumn::make('sku')
                    ->searchable()
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('cases_produced')
                    ->numeric(),
                Tables\Columns\TextColumn::make('performer.name')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('bottled_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'planned' => 'Planned',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                    ]),
                Tables\Filters\SelectFilter::make('bottle_format')
                    ->options([
                        '187ml' => '187ml',
                        '375ml' => '375ml',
                        '500ml' => '500ml',
                        '750ml' => '750ml',
                        '1.0L' => '1.0L',
                        '1.5L' => '1.5L',
                        '3.0L' => '3.0L',
                    ]),
                Tables\Filters\SelectFilter::make('lot')
                    ->relationship('lot', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('complete')
                    ->label('Complete')
                    ->icon('heroicon-o-check')
                    ->requiresConfirmation()
                    ->visible(fn (BottlingRun $record) => $record->status !== 'completed')
                    ->action(function (BottlingRun $record): void {
                        app(BottlingService::class)->completeBottlingRun(
                            $record,
                            auth()->id(),
                        );
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBottlingRuns::route('/'),
            'create' => Pages\CreateBottlingRun::route('/create'),
            'view' => Pages\ViewBottlingRun::route('/{record}'),
            'edit' => Pages\EditBottlingRun::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
