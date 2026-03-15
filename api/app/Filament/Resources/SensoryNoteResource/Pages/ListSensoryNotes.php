<?php

declare(strict_types=1);

namespace App\Filament\Resources\SensoryNoteResource\Pages;

use App\Filament\Resources\SensoryNoteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSensoryNotes extends ListRecords
{
    protected static string $resource = SensoryNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
