<?php

declare(strict_types=1);

namespace App\Filament\Resources\LaborRateResource\Pages;

use App\Filament\Resources\LaborRateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLaborRate extends CreateRecord
{
    protected static string $resource = LaborRateResource::class;
}
