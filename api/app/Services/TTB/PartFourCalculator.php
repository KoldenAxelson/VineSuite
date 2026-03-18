<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;

/**
 * Part IV Calculator — Wine Removed from Bond.
 *
 * Aggregates events where wine was removed from bonded premises into
 * TTB Form 5120.17 Section A (bulk) and Section B (bottled) line items.
 *
 * Section A removal lines (13-28):
 *   - Line 13: Bottled or packaged (bottling_completed)
 *   - Line 14: Taxpaid removals of natural wine (taxpaid_bulk_removal)
 *   - Line 15: Transferred to bonded wine premises (wine_transferred_bonded)
 *   - Line 17: Exported (wine_exported)
 *   - Line 18: Used as distilling material (used_as_distilling_material)
 *   - Line 19: Used in manufacture of vinegar (used_as_vinegar)
 *   - Line 23: Lost by breakage, spillage, or other cause (breakage_reported)
 *   - Line 24: Other (other_bulk_removal)
 *
 * Section B receipt lines (2-6):
 *   - Line 2: Bottled from bulk (bottling_completed — dual entry with Section A Line 13)
 *   - Line 3: Received from bonded wine premises (bottled_received_bonded)
 *   - Line 4: Received from customs (bottled_received_customs)
 *   - Line 5: Wine returned to bond (bottled_returned_to_bond)
 *
 * Section B removal lines (8-17):
 *   - Line 8: Removed taxpaid — sales (stock_sold)
 *   - Line 9: Transferred to bonded wine premises (bottled_transferred_bonded)
 *   - Line 11: Exported (bottled_exported)
 *   - Line 12: Returned to bulk storage (bottled_returned_to_bulk)
 *   - Line 13: Breakage/spillage (bottled_breakage)
 *   - Line 17: Other (bottled_other_removal)
 *
 * Per TTB Form 5120.17:
 *   - Bottling events produce TWO line items:
 *     * Section A, Line 13: decrease from bulk wine operations
 *     * Section B, Line 2: increase to bottled wine operations
 *
 * All volumes in wine gallons, rounded to whole gallons per TTB practice.
 */
class PartFourCalculator
{
    public function __construct(
        private readonly WineTypeClassifier $classifier,
    ) {}

    /**
     * Calculate Part IV line items for a given reporting period.
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    public function calculate(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $lines = [];

        // ─── Section A: Bulk wine removals (Lines 13-28) ─────────────

        // Line 13: Bottling — creates dual entries (Section A decrease + Section B increase)
        $bottlingLines = $this->calculateBottlingLines($from, $to);
        $lines = array_merge($lines, $bottlingLines);

        // Line 14: Taxpaid removals of bulk wine
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'taxpaid_bulk_removal',
            lineNumber: 14,
            section: 'A',
            category: 'taxpaid_bulk_removal',
            description: 'Taxpaid removals of natural wine',
        ));

        // Line 15: Transferred to bonded wine premises
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'wine_transferred_bonded',
            lineNumber: 15,
            section: 'A',
            category: 'transferred_bonded',
            description: 'Transferred to bonded wine premises',
        ));

        // Line 17: Exported
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'wine_exported',
            lineNumber: 17,
            section: 'A',
            category: 'wine_exported',
            description: 'Wine exported',
        ));

        // Line 18: Used as distilling material
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'used_as_distilling_material',
            lineNumber: 18,
            section: 'A',
            category: 'distilling_material',
            description: 'Used as distilling material',
        ));

        // Line 19: Used in manufacture of vinegar
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'used_as_vinegar',
            lineNumber: 19,
            section: 'A',
            category: 'vinegar_stock',
            description: 'Used in manufacture of vinegar',
        ));

        // Line 23: Lost by breakage, spillage, or unavoidable cause
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'breakage_reported',
            lineNumber: 23,
            section: 'A',
            category: 'breakage_bulk',
            description: 'Lost by breakage, spillage, or other cause',
        ));

        // Line 24: Other removals (specify)
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'other_bulk_removal',
            lineNumber: 24,
            section: 'A',
            category: 'other_bulk_removal',
            description: 'Other removals',
        ));

        // ─── Section B: Bottled wine receipts (Lines 3-5) ────────────

        // Line 3: Received from bonded wine premises (bottled)
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'bottled_received_bonded',
            lineNumber: 3,
            section: 'B',
            category: 'bottled_received_bonded',
            description: 'Bottled wine received from bonded premises',
        ));

        // Line 4: Received from customs (bottled)
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'bottled_received_customs',
            lineNumber: 4,
            section: 'B',
            category: 'bottled_received_customs',
            description: 'Bottled wine received from customs',
        ));

        // Line 5: Wine returned to bond (bottled)
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'bottled_returned_to_bond',
            lineNumber: 5,
            section: 'B',
            category: 'bottled_returned_to_bond',
            description: 'Bottled wine returned to bond',
        ));

        // ─── Section B: Bottled wine removals (Lines 8-17) ──────────

        // Line 8: Removed taxpaid — sales
        $lines = array_merge($lines, $this->calculateSalesLines($from, $to));

        // Line 9: Transferred to bonded wine premises (bottled)
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'bottled_transferred_bonded',
            lineNumber: 9,
            section: 'B',
            category: 'bottled_transferred_bonded',
            description: 'Bottled wine transferred to bonded premises',
        ));

        // Line 11: Exported (bottled)
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'bottled_exported',
            lineNumber: 11,
            section: 'B',
            category: 'bottled_exported',
            description: 'Bottled wine exported',
        ));

        // Line 12: Returned to bulk storage (Section B → Section A)
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'bottled_returned_to_bulk',
            lineNumber: 12,
            section: 'B',
            category: 'bottled_returned_to_bulk',
            description: 'Bottled wine returned to bulk storage',
        ));

        // Line 13: Breakage/spillage (bottled)
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'bottled_breakage',
            lineNumber: 13,
            section: 'B',
            category: 'bottled_breakage',
            description: 'Bottled wine lost by breakage or spillage',
        ));

        // Line 17: Other (bottled)
        $lines = array_merge($lines, $this->calculateRemovalLine(
            $from, $to,
            operationType: 'bottled_other_removal',
            lineNumber: 17,
            section: 'B',
            category: 'bottled_other_removal',
            description: 'Other bottled wine removals',
        ));

        return $lines;
    }

    /**
     * Get total gallons removed across all wine types.
     *
     * @param  array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $lines
     */
    public function totalGallons(array $lines): float
    {
        return round(array_sum(array_column($lines, 'gallons')), 0);
    }

