<?php

declare(strict_types=1);

namespace App\Filament\Resources\LotResource\Pages;

use App\Filament\Resources\LotResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLots extends ListRecords
{
    protected static string $resource = LotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
