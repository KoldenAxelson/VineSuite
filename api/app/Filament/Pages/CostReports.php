<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CaseGoodsSku;
use App\Models\Lot;
use App\Models\LotCogsSummary;
use App\Models\LotCostEntry;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class CostReports extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Cost Reports';

    protected static ?string $title = 'COGS & Cost Reports';

    protected static string $view = 'filament.pages.cost-reports';

    public string $activeTab = 'by-lot';

    /**
     * Summary stats across all COGS summaries.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $cogsCount = LotCogsSummary::count();

        $avgCostPerBottle = $cogsCount > 0
            ? LotCogsSummary::whereNotNull('cost_per_bottle')->avg('cost_per_bottle')
            : null;

        $totalBottlesProduced = LotCogsSummary::sum('bottles_produced');

        $totalCostTracked = LotCostEntry::sum('amount');

        $lotsWithCosts = LotCostEntry::distinct('lot_id')->count('lot_id');

        return [
            'cogs_summaries_count' => $cogsCount,
            'avg_cost_per_bottle' => $avgCostPerBottle !== null
                ? '$'.number_format((float) $avgCostPerBottle, 2)
                : 'N/A',
            'total_bottles_produced' => number_format($totalBottlesProduced),
            'total_cost_tracked' => '$'.number_format((float) $totalCostTracked, 2),
            'lots_with_costs' => $lotsWithCosts,
        ];
    }

    /**
     * COGS by lot table — primary view.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(
                LotCogsSummary::query()
                    ->join('lots', 'lot_cogs_summaries.lot_id', '=', 'lots.id')
                    ->select([
                        'lot_cogs_summaries.*',
                        'lots.name as lot_name',
                        'lots.variety',
                        'lots.vintage',
                    ])
            )
            ->columns([
                Tables\Columns\TextColumn::make('lot_name')
                    ->label('Lot')
                    ->searchable(query: function ($query, string $search) {
                        $query->where('lots.name', 'ilike', "%{$search}%");
                    })
                    ->sortable(query: function ($query, string $direction) {
                        $query->orderBy('lots.name', $direction);
                    })
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('variety')
                    ->sortable(query: function ($query, string $direction) {
                        $query->orderBy('lots.variety', $direction);
                    }),

                Tables\Columns\TextColumn::make('vintage')
                    ->sortable(query: function ($query, string $direction) {
                        $query->orderBy('lots.vintage', $direction);
                    }),

                Tables\Columns\TextColumn::make('total_fruit_cost')
                    ->label('Fruit')
                    ->money('usd')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_material_cost')
                    ->label('Material')
                    ->money('usd')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_labor_cost')
                    ->label('Labor')
                    ->money('usd')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_overhead_cost')
                    ->label('Overhead')
                    ->money('usd')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total Cost')
                    ->money('usd')
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('bottles_produced')
                    ->label('Bottles')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cost_per_bottle')
                    ->label('$/Bottle')
                    ->money('usd')
                    ->sortable(),

                Tables\Columns\TextColumn::make('cost_per_case')
                    ->label('$/Case')
                    ->money('usd')
                    ->sortable(),

                Tables\Columns\TextColumn::make('calculated_at')
                    ->label('Calculated')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->defaultSort('calculated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('vintage')
                    ->options(function () {
                        return LotCogsSummary::query()
                            ->join('lots', 'lot_cogs_summaries.lot_id', '=', 'lots.id')
                            ->distinct()
                            ->pluck('lots.vintage', 'lots.vintage')
                            ->sort()
                            ->toArray();
                    })
                    ->query(function ($query, array $data) {
                        if ($data['value']) {
                            $query->where('lots.vintage', $data['value']);
                        }
                    }),

                Tables\Filters\SelectFilter::make('variety')
                    ->options(function () {
                        return LotCogsSummary::query()
                            ->join('lots', 'lot_cogs_summaries.lot_id', '=', 'lots.id')
                            ->distinct()
                            ->pluck('lots.variety', 'lots.variety')
                            ->sort()
                            ->toArray();
                    })
                    ->query(function ($query, array $data) {
                        if ($data['value']) {
                            $query->where('lots.variety', $data['value']);
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('export_csv')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function () {
                        return $this->exportCogsCsv();
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No COGS data yet')
            ->emptyStateDescription('COGS summaries are generated when bottling runs are completed.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }

    /**
     * Get margin report data (SKU selling price vs COGS).
     *
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    public function getMarginReport(): \Illuminate\Support\Collection
    {
        return CaseGoodsSku::query()
            ->whereNotNull('cost_per_bottle')
            ->whereNotNull('price')
            ->where('is_active', true)
            ->get()
            ->map(function (CaseGoodsSku $sku) {
                $costPerBottle = (float) $sku->cost_per_bottle;
                $price = (float) $sku->price;
                $margin = $price > 0 ? (($price - $costPerBottle) / $price) * 100 : 0;

                return (object) [
                    'wine_name' => $sku->wine_name,
                    'vintage' => $sku->vintage,
                    'varietal' => $sku->varietal,
                    'format' => $sku->format,
                    'price' => '$'.number_format($price, 2),
                    'cost_per_bottle' => '$'.number_format($costPerBottle, 2),
                    'gross_margin' => number_format($margin, 1).'%',
                    'margin_dollars' => '$'.number_format($price - $costPerBottle, 2),
                    'margin_class' => $margin >= 50 ? 'text-success-600' : ($margin >= 30 ? 'text-warning-600' : 'text-danger-600'),
                ];
            });
    }

    /**
     * Get cost breakdown by vintage.
     *
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    public function getCostByVintage(): \Illuminate\Support\Collection
    {
        return DB::table('lot_cogs_summaries')
            ->join('lots', 'lot_cogs_summaries.lot_id', '=', 'lots.id')
            ->select([
                'lots.vintage',
                DB::raw('COUNT(*) as lot_count'),
                DB::raw('SUM(lot_cogs_summaries.total_cost) as total_cost'),
                DB::raw('SUM(lot_cogs_summaries.bottles_produced) as total_bottles'),
                DB::raw('AVG(lot_cogs_summaries.cost_per_bottle) as avg_cost_per_bottle'),
                DB::raw('AVG(lot_cogs_summaries.cost_per_gallon) as avg_cost_per_gallon'),
            ])
            ->groupBy('lots.vintage')
            ->orderByDesc('lots.vintage')
            ->get()
            ->map(function ($row) {
                return (object) [
                    'vintage' => $row->vintage,
                    'lot_count' => $row->lot_count,
                    'total_cost' => '$'.number_format((float) $row->total_cost, 2),
                    'total_bottles' => number_format((int) $row->total_bottles),
                    'avg_cost_per_bottle' => '$'.number_format((float) $row->avg_cost_per_bottle, 2),
                    'avg_cost_per_gallon' => '$'.number_format((float) $row->avg_cost_per_gallon, 2),
                ];
            });
    }

    /**
     * Export COGS data as CSV.
     */
    public function exportCogsCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = 'cogs-report-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            // Header row
            fputcsv($handle, [
                'Lot Name',
                'Variety',
                'Vintage',
                'Fruit Cost',
                'Material Cost',
                'Labor Cost',
                'Overhead Cost',
                'Transfer-In Cost',
                'Total Cost',
                'Volume (gal)',
                'Cost/Gallon',
                'Bottles Produced',
                'Cost/Bottle',
                'Cost/Case',
                'Packaging Cost/Bottle',
                'Calculated At',
            ]);

            $summaries = LotCogsSummary::query()
                ->join('lots', 'lot_cogs_summaries.lot_id', '=', 'lots.id')
                ->select(['lot_cogs_summaries.*', 'lots.name as lot_name', 'lots.variety', 'lots.vintage'])
                ->orderBy('lots.vintage')
                ->orderBy('lots.name')
                ->get();

            foreach ($summaries as $s) {
                fputcsv($handle, [
                    $s->getAttribute('lot_name'),
                    $s->getAttribute('variety'),
                    $s->getAttribute('vintage'),
                    $s->total_fruit_cost,
                    $s->total_material_cost,
                    $s->total_labor_cost,
                    $s->total_overhead_cost,
                    $s->total_transfer_in_cost,
                    $s->total_cost,
                    $s->volume_gallons_at_calc,
                    $s->cost_per_gallon,
                    $s->bottles_produced,
                    $s->cost_per_bottle,
                    $s->cost_per_case,
                    $s->packaging_cost_per_bottle,
                    $s->calculated_at,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
