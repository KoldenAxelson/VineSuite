<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlendTrialResource\Pages;

use App\Filament\Resources\BlendTrialResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBlendTrial extends ViewRecord
{
    protected static string $resource = BlendTrialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
