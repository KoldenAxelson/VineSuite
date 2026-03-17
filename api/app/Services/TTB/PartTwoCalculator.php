<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\Event;

/**
 * Part II Calculator — Wine Produced by Fermentation or Other Process.
 *
 * Aggregates production events into TTB Form 5120.17 Section A lines 2-6:
 *   - Line 2: Produced by fermentation (lot_created events)
 *   - Line 3: Produced by sweetening (sweetening_completed events)
 *   - Line 4: Produced by use of wine spirits (fortification_completed events)
 *   - Line 5: Produced by blending (blend_finalized events)
 *   - Line 6: Produced by amelioration (amelioration_completed events)
 *
 * Each line may have multiple entries — one per wine type column (a-f).
 * All volumes in wine gallons, rounded to whole gallons per TTB practice.
 */
class PartTwoCalculator
{
    public function __construct(
        private readonly WineTypeClassifier $classifier,
    ) {}

    /**
     * Calculate Part II line items for a given reporting period.
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    public function calculate(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $lines = [];

        // Line 2: Wine produced by fermentation — from lot_created events (initial crush volume)
        $lines = array_merge($lines, $this->calculateProductionLine(
            $from, $to,
            operationType: 'lot_created',
            volumeField: 'initial_volume',
            lineNumber: 2,
            category: 'wine_produced',
            description: 'Wine produced by fermentation',
        ));

        // Line 3: Wine produced by sweetening
        $lines = array_merge($lines, $this->calculateProductionLine(
            $from, $to,
            operationType: 'sweetening_completed',
            volumeField: 'volume_produced',
            lineNumber: 3,
            category: 'wine_produced_sweetening',
            description: 'Wine produced by sweetening',
        ));

        // Line 4: Wine produced by use of wine spirits (fortification)
        $lines = array_merge($lines, $this->calculateProductionLine(
            $from, $to,
            operationType: 'fortification_completed',
            volumeField: 'volume_produced',
            lineNumber: 4,
            category: 'wine_produced_spirits',
            description: 'Wine produced by use of wine spirits',
        ));

        // Line 5: Wine produced by blending (new volume created from blends)
        $lines = array_merge($lines, $this->calculateProductionLine(
            $from, $to,
            operationType: 'blend_finalized',
            volumeField: 'total_volume_gallons',
            lineNumber: 5,
            category: 'wine_produced_blending',
            description: 'Wine produced by blending',
        ));

        // Line 6: Wine produced by amelioration
        $lines = array_merge($lines, $this->calculateProductionLine(
            $from, $to,
            operationType: 'amelioration_completed',
            volumeField: 'volume_produced',
            lineNumber: 6,
            category: 'wine_produced_amelioration',
            description: 'Wine produced by amelioration',
        ));

        return $lines;
    }

    /**
     * Get total gallons produced across all wine types.
     *
     * @param  array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $lines
     */
    public function totalGallons(array $lines): float
    {
        return round(array_sum(array_column($lines, 'gallons')), 0);
    }

    /**
     * Calculate a single production line from events of a given operation type.
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateProductionLine(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $operationType,
        string $volumeField,
        int $lineNumber,
        string $category,
        string $description,
    ): array {
        $events = Event::ofType($operationType)
            ->performedBetween($from, $to)
            ->get();

        $grouped = $this->aggregateByWineType($events, $volumeField, $operationType);
        $lines = [];

        foreach ($grouped as $wineType => $data) {
            $lines[] = [
                'line_number' => $lineNumber,
                'section' => 'A',
                'category' => $category,
                'wine_type' => $wineType,
                'description' => $description.' — '.(WineTypeClassifier::COLUMN_LABELS[$wineType] ?? $wineType),
                'gallons' => round($data['gallons'], 0),
                'source_event_ids' => $data['event_ids'],
                'needs_review' => $data['needs_review'],
            ];
        }

        return $lines;
    }

    /**
     * Aggregate events by wine type, summing volume from the specified payload field.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Event>  $events
     * @return array<string, array{gallons: float, event_ids: array<int, string>, needs_review: bool}>
     */
    private function aggregateByWineType(\Illuminate\Database\Eloquent\Collection $events, string $volumeField, string $operationType): array
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
            if ($operationType === 'blend_finalized') {
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
