<?php

declare(strict_types=1);

namespace App\Filament\Resources\FermentationRoundResource\Pages;

use App\Filament\Resources\FermentationRoundResource;
use App\Filament\Widgets\FermentationCurveChart;
use Filament\Resources\Pages\ViewRecord;

class ViewFermentationRound extends ViewRecord
{
    protected static string $resource = FermentationRoundResource::class;

    protected function getFooterWidgets(): array
    {
        return [
            FermentationCurveChart::make([
                'roundId' => $this->record->getKey(),
            ]),
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
