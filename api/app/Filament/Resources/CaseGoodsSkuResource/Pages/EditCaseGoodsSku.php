<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaseGoodsSkuResource\Pages;

use App\Filament\Resources\CaseGoodsSkuResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCaseGoodsSku extends EditRecord
{
    protected static string $resource = CaseGoodsSkuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
