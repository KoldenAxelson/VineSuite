<?php

declare(strict_types=1);

namespace App\Filament\Resources\OverheadRateResource\Pages;

use App\Filament\Resources\OverheadRateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOverheadRate extends EditRecord
{
    protected static string $resource = OverheadRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
