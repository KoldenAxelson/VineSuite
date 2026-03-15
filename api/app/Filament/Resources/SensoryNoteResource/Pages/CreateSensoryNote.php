<?php

declare(strict_types=1);

namespace App\Filament\Resources\SensoryNoteResource\Pages;

use App\Filament\Resources\SensoryNoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSensoryNote extends CreateRecord
{
    protected static string $resource = SensoryNoteResource::class;
}
