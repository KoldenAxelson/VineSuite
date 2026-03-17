<?php

declare(strict_types=1);

namespace App\Services\TTB;

/**
 * Part I Calculator — Summary of Wine Operations.
 *
 * Part I is the balance sheet: it doesn't query events directly.
 * Instead, it aggregates results from Parts II-V plus opening inventory
 * to produce the summary totals.
 *
 * Balance equation:
 *   Opening Inventory + Produced (Part II) + Received (Part III)
 *   = Closing Inventory + Removed (Part IV) + Losses (Part V)
 *
 * Therefore:
 *   Closing Inventory = Opening + Produced + Received - Removed - Losses
 *
 * All volumes in wine gallons, rounded to nearest tenth.
 */
class PartOneCalculator
{
    /**
     * Calculate Part I summary for a given reporting period.
     *
     * @param  float  $openingInventory  Opening inventory in wine gallons
     * @param  float  $totalProduced  Total from Part II
     * @param  float  $totalReceived  Total from Part III
     * @param  float  $totalRemoved  Total from Part IV
     * @param  float  $totalLosses  Total from Part V
     * @return array{opening_inventory: float, total_produced: float, total_received: float, total_available: float, total_removed: float, total_losses: float, closing_inventory: float, balanced: bool}
     */
    public function calculate(
        float $openingInventory,
        float $totalProduced,
        float $totalReceived,
        float $totalRemoved,
        float $totalLosses,
    ): array {
        $totalAvailable = round($openingInventory + $totalProduced + $totalReceived, 1);
        $closingInventory = round($totalAvailable - $totalRemoved - $totalLosses, 1);

        // Balance check: available should equal disposed + closing
        $disposed = round($totalRemoved + $totalLosses + $closingInventory, 1);
        $balanced = abs($totalAvailable - $disposed) < 0.1;

        return [
            'opening_inventory' => round($openingInventory, 1),
            'total_produced' => round($totalProduced, 1),
            'total_received' => round($totalReceived, 1),
            'total_available' => $totalAvailable,
            'total_removed' => round($totalRemoved, 1),
            'total_losses' => round($totalLosses, 1),
            'closing_inventory' => $closingInventory,
            'balanced' => $balanced,
        ];
    }

    /**
     * Generate Part I line items for the report.
     *
     * @return array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>
     */
    public function generateLineItems(
        float $openingInventory,
        float $totalProduced,
        float $totalReceived,
        float $totalRemoved,
        float $totalLosses,
    ): array {
        $summary = $this->calculate($openingInventory, $totalProduced, $totalReceived, $totalRemoved, $totalLosses);

        return [
            [
                'line_number' => 1,
                'category' => 'opening_inventory',
                'wine_type' => 'all',
                'description' => 'Opening inventory — beginning of period',
                'gallons' => $summary['opening_inventory'],
                'source_event_ids' => [],
                'needs_review' => false,
            ],
            [
                'line_number' => 2,
                'category' => 'total_produced',
                'wine_type' => 'all',
                'description' => 'Wine produced (Part II total)',
                'gallons' => $summary['total_produced'],
                'source_event_ids' => [],
                'needs_review' => false,
            ],
            [
                'line_number' => 3,
                'category' => 'total_received',
                'wine_type' => 'all',
                'description' => 'Wine received (Part III total)',
                'gallons' => $summary['total_received'],
                'source_event_ids' => [],
                'needs_review' => false,
            ],
            [
                'line_number' => 4,
                'category' => 'total_available',
                'wine_type' => 'all',
                'description' => 'Total wine available (Lines 1+2+3)',
                'gallons' => $summary['total_available'],
                'source_event_ids' => [],
                'needs_review' => false,
            ],
            [
                'line_number' => 5,
                'category' => 'total_removed',
                'wine_type' => 'all',
                'description' => 'Wine removed (Part IV total)',
                'gallons' => $summary['total_removed'],
                'source_event_ids' => [],
                'needs_review' => false,
            ],
            [
                'line_number' => 6,
                'category' => 'total_losses',
                'wine_type' => 'all',
                'description' => 'Losses (Part V total)',
                'gallons' => $summary['total_losses'],
                'source_event_ids' => [],
                'needs_review' => false,
            ],
            [
                'line_number' => 7,
                'category' => 'closing_inventory',
                'wine_type' => 'all',
                'description' => 'Closing inventory — end of period',
                'gallons' => $summary['closing_inventory'],
                'source_event_ids' => [],
                'needs_review' => false,
            ],
        ];
    }
}
