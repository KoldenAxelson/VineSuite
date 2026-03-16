<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PhysicalCount as PhysicalCountModel;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class PhysicalCount extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Physical Count';

    protected static ?string $title = 'Physical Inventory Count';

    protected static string $view = 'filament.pages.physical-count';

    public function table(Table $table): Table
    {
        return $table
            ->query(PhysicalCountModel::query()->with(['location']))
            ->columns([
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Location')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'in_progress',
                        'success' => 'completed',
                        'danger' => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),

                Tables\Columns\TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('SKUs'),

                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (PhysicalCountModel $record): string => route('filament.portal.pages.physical-count').'?count_id='.$record->id),
            ]);
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
