<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\CaseGoodsSkuResource;
use App\Filament\Widgets\PhysicalCountStatsWidget;
use App\Models\PhysicalCount as PhysicalCountModel;
use App\Models\PhysicalCountLine;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PhysicalCount extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static \UnitEnum|string|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Physical Count';

    protected static ?string $title = 'Physical Inventory Count';

    protected string $view = 'filament.pages.physical-count';

    public ?string $countId = null;

    protected function getHeaderWidgets(): array
    {
        if (! $this->countId) {
            return [];
        }

        return [
            PhysicalCountStatsWidget::make(['countId' => $this->countId]),
        ];
    }

    public function mount(): void
    {
        $this->countId = request()->query('count_id');
    }

    public function getTitle(): string
    {
        if ($this->countId) {
            $count = PhysicalCountModel::with('location')->find($this->countId);
            if ($count) {
                $status = ucfirst(str_replace('_', ' ', $count->status));

                return $count->location->name.' — '.$status.' Count';
            }
        }

        return 'Physical Inventory Count';
    }

    /**
     * Summary data for the detail view header.
     *
     * @return array<string, mixed>|null
     */
    public function getCountSummary(): ?array
    {
        if (! $this->countId) {
            return null;
        }

        $count = PhysicalCountModel::with(['location', 'starter', 'completer'])->find($this->countId);
        if (! $count) {
            return null;
        }

        $lines = PhysicalCountLine::where('physical_count_id', $count->id)->get();
        $counted = $lines->whereNotNull('counted_quantity')->count();
        $total = $lines->count();
        $varianceCount = $lines->where('variance', '!=', 0)->whereNotNull('variance')->count();

        return [
            'location' => $count->location->name,
            'status' => $count->status,
            'started_at' => $count->started_at->format('M j, Y g:i A'),
            'started_by' => $count->starter->name,
            'completed_at' => $count->completed_at?->format('M j, Y g:i A'),
            'completed_by' => $count->completer?->name,
            'notes' => $count->notes,
            'progress' => $counted.'/'.$total.' SKUs counted',
            'variances' => $varianceCount,
        ];
    }

    public function table(Table $table): Table
    {
        if ($this->countId) {
            return $this->countLinesTable($table);
        }

        return $this->countsListTable($table);
    }

    /**
     * List table showing all physical count sessions.
     */
    private function countsListTable(Table $table): Table
    {
        return $table
            ->query(PhysicalCountModel::query()->with(['location']))
            ->columns([
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Location')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'in_progress' => 'warning', 'completed' => 'success', 'cancelled' => 'danger', default => 'gray'
                    })
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
                Action::make('view')
                    ->label('View Lines')
                    ->icon('heroicon-o-eye')
                    ->url(fn (PhysicalCountModel $record): string => route('filament.portal.pages.physical-count').'?count_id='.$record->id),
            ]);
    }

    /**
     * Detail table showing count lines for a specific count session.
     */
    private function countLinesTable(Table $table): Table
    {
        return $table
            ->query(
                PhysicalCountLine::query()
                    ->where('physical_count_id', $this->countId)
                    ->with(['sku'])
            )
            ->columns([
                Tables\Columns\TextColumn::make('sku.wine_name')
                    ->label('Wine / SKU')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('sku', function (Builder $q) use ($search) {
                            $q->where('wine_name', 'ilike', "%{$search}%");
                        });
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->join('case_goods_skus', 'physical_count_lines.sku_id', '=', 'case_goods_skus.id')
                            ->orderBy('case_goods_skus.wine_name', $direction)
                            ->select('physical_count_lines.*');
                    })
                    ->weight('bold')
                    ->url(fn (PhysicalCountLine $record): ?string => $record->sku_id
                        ? CaseGoodsSkuResource::getUrl('view', ['record' => $record->sku_id])
                        : null
                    ),

                Tables\Columns\TextColumn::make('sku.varietal')
                    ->label('Varietal')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sku.format')
                    ->label('Format')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('system_quantity')
                    ->label('System Qty')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('counted_quantity')
                    ->label('Counted Qty')
                    ->numeric()
                    ->sortable()
                    ->placeholder('Pending'),

                Tables\Columns\TextColumn::make('variance')
                    ->label('Variance')
                    ->numeric()
                    ->sortable()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state === 0 => 'success',
                        default => 'danger',
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('notes')
                    ->limit(40)
                    ->toggleable()
                    ->placeholder('—'),
            ])
            ->defaultSort('sku.wine_name')
            ->filters([
                Tables\Filters\Filter::make('has_variance')
                    ->label('Variances Only')
                    ->query(fn (Builder $query) => $query->where('variance', '!=', 0)->whereNotNull('variance')),

                Tables\Filters\Filter::make('pending')
                    ->label('Pending Only')
                    ->query(fn (Builder $query) => $query->whereNull('counted_quantity')),
            ])
            ->actions([])
            ->headerActions([
                Action::make('back')
                    ->label('← All Counts')
                    ->url(route('filament.portal.pages.physical-count'))
                    ->color('gray'),
            ]);
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
