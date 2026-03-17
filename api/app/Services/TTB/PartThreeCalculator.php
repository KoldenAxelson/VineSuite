<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\Event;

/**
 * Part III Calculator — Wine Received in Bond.
 *
 * Aggregates events where wine was received into the bonded premises:
 *   - Transfers in from other bonded premises
 *   - Wine received by purchase
 *
 * All volumes in wine gallons, rounded to nearest tenth.
 */
class PartThreeCalculator
{
    public function __construct(
        private readonly WineTypeClassifier $classifier,
    ) {}

    /**
     * Calculate Part III line items for a given reporting period.
     *
     * @return array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    public function calculate(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $lines = [];
        $lineNumber = 1;

        // Wine received by transfer — stock_received events with transfer context
        // In the current system, incoming transfers would be stock_received events
        $receivedEvents = Event::ofType('stock_received')
            ->performedBetween($from, $to)
            ->get();

        $receivedByType = $this->aggregateByWineType($receivedEvents);

        foreach ($receivedByType as $wineType => $data) {
            $lines[] = [
                'line_number' => $lineNumber++,
                'category' => 'wine_received_transfer',
                'wine_type' => $wineType,
                'description' => 'Wine received by transfer — '.ucfirst(str_replace('_', ' ', $wineType)).' wine',
                'gallons' => round($data['gallons'], 1),
                'source_event_ids' => $data['event_ids'],
                'needs_review' => $data['needs_review'],
            ];
        }

        return $lines;
    }

    /**
     * Get total gallons received across all wine types.
     *
     * @param  array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $lines
     */
    public function totalGallons(array $lines): float
    {
        return round(array_sum(array_column($lines, 'gallons')), 1);
    }

    /**
     * Aggregate received events by wine type.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Event>  $events
     * @return array<string, array{gallons: float, event_ids: array<int, string>, needs_review: bool}>
     */
    private function aggregateByWineType(\Illuminate\Database\Eloquent\Collection $events): array
    {
        $grouped = [];

        foreach ($events as $event) {
            $payload = $event->payload;

            // stock_received events use 'quantity' for case count, not gallons directly
            // For TTB purposes, we need gallons. Use volume_gallons if present,
            // otherwise this event type doesn't represent bulk wine received.
            $volume = (float) ($payload['volume_gallons'] ?? 0);

            if ($volume <= 0) {
                continue;
            }

            $lotId = $payload['lot_id'] ?? $event->entity_id;
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
