<?php

declare(strict_types=1);

namespace App\Filament\Resources\DryGoodsItemResource\Pages;

use App\Filament\Resources\DryGoodsItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDryGoodsItem extends CreateRecord
{
    protected static string $resource = DryGoodsItemResource::class;
}
