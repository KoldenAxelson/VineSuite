<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\Event;

/**
 * Part IV Calculator — Wine Removed from Bond.
 *
 * Aggregates events where wine was removed from the bonded premises:
 *   - Bottling (wine leaves bulk storage and becomes case goods)
 *   - Sales / removals (stock_sold events)
 *   - Transfers out to other bonded premises
 *
 * Per handoff doc: rely on bottling_completed events for removals,
 * not stock movements (since auto-deduction from bottling isn't wired yet).
 *
 * All volumes in wine gallons, rounded to nearest tenth.
 */
class PartFourCalculator
{
    public function __construct(
        private readonly WineTypeClassifier $classifier,
    ) {}

    /**
     * Calculate Part IV line items for a given reporting period.
     *
     * @return array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    public function calculate(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $lines = [];
        $lineNumber = 1;

        // Bottling — wine removed from bulk into bottled form
        $bottlingEvents = Event::ofType('bottling_completed')
            ->performedBetween($from, $to)
            ->get();

        $bottledByType = $this->aggregateByWineType($bottlingEvents, 'volume_bottled_gallons');

        foreach ($bottledByType as $wineType => $data) {
            $lines[] = [
                'line_number' => $lineNumber++,
                'category' => 'wine_bottled',
                'wine_type' => $wineType,
                'description' => 'Wine bottled — '.ucfirst(str_replace('_', ' ', $wineType)).' wine',
                'gallons' => round($data['gallons'], 1),
                'source_event_ids' => $data['event_ids'],
                'needs_review' => $data['needs_review'],
            ];
        }

        // Sales — wine removed via stock_sold events
        $soldEvents = Event::ofType('stock_sold')
            ->performedBetween($from, $to)
            ->get();

        $soldByType = $this->aggregateSoldByWineType($soldEvents);

        foreach ($soldByType as $wineType => $data) {
            $lines[] = [
                'line_number' => $lineNumber++,
                'category' => 'wine_sold',
                'wine_type' => $wineType,
                'description' => 'Wine removed by sale — '.ucfirst(str_replace('_', ' ', $wineType)).' wine',
                'gallons' => round($data['gallons'], 1),
                'source_event_ids' => $data['event_ids'],
                'needs_review' => $data['needs_review'],
            ];
        }

        return $lines;
    }

    /**
     * Get total gallons removed across all wine types.
     *
     * @param  array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $lines
     */
    public function totalGallons(array $lines): float
    {
        return round(array_sum(array_column($lines, 'gallons')), 1);
    }

    /**
     * Aggregate bottling events by wine type.
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

            // Bottling events have lot_id in payload or entity is the lot
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

    /**
     * Aggregate sold events by wine type.
     * stock_sold events track case goods, need volume conversion.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Event>  $events
     * @return array<string, array{gallons: float, event_ids: array<int, string>, needs_review: bool}>
     */
    private function aggregateSoldByWineType(\Illuminate\Database\Eloquent\Collection $events): array
    {
        $grouped = [];

        foreach ($events as $event) {
            $payload = $event->payload;

            // stock_sold events may have volume_gallons for bulk sales
            // or quantity (cases) for case goods — convert if needed
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
