<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdditionResource\Pages;

use App\Filament\Resources\AdditionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAddition extends CreateRecord
{
    protected static string $resource = AdditionResource::class;
}
