<?php

declare(strict_types=1);

namespace App\Filament\Resources\BottlingRunResource\Pages;

use App\Filament\Resources\BottlingRunResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBottlingRuns extends ListRecords
{
    protected static string $resource = BottlingRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
