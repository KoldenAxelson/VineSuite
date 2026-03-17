<?php

declare(strict_types=1);

namespace App\Filament\Resources\TTBReportResource\Pages;

use App\Filament\Resources\TTBReportResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewTTBReport extends ViewRecord
{
    protected static string $resource = TTBReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('review')
                ->label('Review & Approve')
                ->icon('heroicon-o-check-badge')
                ->url(fn () => TTBReportResource::getUrl('review', ['record' => $this->record]))
                ->visible(function (): bool {
                    $record = $this->record;

                    return $record instanceof \App\Models\TTBReport && $record->canReview();
                }),
        ];
    }
}
