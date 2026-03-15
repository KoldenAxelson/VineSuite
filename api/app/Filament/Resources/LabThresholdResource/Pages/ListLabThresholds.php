<?php

declare(strict_types=1);

namespace App\Filament\Resources\LabThresholdResource\Pages;

use App\Filament\Resources\LabThresholdResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLabThresholds extends ListRecords
{
    protected static string $resource = LabThresholdResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
