<?php

declare(strict_types=1);

namespace App\Filament\Resources\TransferResource\Pages;

use App\Filament\Resources\TransferResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTransfer extends CreateRecord
{
    protected static string $resource = TransferResource::class;
}
