<?php

declare(strict_types=1);

namespace App\Filament\Resources\VesselResource\Pages;

use App\Filament\Resources\VesselResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVessels extends ListRecords
{
    protected static string $resource = VesselResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
