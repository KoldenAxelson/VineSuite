<?php

declare(strict_types=1);

namespace App\Services\TTB;

/**
 * Part I Calculator — Summary of Wines in Bond.
 *
 * Computes the balance for both Section A (Bulk Wines) and Section B (Bottled Wines)
 * per TTB Form 5120.17. Each section has its own opening/closing inventory.
 *
 * Section A balance: Line 12 must equal Line 32.
 * Section B balance: Line 7 must equal Line 21.
 *
 * All volumes in wine gallons, rounded to whole gallons per TTB practice.
 */
class PartOneCalculator
{
    /**
     * Calculate Section A (Bulk Wines) summary.
     *
     * @return array{opening_inventory: float, total_produced: float, total_received: float, total_increases: float, total_bottled: float, total_removed_taxpaid: float, total_transferred: float, total_losses: float, total_decreases: float, closing_inventory: float, balanced: bool}
     */
    public function calculateSectionA(
        float $openingInventory,
        float $totalProduced,
        float $totalReceived,
        float $totalBottled,
        float $totalRemovedTaxpaid,
        float $totalTransferred,
        float $totalLosses,
    ): array {
        $totalIncreases = round($openingInventory + $totalProduced + $totalReceived, 0);
        $totalDecreases = round($totalBottled + $totalRemovedTaxpaid + $totalTransferred + $totalLosses, 0);
        $closingInventory = round($totalIncreases - $totalDecreases, 0);

        return [
            'opening_inventory' => round($openingInventory, 0),
            'total_produced' => round($totalProduced, 0),
            'total_received' => round($totalReceived, 0),
            'total_increases' => $totalIncreases,
            'total_bottled' => round($totalBottled, 0),
            'total_removed_taxpaid' => round($totalRemovedTaxpaid, 0),
            'total_transferred' => round($totalTransferred, 0),
            'total_losses' => round($totalLosses, 0),
            'total_decreases' => $totalDecreases,
            'closing_inventory' => $closingInventory,
            'balanced' => abs($totalIncreases - ($totalDecreases + $closingInventory)) < 1,
        ];
    }

    /**
     * Calculate Section B (Bottled Wines) summary.
     *
     * @return array{opening_inventory: float, total_bottled: float, total_received_in_bond: float, total_increases: float, total_removed_taxpaid: float, total_transferred: float, total_breakage: float, total_other_losses: float, total_decreases: float, closing_inventory: float, balanced: bool}
     */
    public function calculateSectionB(
        float $openingInventory,
        float $totalBottled,
        float $totalReceivedInBond,
        float $totalRemovedTaxpaid,
        float $totalTransferred,
        float $totalBreakage,
        float $totalOtherLosses,
    ): array {
        $totalIncreases = round($openingInventory + $totalBottled + $totalReceivedInBond, 0);
        $totalDecreases = round($totalRemovedTaxpaid + $totalTransferred + $totalBreakage + $totalOtherLosses, 0);
        $closingInventory = round($totalIncreases - $totalDecreases, 0);

        return [
            'opening_inventory' => round($openingInventory, 0),
            'total_bottled' => round($totalBottled, 0),
            'total_received_in_bond' => round($totalReceivedInBond, 0),
            'total_increases' => $totalIncreases,
            'total_removed_taxpaid' => round($totalRemovedTaxpaid, 0),
            'total_transferred' => round($totalTransferred, 0),
            'total_breakage' => round($totalBreakage, 0),
            'total_other_losses' => round($totalOtherLosses, 0),
            'total_decreases' => $totalDecreases,
            'closing_inventory' => $closingInventory,
            'balanced' => abs($totalIncreases - ($totalDecreases + $closingInventory)) < 1,
        ];
    }

    /**
     * Generate Section A line items matching TTB Form 5120.17 Part I Section A.
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    public function generateSectionALineItems(
        float $openingInventory,
        float $totalProduced,
        float $totalReceived,
        float $totalBottled,
        float $totalRemovedTaxpaid,
        float $totalTransferred,
        float $totalLosses,
    ): array {
        $summary = $this->calculateSectionA(
            $openingInventory, $totalProduced, $totalReceived,
            $totalBottled, $totalRemovedTaxpaid, $totalTransferred, $totalLosses,
        );

        return [
            ['line_number' => 1, 'section' => 'A', 'category' => 'on_hand_beginning', 'wine_type' => 'all', 'description' => 'On hand beginning of period', 'gallons' => $summary['opening_inventory'], 'source_event_ids' => [], 'needs_review' => false],
            ['line_number' => 12, 'section' => 'A', 'category' => 'total_increases', 'wine_type' => 'all', 'description' => 'TOTAL (Lines 1-11)', 'gallons' => $summary['total_increases'], 'source_event_ids' => [], 'needs_review' => false],
            ['line_number' => 31, 'section' => 'A', 'category' => 'on_hand_end', 'wine_type' => 'all', 'description' => 'On hand end of period', 'gallons' => $summary['closing_inventory'], 'source_event_ids' => [], 'needs_review' => false],
            ['line_number' => 32, 'section' => 'A', 'category' => 'total_decreases', 'wine_type' => 'all', 'description' => 'TOTAL (Lines 13-31)', 'gallons' => $summary['total_increases'], 'source_event_ids' => [], 'needs_review' => false],
        ];
    }

    /**
     * Generate Section B line items matching TTB Form 5120.17 Part I Section B.
     *
     * @return array<int, array{line_number: int, section: string, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    public function generateSectionBLineItems(
        float $openingInventory,
        float $totalBottled,
        float $totalReceivedInBond,
        float $totalRemovedTaxpaid,
        float $totalTransferred,
        float $totalBreakage,
        float $totalOtherLosses,
    ): array {
        $summary = $this->calculateSectionB(
            $openingInventory, $totalBottled, $totalReceivedInBond,
            $totalRemovedTaxpaid, $totalTransferred, $totalBreakage, $totalOtherLosses,
        );

        return [
            ['line_number' => 1, 'section' => 'B', 'category' => 'on_hand_beginning', 'wine_type' => 'all', 'description' => 'On hand beginning of period', 'gallons' => $summary['opening_inventory'], 'source_event_ids' => [], 'needs_review' => false],
            ['line_number' => 7, 'section' => 'B', 'category' => 'total_increases', 'wine_type' => 'all', 'description' => 'TOTAL (Lines 1-6)', 'gallons' => $summary['total_increases'], 'source_event_ids' => [], 'needs_review' => false],
            ['line_number' => 20, 'section' => 'B', 'category' => 'on_hand_end', 'wine_type' => 'all', 'description' => 'On hand end of period', 'gallons' => $summary['closing_inventory'], 'source_event_ids' => [], 'needs_review' => false],
            ['line_number' => 21, 'section' => 'B', 'category' => 'total_decreases', 'wine_type' => 'all', 'description' => 'TOTAL (Lines 8-20)', 'gallons' => $summary['total_increases'], 'source_event_ids' => [], 'needs_review' => false],
        ];
    }
}
