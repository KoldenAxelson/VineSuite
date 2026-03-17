<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\Event;

/**
 * Part V Calculator — Losses of Wine.
 *
 * Aggregates events that represent wine losses:
 *   - Transfer variance (loss during transfer)
 *   - Racking lees (volume left behind as lees)
 *   - Bottling waste (waste_percent from bottling_completed)
 *   - Filtering losses
 *   - Stock adjustments (negative adjustments = losses)
 *
 * All volumes in wine gallons, rounded to nearest tenth.
 */
class PartFiveCalculator
{
    public function __construct(
        private readonly WineTypeClassifier $classifier,
    ) {}

    /**
     * Calculate Part V line items for a given reporting period.
     *
     * @return array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    public function calculate(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $lines = [];
        $lineNumber = 1;

        // Transfer losses (variance during transfer)
        $transferLines = $this->calculateTransferLosses($from, $to, $lineNumber);
        $lines = array_merge($lines, $transferLines);
        $lineNumber += count($transferLines);

        // Racking lees losses
        $rackingLines = $this->calculateRackingLosses($from, $to, $lineNumber);
        $lines = array_merge($lines, $rackingLines);
        $lineNumber += count($rackingLines);

        // Bottling waste losses
        $bottlingLines = $this->calculateBottlingLosses($from, $to, $lineNumber);
        $lines = array_merge($lines, $bottlingLines);
        $lineNumber += count($bottlingLines);

        // Filtering losses
        $filteringLines = $this->calculateFilteringLosses($from, $to, $lineNumber);
        $lines = array_merge($lines, $filteringLines);
        $lineNumber += count($filteringLines);

        return $lines;
    }

    /**
     * Get total gallons of losses.
     *
     * @param  array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $lines
     */
    public function totalGallons(array $lines): float
    {
        return round(array_sum(array_column($lines, 'gallons')), 1);
    }

    /**
     * Calculate transfer variance losses.
     *
     * @return array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateTransferLosses(\DateTimeInterface $from, \DateTimeInterface $to, int $startLine): array
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

        return $this->formatLines($grouped, 'transfer_loss', 'Loss from transfer variance', $startLine);
    }

    /**
     * Calculate racking lees losses.
     *
     * @return array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateRackingLosses(\DateTimeInterface $from, \DateTimeInterface $to, int $startLine): array
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

        return $this->formatLines($grouped, 'racking_lees', 'Loss from racking lees', $startLine);
    }

    /**
     * Calculate bottling waste losses.
     *
     * @return array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateBottlingLosses(\DateTimeInterface $from, \DateTimeInterface $to, int $startLine): array
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

        return $this->formatLines($grouped, 'bottling_waste', 'Loss from bottling waste', $startLine);
    }

    /**
     * Calculate filtering losses.
     *
     * @return array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateFilteringLosses(\DateTimeInterface $from, \DateTimeInterface $to, int $startLine): array
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

        return $this->formatLines($grouped, 'filtering_loss', 'Loss from filtering', $startLine);
    }

    /**
     * Format grouped data into standard line item arrays.
     *
     * @param  array<string, array{gallons: float, event_ids: array<int, string>, needs_review: bool}>  $grouped
     * @return array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function formatLines(array $grouped, string $category, string $descriptionPrefix, int $startLine): array
    {
        $lines = [];

        foreach ($grouped as $wineType => $data) {
            $lines[] = [
                'line_number' => $startLine++,
                'category' => $category,
                'wine_type' => $wineType,
                'description' => $descriptionPrefix.' — '.ucfirst(str_replace('_', ' ', $wineType)).' wine',
                'gallons' => round($data['gallons'], 1),
                'source_event_ids' => $data['event_ids'],
                'needs_review' => $data['needs_review'],
            ];
        }

        return $lines;
    }
}
