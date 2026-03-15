<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlendTrialResource\Pages;

use App\Filament\Resources\BlendTrialResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBlendTrial extends EditRecord
{
    protected static string $resource = BlendTrialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
