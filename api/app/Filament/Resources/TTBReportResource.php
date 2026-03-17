<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TTBReportResource\Pages;
use App\Models\TTBReport;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TTBReportResource extends Resource
{
    protected static ?string $model = TTBReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'TTB Reports';

    protected static ?string $modelLabel = 'TTB Report';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('report_period_year')
                    ->label('Year')
                    ->sortable(),
                Tables\Columns\TextColumn::make('report_period_month')
                    ->label('Month')
                    ->formatStateUsing(fn (int $state): string => date('F', mktime(0, 0, 0, $state, 1)))
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'draft',
                        'info' => 'reviewed',
                        'success' => 'filed',
                        'danger' => 'amended',
                    ]),
                Tables\Columns\TextColumn::make('generated_at')
                    ->label('Generated')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                Tables\Columns\TextColumn::make('reviewedByUser.name')
                    ->label('Reviewed By')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('reviewed_at')
                    ->label('Reviewed At')
                    ->dateTime('M j, Y')
                    ->placeholder('-'),
                Tables\Columns\IconColumn::make('pdf_path')
                    ->label('PDF')
                    ->boolean()
                    ->trueIcon('heroicon-o-document-arrow-down')
                    ->falseIcon('heroicon-o-minus'),
            ])
            ->defaultSort('report_period_year', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'reviewed' => 'Reviewed',
                        'filed' => 'Filed',
                        'amended' => 'Amended',
                    ]),
                Tables\Filters\SelectFilter::make('report_period_year')
                    ->label('Year')
                    ->options(function () {
                        if (! \Illuminate\Support\Facades\Schema::hasTable('ttb_reports')) {
                            return [];
                        }

                        return TTBReport::distinct()
                            ->pluck('report_period_year', 'report_period_year')
                            ->sort()
                            ->toArray();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTTBReports::route('/'),
            'view' => Pages\ViewTTBReport::route('/{record}'),
            'review' => Pages\ReviewTTBReport::route('/{record}/review'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
