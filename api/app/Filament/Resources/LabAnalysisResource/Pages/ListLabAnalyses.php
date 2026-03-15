<?php

declare(strict_types=1);

namespace App\Filament\Resources\LabAnalysisResource\Pages;

use App\Filament\Resources\LabAnalysisResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLabAnalyses extends ListRecords
{
    protected static string $resource = LabAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
