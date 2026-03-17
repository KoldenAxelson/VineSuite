<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\Event;

/**
 * Part III Calculator — Wine Received in Bond.
 *
 * Aggregates events into TTB Form 5120.17 Section A lines 7-10:
 *   - Line 7: Received from bonded wine premises (stock_received events)
 *   - Line 8: Received from customs (wine_received_customs events)
 *   - Line 9: Wine returned to bond from bottled account (wine_returned_to_bulk events)
 *   - Line 10: Other wine received (wine_received_other events)
 *
 * Each line may have multiple entries — one per wine type column (a-f).
 * All volumes in wine gallons, rounded to whole gallons per TTB practice.
 */
class PartThreeCalculator
{
    public function __construct(
        private readonly WineTypeClassifier $classifier,
    ) {}

    /**
     * Calculate Part III line items for a given reporting period.
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    public function calculate(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $lines = [];

        // Line 7: Wine received from bonded wine premises
        $lines = array_merge($lines, $this->calculateReceiptLine(
            $from, $to,
            operationType: 'stock_received',
            lineNumber: 7,
            category: 'wine_received_transfer',
            description: 'Wine received from bonded wine premises',
        ));

        // Line 8: Wine received from customs
        $lines = array_merge($lines, $this->calculateReceiptLine(
            $from, $to,
            operationType: 'wine_received_customs',
            lineNumber: 8,
            category: 'wine_received_customs',
            description: 'Wine received from customs',
        ));

        // Line 9: Wine returned to bond from bottled wine account (Section B → Section A)
        $lines = array_merge($lines, $this->calculateReceiptLine(
            $from, $to,
            operationType: 'wine_returned_to_bulk',
            lineNumber: 9,
            category: 'wine_returned_to_bond',
            description: 'Wine returned to bond from bottled account',
        ));

        // Line 10: Other wine received
        $lines = array_merge($lines, $this->calculateReceiptLine(
            $from, $to,
            operationType: 'wine_received_other',
            lineNumber: 10,
            category: 'wine_received_other',
            description: 'Other wine received',
        ));

        return $lines;
    }

    /**
     * Get total gallons received across all wine types.
     *
     * @param  array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $lines
     */
    public function totalGallons(array $lines): float
    {
        return round(array_sum(array_column($lines, 'gallons')), 0);
    }

    /**
     * Calculate a single receipt line from events of a given operation type.
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateReceiptLine(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $operationType,
        int $lineNumber,
        string $category,
        string $description,
    ): array {
        $events = Event::ofType($operationType)
            ->performedBetween($from, $to)
            ->get();

        $grouped = $this->aggregateByWineType($events);
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
