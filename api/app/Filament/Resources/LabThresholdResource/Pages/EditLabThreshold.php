<?php

declare(strict_types=1);

namespace App\Filament\Resources\LabThresholdResource\Pages;

use App\Filament\Resources\LabThresholdResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLabThreshold extends EditRecord
{
    protected static string $resource = LabThresholdResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
