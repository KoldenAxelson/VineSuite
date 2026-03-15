<?php

declare(strict_types=1);

namespace App\Filament\Resources\LotResource\Pages;

use App\Filament\Resources\LotResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLot extends CreateRecord
{
    protected static string $resource = LotResource::class;
}
