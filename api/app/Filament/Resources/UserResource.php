<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Team member management resource.
 *
 * Allows Owner/Admin to view team members, invite new users,
 * and deactivate existing members. Lives under Settings navigation group.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Team Members';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Team Member';

    protected static ?string $pluralModelLabel = 'Team Members';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('User Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Forms\Components\Select::make('role')
                            ->options([
                                'owner' => 'Owner',
                                'admin' => 'Admin',
                                'winemaker' => 'Winemaker',
                                'cellar_hand' => 'Cellar Hand',
                                'tasting_room_staff' => 'Tasting Room Staff',
                                'accountant' => 'Accountant',
                                'read_only' => 'Read Only',
                            ])
                            ->required()
                            ->native(false),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('role')
                    ->colors([
                        'primary' => 'owner',
                        'success' => 'admin',
                        'info' => 'winemaker',
                        'warning' => 'cellar_hand',
                        'secondary' => fn (string $state): bool => in_array($state, ['tasting_room_staff', 'accountant', 'read_only']),
                    ])
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state)))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active')
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'owner' => 'Owner',
                        'admin' => 'Admin',
                        'winemaker' => 'Winemaker',
                        'cellar_hand' => 'Cellar Hand',
                        'tasting_room_staff' => 'Tasting Room Staff',
                        'accountant' => 'Accountant',
                        'read_only' => 'Read Only',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->actions([
                Tables\Actions\Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Deactivate Team Member')
                    ->modalDescription('This will prevent the user from logging in. Their data will be preserved.')
                    ->visible(fn (User $record): bool => $record->is_active && $record->role !== 'owner')
                    ->action(fn (User $record) => $record->update(['is_active' => false])),
                Tables\Actions\Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => ! $record->is_active)
                    ->action(fn (User $record) => $record->update(['is_active' => true])),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Only Owner and Admin roles can access team management.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role, ['owner', 'admin']);
    }
}
