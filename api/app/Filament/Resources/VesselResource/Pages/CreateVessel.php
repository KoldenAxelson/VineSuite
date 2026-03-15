<?php

declare(strict_types=1);

namespace App\Filament\Resources\VesselResource\Pages;

use App\Filament\Resources\VesselResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVessel extends CreateRecord
{
    protected static string $resource = VesselResource::class;
}
