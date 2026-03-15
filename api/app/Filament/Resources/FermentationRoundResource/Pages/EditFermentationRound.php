<?php

declare(strict_types=1);

namespace App\Filament\Resources\FermentationRoundResource\Pages;

use App\Filament\Resources\FermentationRoundResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFermentationRound extends EditRecord
{
    protected static string $resource = FermentationRoundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }
}
