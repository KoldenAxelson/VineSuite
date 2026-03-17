<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\Event;

/**
 * Part II Calculator — Wine Produced by Fermentation or Other Process.
 *
 * Aggregates production events that create new wine volume within the reporting period.
 * This covers:
 *   - Lots created (grape reception / crush)
 *   - Fermentation completed (volume at end of fermentation)
 *
 * All volumes in wine gallons, rounded to nearest tenth.
 */
class PartTwoCalculator
{
    public function __construct(
        private readonly WineTypeClassifier $classifier,
    ) {}

    /**
     * Calculate Part II line items for a given reporting period.
     *
     * @return array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    public function calculate(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $lines = [];

        // Line 1-4: Wine produced — from lot_created events (initial crush volume)
        $lotCreatedEvents = Event::ofType('lot_created')
            ->performedBetween($from, $to)
            ->get();

        $producedByType = $this->aggregateByWineType($lotCreatedEvents, 'initial_volume');

        $lineNumber = 1;
        foreach ($producedByType as $wineType => $data) {
            $lines[] = [
                'line_number' => $lineNumber++,
                'category' => 'wine_produced',
                'wine_type' => $wineType,
                'description' => 'Wine produced by fermentation — '.ucfirst(str_replace('_', ' ', $wineType)).' wine',
                'gallons' => round($data['gallons'], 1),
                'source_event_ids' => $data['event_ids'],
                'needs_review' => $data['needs_review'],
            ];
        }

        // Line 5-8: Wine produced by blending (new volume created from blends)
        $blendEvents = Event::ofType('blend_finalized')
            ->performedBetween($from, $to)
            ->get();

        $blendedByType = $this->aggregateByWineType($blendEvents, 'total_volume_gallons');

        foreach ($blendedByType as $wineType => $data) {
            $lines[] = [
                'line_number' => $lineNumber++,
                'category' => 'wine_produced_blending',
                'wine_type' => $wineType,
                'description' => 'Wine produced by blending — '.ucfirst(str_replace('_', ' ', $wineType)).' wine',
                'gallons' => round($data['gallons'], 1),
                'source_event_ids' => $data['event_ids'],
                'needs_review' => $data['needs_review'],
            ];
        }

        return $lines;
    }

    /**
     * Get total gallons produced across all wine types.
     *
     * @param  array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $lines
     */
    public function totalGallons(array $lines): float
    {
        return round(array_sum(array_column($lines, 'gallons')), 1);
    }

    /**
     * Aggregate events by wine type, summing volume from the specified payload field.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Event>  $events
     * @return array<string, array{gallons: float, event_ids: array<int, string>, needs_review: bool}>
     */
    private function aggregateByWineType(\Illuminate\Database\Eloquent\Collection $events, string $volumeField): array
    {
        $grouped = [];

        foreach ($events as $event) {
            $payload = $event->payload;
            $volume = (float) ($payload[$volumeField] ?? 0);

            if ($volume <= 0) {
                continue;
            }

            $lotId = $event->entity_id;
            // For blend events, the entity is the new lot
            if ($event->operation_type === 'blend_finalized') {
                $lotId = $payload['new_lot_id'] ?? $event->entity_id;
            }

            $classification = $this->classifier->classify($lotId, $payload);
            $wineType = $classification['type'];

            if (! isset($grouped[$wineType])) {
                $grouped[$wineType] = [
                    'gallons' => 0.0,
                    'event_ids' => [],
                    'needs_review' => false,
                ];
            }

            $grouped[$wineType]['gallons'] += $volume;
            $grouped[$wineType]['event_ids'][] = $event->id;
            if ($classification['needs_review']) {
                $grouped[$wineType]['needs_review'] = true;
            }
        }

        return $grouped;
    }
}
