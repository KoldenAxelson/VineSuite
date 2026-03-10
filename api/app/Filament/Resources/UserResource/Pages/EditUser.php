<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No delete action — users are deactivated, not deleted
        ];
    }

    protected function afterSave(): void
    {
        // Sync the spatie role when the role column is changed
        $user = $this->record;
        $user->syncRoles([$user->role]);
    }
}
