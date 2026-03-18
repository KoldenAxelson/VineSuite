<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\CaseGoodsSku;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\DB;

class LowStockSkusTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Low Stock SKUs';

    protected static ?string $description = 'Case goods with fewer than 12 cases on hand.';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CaseGoodsSku::query()
                    ->where('is_active', true)
                    ->addSelect([
                        'total_on_hand' => DB::table('stock_levels')
                            ->whereColumn('stock_levels.sku_id', 'case_goods_skus.id')
                            ->selectRaw('COALESCE(SUM(on_hand), 0)'),
                    ])
                    ->whereRaw('(SELECT COALESCE(SUM(on_hand), 0) FROM stock_levels WHERE stock_levels.sku_id = case_goods_skus.id) < 12')
            )
            ->columns([
                Tables\Columns\TextColumn::make('wine_name')
                    ->label('Wine')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('vintage')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('varietal')
                    ->label('Varietal'),

                Tables\Columns\TextColumn::make('format')
                    ->label('Format'),

                Tables\Columns\TextColumn::make('total_on_hand')
                    ->label('On Hand')
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn ($state): string => $state <= 0 ? 'danger' : 'warning'),
            ])
            ->paginated(false)
            ->defaultSort('total_on_hand', 'asc')
            ->emptyStateHeading('Stock levels healthy')
            ->emptyStateDescription('All active SKUs have 12+ cases on hand.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
