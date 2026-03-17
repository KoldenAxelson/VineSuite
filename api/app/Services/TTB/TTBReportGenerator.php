<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\TTBReport;
use Carbon\Carbon;

/**
 * TTBReportGenerator — orchestrates TTB Form 5120.17 report generation.
 *
 * Queries the event log for a given month and aggregates operations into
 * Section A (bulk) and Section B (bottled) of the TTB Form 5120.17
 * (Report of Wine Premises Operations).
 *
 * This is the entry point for report generation. It coordinates the five
 * part calculators and produces a complete report data structure with
 * separate sections for bulk and bottled wine.
 *
 * Usage:
 *   $generator = app(TTBReportGenerator::class);
 *   $report = $generator->generate(
 *       month: 1,
 *       year: 2025,
 *       openingBulkInventory: 5000.0,
 *       openingBottledInventory: 1000.0
 *   );
 */
class TTBReportGenerator
{
    public function __construct(
        private readonly PartOneCalculator $partOne,
        private readonly PartTwoCalculator $partTwo,
        private readonly PartThreeCalculator $partThree,
        private readonly PartFourCalculator $partFour,
        private readonly PartFiveCalculator $partFive,
    ) {}

    /**
     * Generate the full TTB Form 5120.17 report for a given month.
     *
     * @param  int  $month  Reporting month (1-12)
     * @param  int  $year  Reporting year
     * @param  float  $openingBulkInventory  Opening bulk inventory in wine gallons.
     *                                       For month N, this should be the closing bulk inventory from month N-1.
     *                                       For the very first report, this must be manually provided.
     * @param  float  $openingBottledInventory  Opening bottled inventory in wine gallons.
     *                                          For month N, this should be the closing bottled inventory from month N-1.
     *                                          For the very first report, this must be manually provided.
     * @return array{
     *   period: array{month: int, year: int, from: string, to: string},
     *   section_a: array{summary: array<string, mixed>, lines: array<int, mixed>},
     *   section_b: array{summary: array<string, mixed>, lines: array<int, mixed>},
     *   needs_review: bool,
     *   review_flags: array<int, string>,
     *   generated_at: string,
     * }
     */
    public function generate(int $month, int $year, float $openingBulkInventory = 0.0, float $openingBottledInventory = 0.0): array
    {
        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to = $from->copy()->endOfMonth()->endOfDay();

        // Calculate Parts II-V from events
        $partTwoLines = $this->partTwo->calculate($from, $to);
        $partThreeLines = $this->partThree->calculate($from, $to);
        $partFourLines = $this->partFour->calculate($from, $to);
        $partFiveLines = $this->partFive->calculate($from, $to);

        // Separate Part IV lines by section
        $partFourSectionALines = array_filter($partFourLines, fn ($line) => $line['section'] === 'A');
        $partFourSectionBLines = array_filter($partFourLines, fn ($line) => $line['section'] === 'B');

        // ─── Section A totals (bulk wine) ────────────────────────────
        $totalProducedA = $this->partTwo->totalGallons($partTwoLines);
        $totalReceivedA = $this->partThree->totalGallons($partThreeLines);

        // Bottled: Section A Line 13 (wine_bottled category)
        $totalBottledA = $this->sumGallonsByCondition(
            $partFourSectionALines,
            fn ($line) => ($line['category'] ?? null) === 'wine_bottled'
        );

        // Taxpaid bulk removals: Section A Line 14
        $totalRemovedTaxpaidA = $this->sumGallonsByCondition(
            $partFourSectionALines,
            fn ($line) => ($line['category'] ?? null) === 'taxpaid_bulk_removal'
        );

        // Transferred to bonded premises + exported + distilling + vinegar + other: Section A Lines 15-24
        $transferCategories = ['transferred_bonded', 'wine_exported', 'distilling_material', 'vinegar_stock', 'breakage_bulk', 'other_bulk_removal'];
        $totalTransferredA = $this->sumGallonsByCondition(
            $partFourSectionALines,
            fn ($line) => in_array($line['category'] ?? null, $transferCategories, true)
        );

        // Losses from Part V (transfer variance, bottling waste, filtering, evaporation, racking)
        $totalLossesA = $this->partFive->totalGallons($partFiveLines);

        // ─── Section B totals (bottled wine) ─────────────────────────

        // Bottled from bulk: Section B Line 2
        $totalBottledB = $this->sumGallonsByCondition(
            $partFourSectionBLines,
            fn ($line) => ($line['category'] ?? null) === 'wine_bottled'
        );

        // Received in bond (bottled): Section B Lines 3-5
        $receivedInBondCategories = ['bottled_received_bonded', 'bottled_received_customs', 'bottled_returned_to_bond'];
        $totalReceivedInBondB = $this->sumGallonsByCondition(
            $partFourSectionBLines,
            fn ($line) => in_array($line['category'] ?? null, $receivedInBondCategories, true)
        );

        // Removed taxpaid (sales): Section B Line 8
        $totalRemovedTaxpaidB = $this->sumGallonsByCondition(
            $partFourSectionBLines,
            fn ($line) => ($line['category'] ?? null) === 'wine_sold'
        );

        // Transferred (bottled): Section B Lines 9, 11
        $bottledTransferCategories = ['bottled_transferred_bonded', 'bottled_exported', 'bottled_returned_to_bulk', 'bottled_other_removal'];
        $totalTransferredB = $this->sumGallonsByCondition(
            $partFourSectionBLines,
            fn ($line) => in_array($line['category'] ?? null, $bottledTransferCategories, true)
        );

        // Breakage (bottled): Section B Line 13
        $totalBreakageB = $this->sumGallonsByCondition(
            $partFourSectionBLines,
            fn ($line) => ($line['category'] ?? null) === 'bottled_breakage'
        );

        // Other losses (bottled) — currently none tracked separately
        $totalOtherLossesB = 0.0;

        // ─── Calculate summaries ─────────────────────────────────────

        $sectionASummary = $this->partOne->calculateSectionA(
            $openingBulkInventory,
            $totalProducedA,
            $totalReceivedA,
            $totalBottledA,
            $totalRemovedTaxpaidA,
            $totalTransferredA,
            $totalLossesA,
        );

        $sectionBSummary = $this->partOne->calculateSectionB(
            $openingBottledInventory,
            $totalBottledB,
            $totalReceivedInBondB,
            $totalRemovedTaxpaidB,
            $totalTransferredB,
            $totalBreakageB,
            $totalOtherLossesB,
        );

        // Merge line items for Section A (production + receipt + removal + loss)
        $sectionALines = array_merge(
            $partTwoLines,
            $partThreeLines,
            $partFourSectionALines,
            $partFiveLines
        );

        // Lines for Section B (only Part IV section B items)
        $sectionBLines = $partFourSectionBLines;

        // Collect review flags
        $reviewFlags = $this->collectReviewFlags($partTwoLines, $partThreeLines, $partFourLines, $partFiveLines);
        $needsReview = count($reviewFlags) > 0 || ! $sectionASummary['balanced'] || ! $sectionBSummary['balanced'];

        if (! $sectionASummary['balanced']) {
            $reviewFlags[] = 'Section A balance equation does not balance — verify all entries';
        }

        if (! $sectionBSummary['balanced']) {
            $reviewFlags[] = 'Section B balance equation does not balance — verify all entries';
        }

        return [
            'period' => [
                'month' => $month,
                'year' => $year,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'section_a' => [
                'summary' => $sectionASummary,
                'lines' => $sectionALines,
            ],
            'section_b' => [
                'summary' => $sectionBSummary,
                'lines' => array_values($sectionBLines),
            ],
            'needs_review' => $needsReview,
            'review_flags' => $reviewFlags,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get the closing inventories from the previous month's report.
     * Used as opening inventories for the next month.
     *
     * @param  int  $month  Current reporting month
     * @param  int  $year  Current reporting year
     * @return array{bulk: float, bottled: float}|null
     */
    public function getPreviousClosingInventory(int $month, int $year): ?array
    {
        $previousDate = Carbon::create($year, $month, 1)->subMonth();

        $previousReport = TTBReport::where('report_period_month', $previousDate->month)
            ->where('report_period_year', $previousDate->year)
            ->whereIn('status', ['reviewed', 'filed'])
            ->first();

        if ($previousReport === null) {
            return null;
        }

        $data = $previousReport->data;

        return [
            'bulk' => (float) ($data['section_a']['summary']['closing_inventory'] ?? 0),
            'bottled' => (float) ($data['section_b']['summary']['closing_inventory'] ?? 0),
        ];
    }

    /**
     * Sum gallons from line items matching a condition.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  callable(array<string, mixed>): bool  $condition
     */
    private function sumGallonsByCondition(array $lines, callable $condition): float
    {
        $total = 0.0;
        foreach ($lines as $line) {
            if ($condition($line)) {
                $total += (float) ($line['gallons'] ?? 0);
            }
        }

        return round($total, 0);
    }

    /**
     * Collect review flags from all part line items.
     *
     * @param  array<int, array<string, mixed>>  $partTwo
     * @param  array<int, array<string, mixed>>  $partThree
     * @param  array<int, array<string, mixed>>  $partFour
     * @param  array<int, array<string, mixed>>  $partFive
     * @return array<int, string>
     */
    private function collectReviewFlags(array $partTwo, array $partThree, array $partFour, array $partFive): array
    {
        $flags = [];
        $allLines = array_merge($partTwo, $partThree, $partFour, $partFive);

        foreach ($allLines as $line) {
            if (! empty($line['needs_review'])) {
                $section = $line['section'] ?? 'unknown';
                $flags[] = "Section {$section} line needs review: {$line['description']} ({$line['gallons']} gal)";
            }
        }

        return $flags;
    }
}
