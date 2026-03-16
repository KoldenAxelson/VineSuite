<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaseGoodsSkuResource\Pages;

use App\Filament\Resources\CaseGoodsSkuResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCaseGoodsSku extends ViewRecord
{
    protected static string $resource = CaseGoodsSkuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
