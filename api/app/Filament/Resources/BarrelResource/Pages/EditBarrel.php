<?php

declare(strict_types=1);

namespace App\Filament\Resources\BarrelResource\Pages;

use App\Filament\Resources\BarrelResource;
use Filament\Resources\Pages\EditRecord;

class EditBarrel extends EditRecord
{
    protected static string $resource = BarrelResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
