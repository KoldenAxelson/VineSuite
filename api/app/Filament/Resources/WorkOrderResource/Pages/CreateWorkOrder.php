<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkOrderResource\Pages;

use App\Filament\Resources\WorkOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;
}
