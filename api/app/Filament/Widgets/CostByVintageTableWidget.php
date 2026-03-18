<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\LotCogsSummary;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Model;

class CostByVintageTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Cost Summary by Vintage';

    protected int|string|array $columnSpan = 'full';

    public function getTableRecordKey(Model|array $record): string
    {
        return (string) (is_array($record) ? $record['vintage'] : $record->getAttribute('vintage'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                LotCogsSummary::query()
                    ->join('lots', 'lot_cogs_summaries.lot_id', '=', 'lots.id')
                    ->select([
                        'lots.vintage',
                    ])
                    ->selectRaw('COUNT(*) as lot_count')
                    ->selectRaw('SUM(lot_cogs_summaries.total_cost) as total_cost_raw')
                    ->selectRaw('SUM(lot_cogs_summaries.bottles_produced) as total_bottles_raw')
                    ->selectRaw('AVG(lot_cogs_summaries.cost_per_bottle) as avg_cost_per_bottle_raw')
                    ->selectRaw('AVG(lot_cogs_summaries.cost_per_gallon) as avg_cost_per_gallon_raw')
                    ->groupBy('lots.vintage')
            )
            ->columns([
                Tables\Columns\TextColumn::make('vintage')
                    ->label('Vintage')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('lot_count')
                    ->label('Lots')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_cost_raw')
                    ->label('Total Cost')
                    ->money('usd')
                    ->alignEnd()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('total_bottles_raw')
                    ->label('Total Bottles')
                    ->numeric()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('avg_cost_per_bottle_raw')
                    ->label('Avg $/Bottle')
                    ->money('usd')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('avg_cost_per_gallon_raw')
                    ->label('Avg $/Gallon')
                    ->money('usd')
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->defaultSort('vintage', 'desc')
            ->defaultKeySort(false)
            ->emptyStateHeading('No vintage data')
            ->emptyStateDescription('COGS summaries will appear here grouped by vintage.')
            ->emptyStateIcon('heroicon-o-chart-bar');
    }
}
