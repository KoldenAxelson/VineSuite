<?php

declare(strict_types=1);

namespace App\Filament\Resources\TTBReportResource\Pages;

use App\Filament\Resources\TTBReportResource;
use App\Models\Event;
use App\Models\TTBReport;
use App\Models\TTBReportLine;
use App\Services\EventLogger;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

/**
 * TTB Report Review page — the winemaker reviews auto-generated report
 * data, drills into line items, and approves for filing.
 */
class ReviewTTBReport extends Page
{
    protected static string $resource = TTBReportResource::class;

    protected static string $view = 'filament.pages.ttb-report-review';

    protected static ?string $title = 'Review TTB Report';

    public TTBReport $record;

    /** @var array<string, mixed> */
    public array $reportData = [];

    /** @var array<int, array<string, mixed>> */
    public array $drillDownEvents = [];

    public ?string $selectedLineId = null;

    public function mount(TTBReport $record): void
    {
        $this->record = $record;
        $this->reportData = $record->data ?? [];
    }

    /**
     * Get all line items grouped by part.
     *
     * @return array<string, \Illuminate\Database\Eloquent\Collection<int, TTBReportLine>>
     */
    public function getLinesByPart(): array
    {
        $lines = $this->record->lines()->orderBy('part')->orderBy('line_number')->get();

        $grouped = [];
        foreach (['I', 'II', 'III', 'IV', 'V'] as $part) {
            $grouped[$part] = $lines->where('part', $part)->values();
        }

        return $grouped;
    }

    /**
     * Get the Part I summary from the stored report data.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return $this->reportData['part_one']['summary'] ?? [];
    }

    /**
     * Get review flags from the report data.
     *
     * @return array<int, string>
     */
    public function getReviewFlags(): array
    {
        return $this->reportData['review_flags'] ?? [];
    }

    /**
     * Drill into a specific line item to see source events.
     */
    public function drillDown(string $lineId): void
    {
        $this->selectedLineId = $lineId;

        $line = TTBReportLine::find($lineId);
        if ($line === null) {
            $this->drillDownEvents = [];

            return;
        }

        $eventIds = $line->source_event_ids ?? [];
        if (empty($eventIds)) {
            $this->drillDownEvents = [];

            return;
        }

        $events = Event::whereIn('id', $eventIds)
            ->orderBy('performed_at')
            ->get();

        $this->drillDownEvents = $events->map(fn (Event $e) => [
            'id' => $e->id,
            'operation_type' => $e->operation_type,
            'entity_type' => $e->entity_type,
            'performed_at' => $e->performed_at->format('M j, Y g:i A'),
            'payload' => $e->payload,
        ])->toArray();
    }

    /**
     * Add a note to a specific line item.
     */
    public function addLineNote(string $lineId, string $note): void
    {
        $line = TTBReportLine::find($lineId);
        if ($line !== null) {
            $line->update(['notes' => $note]);

            Notification::make()
                ->title('Note saved')
                ->success()
                ->send();
        }
    }

    /**
     * Approve the report — changes status to 'reviewed'.
     */
    public function approveReport(): void
    {
        if (! $this->record->canReview()) {
            Notification::make()
                ->title('Cannot approve')
                ->body('This report has already been reviewed or filed.')
                ->danger()
                ->send();

            return;
        }

        $this->record->update([
            'status' => 'reviewed',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        app(EventLogger::class)->log(
            entityType: 'ttb_report',
            entityId: $this->record->id,
            operationType: 'ttb_report_reviewed',
            payload: [
                'report_id' => $this->record->id,
                'period_month' => $this->record->report_period_month,
                'period_year' => $this->record->report_period_year,
                'reviewed_by' => auth()->id(),
            ],
            performedBy: auth()->id(),
            performedAt: now(),
        );

        Notification::make()
            ->title('Report Approved')
            ->body('TTB report for '.$this->record->periodLabel().' has been approved.')
            ->success()
            ->send();

        $this->redirect(TTBReportResource::getUrl('view', ['record' => $this->record]));
    }
}
