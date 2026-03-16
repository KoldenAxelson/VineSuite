<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FermentationEntry;
use App\Models\FermentationRound;
use App\Support\LogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FermentationService — business logic for fermentation rounds and daily entries.
 *
 * Manages the fermentation lifecycle: create round → daily entries → complete/stuck.
 * Each entry writes a `fermentation_data_entered` event. Round completion writes
 * a `fermentation_completed` event. Event payloads are self-contained with
 * lot name/variety per the data-portability design constraint.
 */
class FermentationService
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * Create a new fermentation round for a lot.
     *
     * @param  array<string, mixed>  $data  Validated round data
     * @param  string  $createdBy  UUID of the user
     */
    public function createRound(array $data, string $createdBy): FermentationRound
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $data['created_by'] = $createdBy;

            $round = FermentationRound::create($data);
            $round->load('lot');

            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $round->lot_id,
                operationType: 'fermentation_round_created',
                payload: [
                    'round_id' => $round->id,
                    'lot_name' => $round->lot->name,
                    'lot_variety' => $round->lot->variety,
                    'fermentation_type' => $round->fermentation_type,
                    'round_number' => $round->round_number,
                    'inoculation_date' => $round->inoculation_date->toDateString(),
                    'yeast_strain' => $round->yeast_strain,
                    'ml_bacteria' => $round->ml_bacteria,
                    'target_temp' => $round->target_temp ? (float) $round->target_temp : null,
                ],
                performedBy: $createdBy,
                performedAt: $round->inoculation_date,
            );

            Log::info('Fermentation round created', LogContext::with([
                'round_id' => $round->id,
                'lot_id' => $round->lot_id,
                'fermentation_type' => $round->fermentation_type,
                'round_number' => $round->round_number,
            ], $createdBy));

            return $round;
        });
    }

    /**
     * Record a daily fermentation entry.
     *
     * @param  array<string, mixed>  $data  Validated entry data
     * @param  string  $performedBy  UUID of the user
     */
    public function addEntry(array $data, string $performedBy): FermentationEntry
    {
        return DB::transaction(function () use ($data, $performedBy) {
            $data['performed_by'] = $performedBy;

            $entry = FermentationEntry::create($data);
            $entry->load('round.lot');

            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $entry->round->lot_id,
                operationType: 'fermentation_data_entered',
                payload: [
                    'entry_id' => $entry->id,
                    'round_id' => $entry->fermentation_round_id,
                    'lot_name' => $entry->round->lot->name,
                    'lot_variety' => $entry->round->lot->variety,
                    'fermentation_type' => $entry->round->fermentation_type,
                    'entry_date' => $entry->entry_date->toDateString(),
                    'temperature' => $entry->temperature !== null ? (float) $entry->temperature : null,
                    'brix_or_density' => $entry->brix_or_density !== null ? (float) $entry->brix_or_density : null,
                    'measurement_type' => $entry->measurement_type,
                    'free_so2' => $entry->free_so2 !== null ? (float) $entry->free_so2 : null,
                ],
                performedBy: $performedBy,
                performedAt: $entry->entry_date,
            );

            Log::info('Fermentation entry recorded', LogContext::with([
                'entry_id' => $entry->id,
                'round_id' => $entry->fermentation_round_id,
                'lot_id' => $entry->round->lot_id,
                'entry_date' => $entry->entry_date->toDateString(),
            ], $performedBy));

            return $entry;
        });
    }

    /**
     * Mark a fermentation round as completed.
     *
     * @param  string  $completedBy  UUID of the user
     */
    public function completeRound(FermentationRound $round, string $completedBy, ?string $completionDate = null): FermentationRound
    {
        return DB::transaction(function () use ($round, $completedBy, $completionDate) {
            $round->update([
                'status' => 'completed',
                'completion_date' => $completionDate ?? now()->toDateString(),
            ]);

            $round->load('lot');

            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $round->lot_id,
                operationType: 'fermentation_completed',
                payload: [
                    'round_id' => $round->id,
                    'lot_name' => $round->lot->name,
                    'lot_variety' => $round->lot->variety,
                    'fermentation_type' => $round->fermentation_type,
                    'round_number' => $round->round_number,
                    'completion_date' => $round->completion_date->toDateString(),
                    'total_entries' => $round->entries()->count(),
                ],
                performedBy: $completedBy,
                performedAt: $round->completion_date,
            );

            Log::info('Fermentation round completed', LogContext::with([
                'round_id' => $round->id,
                'lot_id' => $round->lot_id,
                'fermentation_type' => $round->fermentation_type,
                'completion_date' => $round->completion_date->toDateString(),
            ], $completedBy));

            return $round;
        });
    }

    /**
     * Mark a fermentation round as stuck.
     *
     * @param  string  $reportedBy  UUID of the user
     */
    public function markStuck(FermentationRound $round, string $reportedBy): FermentationRound
    {
        $round->update(['status' => 'stuck']);

        Log::warning('Fermentation round marked stuck', LogContext::with([
            'round_id' => $round->id,
            'lot_id' => $round->lot_id,
            'fermentation_type' => $round->fermentation_type,
        ], $reportedBy));

        return $round;
    }

    /**
     * Confirm ML fermentation dryness (malolactic-specific).
     *
     * @param  string  $confirmedBy  UUID of the user
     */
    public function confirmMlDryness(FermentationRound $round, string $confirmedBy, ?string $confirmationDate = null): FermentationRound
    {
        $round->update([
            'confirmation_date' => $confirmationDate ?? now()->toDateString(),
        ]);

        Log::info('ML dryness confirmed', LogContext::with([
            'round_id' => $round->id,
            'lot_id' => $round->lot_id,
            'confirmation_date' => $round->confirmation_date->toDateString(),
        ], $confirmedBy));

        return $round;
    }
}
