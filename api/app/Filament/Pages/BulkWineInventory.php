<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class BulkWineInventory extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Bulk Wine Inventory';

    protected static ?string $title = 'Bulk Wine Inventory';

    protected static string $view = 'filament.pages.bulk-wine-inventory';

    /**
     * Summary stats displayed above the table.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $stats = DB::table('lot_vessel')
            ->whereNull('emptied_at')
            ->selectRaw('COUNT(DISTINCT lot_id) as active_lot_count')
            ->selectRaw('COUNT(DISTINCT vessel_id) as active_vessel_count')
            ->selectRaw('COALESCE(SUM(volume_gallons), 0) as total_gallons_in_vessels')
            ->first();

        $lotBookTotal = DB::table('lots')
            ->whereIn('status', ['in_progress', 'aging'])
            ->sum('volume_gallons');

        return [
            'total_gallons_in_vessels' => number_format((float) $stats->total_gallons_in_vessels, 1),
            'total_gallons_book_value' => number_format((float) $lotBookTotal, 1),
            'variance_gallons' => number_format(round((float) $lotBookTotal - (float) $stats->total_gallons_in_vessels, 4), 1),
            'active_lot_count' => (int) $stats->active_lot_count,
            'active_vessel_count' => (int) $stats->active_vessel_count,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                \App\Models\Lot::query()
                    ->leftJoin('lot_vessel', function ($join) {
                        $join->on('lots.id', '=', 'lot_vessel.lot_id')
                            ->whereNull('lot_vessel.emptied_at');
                    })
                    ->whereIn('lots.status', ['in_progress', 'aging'])
                    ->select([
                        'lots.id',
                        'lots.name',
                        'lots.variety',
                        'lots.vintage',
                        'lots.status',
                        'lots.volume_gallons',
                    ])
                    ->selectRaw('COALESCE(SUM(lot_vessel.volume_gallons), 0) as vessel_volume')
                    ->selectRaw('COUNT(DISTINCT lot_vessel.vessel_id) as vessel_count')
                    ->groupBy('lots.id', 'lots.name', 'lots.variety', 'lots.vintage', 'lots.status', 'lots.volume_gallons')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Lot')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('variety')
                    ->sortable(),

                Tables\Columns\TextColumn::make('vintage')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'primary' => 'in_progress',
                        'success' => 'aging',
                    ])
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),

                Tables\Columns\TextColumn::make('volume_gallons')
                    ->label('Book Volume (gal)')
                    ->numeric(2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('vessel_volume')
                    ->label('Vessel Volume (gal)')
                    ->numeric(2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('vessel_count')
                    ->label('Vessels')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'in_progress' => 'In Progress',
                        'aging' => 'Aging',
                    ]),
            ]);
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
