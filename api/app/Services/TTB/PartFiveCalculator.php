<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\Event;

/**
 * Part V Calculator — Losses of Wine.
 *
 * Aggregates events that represent wine losses in Section A (bulk wine operations).
 * These map to TTB Form 5120.17 Section A lines 29-30:
 *   - Line 29: Operational losses (transfer variance, bottling waste, filtering, evaporation)
 *   - Line 30: Lees/sediment losses (racking lees)
 *
 * Note: Breakage/spillage is handled by PartFourCalculator (Section A Line 23)
 * as it is a deliberate removal category, not an operational loss.
 *
 * All volumes in wine gallons, rounded to whole gallons per TTB practice.
 */
class PartFiveCalculator
{
    public function __construct(
        private readonly WineTypeClassifier $classifier,
    ) {}

    /**
     * Calculate Part V line items for a given reporting period.
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    public function calculate(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $lines = [];

        // Line 29: Transfer losses (variance during transfer)
        $lines = array_merge($lines, $this->calculateTransferLosses($from, $to));

        // Line 29: Bottling waste losses
        $lines = array_merge($lines, $this->calculateBottlingLosses($from, $to));

        // Line 29: Filtering losses
        $lines = array_merge($lines, $this->calculateFilteringLosses($from, $to));

        // Line 29: Evaporation losses
        $lines = array_merge($lines, $this->calculateEvaporationLosses($from, $to));

        // Line 30: Racking lees losses
        $lines = array_merge($lines, $this->calculateRackingLosses($from, $to));

        return $lines;
    }

    /**
     * Get total gallons of losses.
     *
     * @param  array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $lines
     */
    public function totalGallons(array $lines): float
    {
        return round(array_sum(array_column($lines, 'gallons')), 0);
    }

