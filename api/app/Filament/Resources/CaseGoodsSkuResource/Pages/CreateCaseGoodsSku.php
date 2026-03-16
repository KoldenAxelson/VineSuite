<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaseGoodsSkuResource\Pages;

use App\Filament\Resources\CaseGoodsSkuResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCaseGoodsSku extends CreateRecord
{
    protected static string $resource = CaseGoodsSkuResource::class;
}
