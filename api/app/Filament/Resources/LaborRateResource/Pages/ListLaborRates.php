<?php

declare(strict_types=1);

namespace App\Filament\Resources\LaborRateResource\Pages;

use App\Filament\Resources\LaborRateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLaborRates extends ListRecords
{
    protected static string $resource = LaborRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
