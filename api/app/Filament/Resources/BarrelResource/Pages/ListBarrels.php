<?php

declare(strict_types=1);

namespace App\Filament\Resources\BarrelResource\Pages;

use App\Filament\Resources\BarrelResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBarrels extends ListRecords
{
    protected static string $resource = BarrelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
