<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\CaseGoodsSku;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class MarginReportTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Margin Report';

    protected static ?string $description = 'Selling price vs. COGS by SKU. Only active SKUs with both price and cost data shown.';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CaseGoodsSku::query()
                    ->whereNotNull('cost_per_bottle')
                    ->whereNotNull('price')
                    ->where('is_active', true)
            )
            ->columns([
                Tables\Columns\TextColumn::make('wine_name')
                    ->label('Wine')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('vintage')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('varietal')
                    ->label('Varietal'),

                Tables\Columns\TextColumn::make('price')
                    ->label('Price')
                    ->money('usd')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('cost_per_bottle')
                    ->label('COGS/Bottle')
                    ->money('usd')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('margin_dollars')
                    ->label('Margin $')
                    ->alignEnd()
                    ->weight('bold')
                    ->getStateUsing(function (CaseGoodsSku $record): string {
                        $margin = (float) $record->price - (float) $record->cost_per_bottle;

                        return '$'.number_format($margin, 2);
                    }),

                Tables\Columns\TextColumn::make('gross_margin')
                    ->label('Margin %')
                    ->alignEnd()
                    ->weight('bold')
                    ->getStateUsing(function (CaseGoodsSku $record): string {
                        $price = (float) $record->price;
                        $cost = (float) $record->cost_per_bottle;
                        $margin = $price > 0 ? (($price - $cost) / $price) * 100 : 0;

                        return number_format($margin, 1).'%';
                    })
                    ->color(function (CaseGoodsSku $record): string {
                        $price = (float) $record->price;
                        $cost = (float) $record->cost_per_bottle;
                        $margin = $price > 0 ? (($price - $cost) / $price) * 100 : 0;

                        if ($margin >= 50) {
                            return 'success';
                        }

                        return $margin >= 30 ? 'warning' : 'danger';
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('No margin data')
            ->emptyStateDescription('Active SKUs with both price and cost data will appear here.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }
}