    /**
     * Calculate transfer variance losses (Line 29).
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateTransferLosses(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $events = Event::ofType('transfer_executed')
            ->performedBetween($from, $to)
            ->get();

        $grouped = [];

        foreach ($events as $event) {
            $payload = $event->payload;
            $variance = (float) ($payload['variance'] ?? 0);

            // Only count negative variance (losses). Positive variance is a gain.
            if ($variance >= 0) {
                continue;
            }

            $lossGallons = abs($variance);
            $lotId = $payload['lot_id'] ?? $event->entity_id;
            $classification = $this->classifier->classify($lotId, $payload);
            $wineType = $classification['type'];

            if (! isset($grouped[$wineType])) {
                $grouped[$wineType] = ['gallons' => 0.0, 'event_ids' => [], 'needs_review' => false];
            }
            $grouped[$wineType]['gallons'] += $lossGallons;
            $grouped[$wineType]['event_ids'][] = $event->id;
            if ($classification['needs_review']) {
                $grouped[$wineType]['needs_review'] = true;
            }
        }

        return $this->formatLines($grouped, 'transfer_loss', 'Loss from transfer variance', 29);
    }

    /**
     * Calculate racking lees losses (Line 30).
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateRackingLosses(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $events = Event::ofType('rack_completed')
            ->performedBetween($from, $to)
            ->get();

        $grouped = [];

        foreach ($events as $event) {
            $payload = $event->payload;
            // lees_weight is in kg/lbs — approximate gallons (1 gallon ≈ 3.785 kg for lees)
            // However, many wineries track lees as volume. Use lees_gallons if present,
            // otherwise estimate from lees_weight.
            $leesGallons = (float) ($payload['lees_gallons'] ?? 0);

            if ($leesGallons <= 0 && isset($payload['lees_weight'])) {
                // Rough conversion: lees are roughly same density as wine
                // 1 gallon ≈ 8.34 lbs. This is an estimate — flag for review.
                $leesGallons = (float) $payload['lees_weight'] / 8.34;
            }

            if ($leesGallons <= 0) {
                continue;
            }

            $lotId = $payload['lot_id'] ?? $event->entity_id;
            $classification = $this->classifier->classify($lotId, $payload);
            $wineType = $classification['type'];

            if (! isset($grouped[$wineType])) {
                $grouped[$wineType] = ['gallons' => 0.0, 'event_ids' => [], 'needs_review' => false];
            }
            $grouped[$wineType]['gallons'] += $leesGallons;
            $grouped[$wineType]['event_ids'][] = $event->id;
            if ($classification['needs_review'] || isset($payload['lees_weight'])) {
                $grouped[$wineType]['needs_review'] = true;
            }
        }

        return $this->formatLines($grouped, 'racking_lees', 'Loss from racking lees (inventory loss)', 30);
    }

    /**
     * Calculate bottling waste losses (Line 29).
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateBottlingLosses(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $events = Event::ofType('bottling_completed')
            ->performedBetween($from, $to)
            ->get();

        $grouped = [];

        foreach ($events as $event) {
            $payload = $event->payload;
            $volumeBottled = (float) ($payload['volume_bottled_gallons'] ?? 0);
            $wastePct = (float) ($payload['waste_pct'] ?? $payload['waste_percent'] ?? 0);

            if ($volumeBottled <= 0 || $wastePct <= 0) {
                continue;
            }

            // Waste gallons = volume that went into bottling line * waste percentage
            // The waste_pct represents the fraction lost during bottling
            $wasteGallons = $volumeBottled * ($wastePct / 100);

            $lotId = $payload['lot_id'] ?? $event->entity_id;
            $classification = $this->classifier->classify($lotId, $payload);
            $wineType = $classification['type'];

            if (! isset($grouped[$wineType])) {
                $grouped[$wineType] = ['gallons' => 0.0, 'event_ids' => [], 'needs_review' => false];
            }
            $grouped[$wineType]['gallons'] += $wasteGallons;
            $grouped[$wineType]['event_ids'][] = $event->id;
            if ($classification['needs_review']) {
                $grouped[$wineType]['needs_review'] = true;
            }
        }

        return $this->formatLines($grouped, 'bottling_waste', 'Loss from bottling waste', 29);
    }

    /**
     * Calculate filtering losses (Line 29).
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateFilteringLosses(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $events = Event::ofType('filtering_logged')
            ->performedBetween($from, $to)
            ->get();

        $grouped = [];

        foreach ($events as $event) {
            $payload = $event->payload;
            $lossGallons = (float) ($payload['loss_gallons'] ?? 0);

            if ($lossGallons <= 0) {
                continue;
            }

            $lotId = $payload['lot_id'] ?? $event->entity_id;
            $classification = $this->classifier->classify($lotId, $payload);
            $wineType = $classification['type'];

            if (! isset($grouped[$wineType])) {
                $grouped[$wineType] = ['gallons' => 0.0, 'event_ids' => [], 'needs_review' => false];
            }
            $grouped[$wineType]['gallons'] += $lossGallons;
            $grouped[$wineType]['event_ids'][] = $event->id;
            if ($classification['needs_review']) {
                $grouped[$wineType]['needs_review'] = true;
            }
        }

        return $this->formatLines($grouped, 'filtering_loss', 'Loss from filtering', 29);
    }

    /**
     * Calculate evaporation losses (Line 29).
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateEvaporationLosses(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $events = Event::ofType('evaporation_measured')
            ->performedBetween($from, $to)
            ->get();

        $grouped = [];

        foreach ($events as $event) {
            $payload = $event->payload;
            $lossGallons = (float) ($payload['loss_gallons'] ?? 0);

            if ($lossGallons <= 0) {
                continue;
            }

            $lotId = $payload['lot_id'] ?? $event->entity_id;
            $classification = $this->classifier->classify($lotId, $payload);
            $wineType = $classification['type'];

            if (! isset($grouped[$wineType])) {
                $grouped[$wineType] = ['gallons' => 0.0, 'event_ids' => [], 'needs_review' => false];
            }
            $grouped[$wineType]['gallons'] += $lossGallons;
            $grouped[$wineType]['event_ids'][] = $event->id;
            if ($classification['needs_review']) {
                $grouped[$wineType]['needs_review'] = true;
            }
        }

        return $this->formatLines($grouped, 'evaporation_loss', 'Loss from evaporation (angel\'s share)', 29);
    }

    /**
     * Format grouped data into standard line item arrays.
     *
     * @param  array<string, array{gallons: float, event_ids: array<int, string>, needs_review: bool}>  $grouped
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function formatLines(array $grouped, string $category, string $descriptionPrefix, int $lineNumber): array
    {
        $lines = [];

        foreach ($grouped as $wineType => $data) {
            $lines[] = [
                'line_number' => $lineNumber,
                'section' => 'A',
                'category' => $category,
                'wine_type' => $wineType,
                'description' => $descriptionPrefix.' — '.(WineTypeClassifier::COLUMN_LABELS[$wineType] ?? $wineType),
                'gallons' => round($data['gallons'], 0),
                'source_event_ids' => $data['event_ids'],
                'needs_review' => $data['needs_review'],
            ];
        }

        return $lines;
    }
}
