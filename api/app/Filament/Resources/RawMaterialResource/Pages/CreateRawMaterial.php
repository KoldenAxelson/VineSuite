<?php

declare(strict_types=1);

namespace App\Filament\Resources\RawMaterialResource\Pages;

use App\Filament\Resources\RawMaterialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRawMaterial extends CreateRecord
{
    protected static string $resource = RawMaterialResource::class;
}
