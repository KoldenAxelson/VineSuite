<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlendTrialResource\Pages;

use App\Filament\Resources\BlendTrialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlendTrials extends ListRecords
{
    protected static string $resource = BlendTrialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
