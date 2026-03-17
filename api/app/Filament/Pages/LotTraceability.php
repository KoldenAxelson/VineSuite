<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Lot;
use App\Services\TTB\LotTraceabilityService;
use Filament\Pages\Page;

/**
 * LotTraceability — full lot trace from grape source to final sale.
 *
 * Renders the one-step-back / one-step-forward trace chain
 * for FDA traceability and recall documentation.
 */
class LotTraceability extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Lot Traceability';

    protected static ?string $title = 'Lot Traceability';

    protected static string $view = 'filament.pages.lot-traceability';

    public ?string $selectedLotId = null;

    /** @var array<string, mixed> */
    public array $traceData = [];

    /**
     * Get all lots for the selector dropdown.
     *
     * @return array<string, string>
     */
    public function getLotOptions(): array
    {
        return Lot::orderBy('name')
            ->get()
            ->mapWithKeys(fn (Lot $lot) => [
                $lot->id => $lot->name.' ('.$lot->variety.', '.$lot->vintage.')',
            ])
            ->toArray();
    }

    /**
     * Run the trace for the selected lot.
     */
    public function runTrace(): void
    {
        if ($this->selectedLotId === null) {
            return;
        }

        $service = app(LotTraceabilityService::class);
        $this->traceData = $service->trace($this->selectedLotId);
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
