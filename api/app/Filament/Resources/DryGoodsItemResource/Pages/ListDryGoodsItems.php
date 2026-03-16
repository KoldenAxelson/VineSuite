<?php

declare(strict_types=1);

namespace App\Filament\Resources\DryGoodsItemResource\Pages;

use App\Filament\Resources\DryGoodsItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDryGoodsItems extends ListRecords
{
    protected static string $resource = DryGoodsItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
