<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkOrderResource\Pages;

use App\Filament\Resources\WorkOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkOrders extends ListRecords
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
