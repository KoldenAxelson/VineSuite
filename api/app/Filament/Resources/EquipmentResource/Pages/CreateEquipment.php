<?php

declare(strict_types=1);

namespace App\Filament\Resources\EquipmentResource\Pages;

use App\Filament\Resources\EquipmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEquipment extends CreateRecord
{
    protected static string $resource = EquipmentResource::class;
}
