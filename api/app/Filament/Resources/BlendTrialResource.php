<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BlendTrialResource\Pages;
use App\Models\BlendTrial;
use App\Services\BlendService;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextColumn\TextColumnSize;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class BlendTrialResource extends Resource
{
    protected static ?string $model = BlendTrial::class;

    protected static ?string $navigationIcon = 'heroicon-o-variable';

    protected static ?string $navigationGroup = 'Production';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Blend Trials';

    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Blend Trial Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('status')
                            ->options([
                                'draft' => 'Draft',
                                'finalized' => 'Finalized',
                                'archived' => 'Archived',
                            ])
                            ->required(),
                        TextInput::make('version')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('ttb_label_variety')
                            ->nullable()
                            ->maxLength(255),
                        TextInput::make('total_volume_gallons')
                            ->numeric()
                            ->required()
                            ->step(0.0001),
                        Textarea::make('notes')
                            ->nullable()
                            ->columnSpanFull(),
                    ]),
                Section::make('Variety Composition')
                    ->columns(1)
                    ->schema([
                        KeyValue::make('variety_composition')
                            ->nullable(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold')
                    ->size(TextColumnSize::Medium),
                BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'draft',
                        'success' => 'finalized',
                        'secondary' => 'archived',
                    ]),
                TextColumn::make('version')
                    ->numeric(),
                TextColumn::make('ttb_label_variety')
                    ->placeholder('-'),
                TextColumn::make('total_volume_gallons')
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('creator.name')
                    ->label('Created By'),
                TextColumn::make('finalized_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'finalized' => 'Finalized',
                        'archived' => 'Archived',
                    ]),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make()
                        ->visible(function (BlendTrial $record): bool {
                            return $record->status !== 'finalized';
                        }),
                    \Filament\Tables\Actions\Action::make('finalize')
                        ->label('Finalize')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Finalize Blend Trial')
                        ->modalDescription('Are you sure you want to finalize this blend trial? This action cannot be undone.')
                        ->visible(function (BlendTrial $record): bool {
                            return $record->status === 'draft';
                        })
                        ->action(function (BlendTrial $record): void {
                            app(BlendService::class)->finalizeTrial(
                                $record,
                                auth()->id(),
                            );
                        }),
                ]),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlendTrials::route('/'),
            'create' => Pages\CreateBlendTrial::route('/create'),
            'view' => Pages\ViewBlendTrial::route('/{record}'),
            'edit' => Pages\EditBlendTrial::route('/{record}/edit'),
        ];
    }
}
