<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkOrderResource\Pages;

use App\Filament\Resources\WorkOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkOrder extends EditRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
