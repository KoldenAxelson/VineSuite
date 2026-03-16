<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SensoryNote;
use App\Support\LogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SensoryNoteService — business logic for winemaker tasting notes.
 *
 * Lightweight internal tasting notes — not wine-review-style scoring.
 * Each note writes a `sensory_note_recorded` event with self-contained
 * payload (lot name/variety, taster name) per the data-portability
 * design constraint.
 */
class SensoryNoteService
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * Record a sensory/tasting note for a lot.
     *
     * @param  array<string, mixed>  $data  Validated note data
     * @param  string  $tasterId  UUID of the taster
     */
    public function createNote(array $data, string $tasterId): SensoryNote
    {
        return DB::transaction(function () use ($data, $tasterId) {
            $data['taster_id'] = $tasterId;

            $note = SensoryNote::create($data);
            $note->load(['lot', 'taster']);

            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $note->lot_id,
                operationType: 'sensory_note_recorded',
                payload: [
                    'note_id' => $note->id,
                    'lot_name' => $note->lot->name,
                    'lot_variety' => $note->lot->variety,
                    'taster_name' => $note->taster->name,
                    'date' => $note->date->toDateString(),
                    'rating' => $note->rating !== null ? (float) $note->rating : null,
                    'rating_scale' => $note->rating_scale,
                    'has_nose_notes' => $note->nose_notes !== null,
                    'has_palate_notes' => $note->palate_notes !== null,
                    'has_overall_notes' => $note->overall_notes !== null,
                ],
                performedBy: $tasterId,
                performedAt: $note->date,
            );

            Log::info('Sensory note recorded', LogContext::with([
                'note_id' => $note->id,
                'lot_id' => $note->lot_id,
                'rating' => $note->rating,
                'rating_scale' => $note->rating_scale,
            ], $tasterId));

            return $note;
        });
    }
}
