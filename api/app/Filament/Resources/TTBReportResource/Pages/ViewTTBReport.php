<?php

declare(strict_types=1);

namespace App\Filament\Resources\TTBReportResource\Pages;

use App\Filament\Resources\TTBReportResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Redirects to the Review page which has the full report UI.
 *
 * The Review page handles all statuses (draft, reviewed, filed) —
 * it only shows the "Approve" button for drafts.
 */
class ViewTTBReport extends ViewRecord
{
    protected static string $resource = TTBReportResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->redirect(TTBReportResource::getUrl('review', ['record' => $this->record]));
    }
}
