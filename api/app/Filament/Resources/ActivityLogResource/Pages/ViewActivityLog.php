<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLogResource\Pages;

use App\Filament\Resources\ActivityLogResource;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewActivityLog extends ViewRecord
{
    protected static string $resource = ActivityLogResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Activity Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('When')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('user.name')
                            ->label('Who')
                            ->placeholder('System'),
                        Infolists\Components\TextEntry::make('action')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'created' => 'success',
                                'updated' => 'warning',
                                'deleted' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('model_type')
                            ->label('Model')
                            ->formatStateUsing(fn (string $state): string => class_basename($state)),
                        Infolists\Components\TextEntry::make('model_id')
                            ->label('Record ID'),
                        Infolists\Components\TextEntry::make('ip_address')
                            ->label('IP Address'),
                        Infolists\Components\TextEntry::make('user_agent')
                            ->label('User Agent')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Changed Fields')
                    ->schema([
                        Infolists\Components\TextEntry::make('changed_fields')
                            ->label('Fields')
                            ->formatStateUsing(function ($state): string {
                                if (is_array($state)) {
                                    return implode(', ', $state);
                                }

                                return $state ?? '—';
                            }),
                    ])
                    ->visible(fn ($record) => ! empty($record->changed_fields)),

                Infolists\Components\Section::make('Old Values')
                    ->schema([
                        Infolists\Components\TextEntry::make('old_values')
                            ->label('')
                            ->formatStateUsing(fn ($state) => is_array($state)
                                ? json_encode($state, JSON_PRETTY_PRINT)
                                : '—')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => ! empty($record->old_values))
                    ->collapsed(),

                Infolists\Components\Section::make('New Values')
                    ->schema([
                        Infolists\Components\TextEntry::make('new_values')
                            ->label('')
                            ->formatStateUsing(fn ($state) => is_array($state)
                                ? json_encode($state, JSON_PRETTY_PRINT)
                                : '—')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => ! empty($record->new_values))
                    ->collapsed(),
            ]);
    }
}
