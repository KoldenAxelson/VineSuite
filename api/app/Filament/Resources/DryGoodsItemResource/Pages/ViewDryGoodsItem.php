<?php

declare(strict_types=1);

namespace App\Filament\Resources\DryGoodsItemResource\Pages;

use App\Filament\Resources\DryGoodsItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDryGoodsItem extends ViewRecord
{
    protected static string $resource = DryGoodsItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
