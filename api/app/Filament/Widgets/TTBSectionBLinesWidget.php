<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\TTBReportLine;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TTBSectionBLinesWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Section B — Bottled Wine Line Items';

    protected int|string|array $columnSpan = 'full';

    public ?string $reportId = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                TTBReportLine::query()
                    ->where('ttb_report_id', $this->reportId)
                    ->where('section', 'B')
                    ->orderBy('line_number')
            )
            ->columns([
                Tables\Columns\TextColumn::make('line_number')
                    ->label('Line')
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->wrap(),

                Tables\Columns\TextColumn::make('wine_type')
                    ->label('Wine Type')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'not_over_16' => 'info',
                        'over_16_to_21' => 'warning',
                        'over_21_to_24' => 'danger',
                        'artificially_carbonated', 'sparkling' => 'info',
                        'hard_cider' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'not_over_16' => 'Not Over 16%',
                        'over_16_to_21' => 'Over 16-21%',
                        'over_21_to_24' => 'Over 21-24%',
                        'artificially_carbonated' => 'Artif. Carbonated',
                        'sparkling' => 'Sparkling',
                        'hard_cider' => 'Hard Cider',
                        'all' => 'All Types',
                        default => $state ? ucfirst(str_replace('_', ' ', $state)) : '—',
                    }),

                Tables\Columns\TextColumn::make('gallons')
                    ->label('Gallons')
                    ->numeric(0)
                    ->alignEnd()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('source_event_ids')
                    ->label('Events')
                    ->alignCenter()
                    ->getStateUsing(fn (TTBReportLine $record): string => count($record->source_event_ids ?? []) > 0
                        ? (string) count($record->source_event_ids)
                        : '—'
                    )
                    ->color(fn (TTBReportLine $record): string => count($record->source_event_ids ?? []) > 0
                        ? 'primary'
                        : 'gray'
                    ),

                Tables\Columns\IconColumn::make('needs_review')
                    ->label('Review')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('')
                    ->alignCenter(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No activity')
            ->emptyStateDescription('No activity in Section B for the reporting period.')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
