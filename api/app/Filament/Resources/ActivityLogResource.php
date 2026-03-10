<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\ActivityLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only activity log viewer in the Management Portal.
 *
 * Displays audit trail of all system-level changes.
 * No create, edit, or delete — activity logs are immutable.
 * Filterable by user, action type, and date.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Activity Log';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Activity';

    protected static ?string $pluralModelLabel = 'Activity Log';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Who')
                    ->placeholder('System')
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('action')
                    ->colors([
                        'success' => 'created',
                        'warning' => 'updated',
                        'danger' => 'deleted',
                    ]),
                Tables\Columns\TextColumn::make('model_type')
                    ->label('What')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('model_id')
                    ->label('ID')
                    ->limit(8)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('changed_fields')
                    ->label('Changed')
                    ->formatStateUsing(function ($state): string {
                        if (is_array($state)) {
                            return implode(', ', $state);
                        }

                        return $state ?? '—';
                    }),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                    ]),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('User')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]) // No bulk actions — immutable
            ->defaultSort('created_at', 'desc')
            ->poll('30s'); // Auto-refresh every 30 seconds
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
            'view' => Pages\ViewActivityLog::route('/{record}'),
        ];
    }

    /**
     * Only Owner and Admin can view the activity log.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role, ['owner', 'admin']);
    }
}