    /**
     * Calculate bottling lines — creates dual entries for Section A and Section B.
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateBottlingLines(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $events = Event::ofType('bottling_completed')
            ->performedBetween($from, $to)
            ->get();

        $bottledByType = $this->aggregateByWineType($events, 'volume_bottled_gallons');
        $lines = [];

        // Section A, Line 13: decrease from bulk
        foreach ($bottledByType as $wineType => $data) {
            $lines[] = [
                'line_number' => 13,
                'section' => 'A',
                'category' => 'wine_bottled',
                'wine_type' => $wineType,
                'description' => 'Wine bottled (removed from bulk) — '.(WineTypeClassifier::COLUMN_LABELS[$wineType] ?? $wineType),
                'gallons' => round($data['gallons'], 0),
                'source_event_ids' => $data['event_ids'],
                'needs_review' => $data['needs_review'],
            ];
        }

        // Section B, Line 2: increase to bottled
        foreach ($bottledByType as $wineType => $data) {
            $lines[] = [
                'line_number' => 2,
                'section' => 'B',
                'category' => 'wine_bottled',
                'wine_type' => $wineType,
                'description' => 'Bottled wine received (from bulk) — '.(WineTypeClassifier::COLUMN_LABELS[$wineType] ?? $wineType),
                'gallons' => round($data['gallons'], 0),
                'source_event_ids' => $data['event_ids'],
                'needs_review' => $data['needs_review'],
            ];
        }

        return $lines;
    }

    /**
     * Calculate sales removal lines (Section B, Line 8).
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateSalesLines(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $events = Event::ofType('stock_sold')
            ->performedBetween($from, $to)
            ->get();

        $soldByType = $this->aggregateSoldByWineType($events);
        $lines = [];

        foreach ($soldByType as $wineType => $data) {
            $lines[] = [
                'line_number' => 8,
                'section' => 'B',
                'category' => 'wine_sold',
                'wine_type' => $wineType,
                'description' => 'Wine removed by sale (taxpaid) — '.(WineTypeClassifier::COLUMN_LABELS[$wineType] ?? $wineType),
                'gallons' => round($data['gallons'], 0),
                'source_event_ids' => $data['event_ids'],
                'needs_review' => $data['needs_review'],
            ];
        }

        return $lines;
    }

    /**
     * Calculate a generic removal/receipt line from events of a given operation type.
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    private function calculateRemovalLine(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $operationType,
        int $lineNumber,
        string $section,
        string $category,
        string $description,
    ): array {
        $events = Event::ofType($operationType)
            ->performedBetween($from, $to)
            ->get();

        $grouped = $this->aggregateByWineType($events, 'volume_gallons');
        $lines = [];

        foreach ($grouped as $wineType => $data) {
            $lines[] = [
                'line_number' => $lineNumber,
                'section' => $section,
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
     * Aggregate events by wine type using a specified volume field.
     *
     * @param  Collection<int, Event>  $events
     * @return array<string, array{gallons: float, event_ids: array<int, string>, needs_review: bool}>
     */
    private function aggregateByWineType(Collection $events, string $volumeField): array
    {
        $grouped = [];

        foreach ($events as $event) {
            $payload = $event->payload;
            $volume = (float) ($payload[$volumeField] ?? 0);

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

    /**
     * Aggregate sold events by wine type.
     * stock_sold events track case goods, need volume conversion.
     *
     * @param  Collection<int, Event>  $events
     * @return array<string, array{gallons: float, event_ids: array<int, string>, needs_review: bool}>
     */
    private function aggregateSoldByWineType(Collection $events): array
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
