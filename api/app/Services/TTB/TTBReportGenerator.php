<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\TTBReport;
use Carbon\Carbon;

/**
 * TTBReportGenerator — orchestrates TTB Form 5120.17 report generation.
 *
 * Queries the event log for a given month and aggregates operations into
 * the five parts of the TTB Form 5120.17 (Report of Wine Premises Operations).
 *
 * This is the entry point for report generation. It coordinates the five
 * part calculators and produces a complete report data structure.
 *
 * Usage:
 *   $generator = app(TTBReportGenerator::class);
 *   $report = $generator->generate(month: 1, year: 2025, openingInventory: 5000.0);
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
     * @param  float  $openingInventory  Opening inventory in wine gallons.
     *                                   For month N, this should be the closing inventory from month N-1.
     *                                   For the very first report, this must be manually provided.
     * @return array{
     *   period: array{month: int, year: int, from: string, to: string},
     *   part_one: array{summary: array<string, mixed>, lines: array<int, mixed>},
     *   part_two: array{lines: array<int, mixed>, total_gallons: float},
     *   part_three: array{lines: array<int, mixed>, total_gallons: float},
     *   part_four: array{lines: array<int, mixed>, total_gallons: float},
     *   part_five: array{lines: array<int, mixed>, total_gallons: float},
     *   needs_review: bool,
     *   review_flags: array<int, string>,
     *   generated_at: string,
     * }
     */
    public function generate(int $month, int $year, float $openingInventory = 0.0): array
    {
        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to = $from->copy()->endOfMonth()->endOfDay();

        // Calculate Parts II-V from events
        $partTwoLines = $this->partTwo->calculate($from, $to);
        $partThreeLines = $this->partThree->calculate($from, $to);
        $partFourLines = $this->partFour->calculate($from, $to);
        $partFiveLines = $this->partFive->calculate($from, $to);

        // Aggregate totals
        $totalProduced = $this->partTwo->totalGallons($partTwoLines);
        $totalReceived = $this->partThree->totalGallons($partThreeLines);
        $totalRemoved = $this->partFour->totalGallons($partFourLines);
        $totalLosses = $this->partFive->totalGallons($partFiveLines);

        // Part I summary (balance equation)
        $partOneSummary = $this->partOne->calculate(
            $openingInventory,
            $totalProduced,
            $totalReceived,
            $totalRemoved,
            $totalLosses,
        );
        $partOneLines = $this->partOne->generateLineItems(
            $openingInventory,
            $totalProduced,
            $totalReceived,
            $totalRemoved,
            $totalLosses,
        );

        // Collect review flags
        $reviewFlags = $this->collectReviewFlags($partTwoLines, $partThreeLines, $partFourLines, $partFiveLines);
        $needsReview = count($reviewFlags) > 0 || ! $partOneSummary['balanced'];

        if (! $partOneSummary['balanced']) {
            $reviewFlags[] = 'Part I balance equation does not balance — verify all entries';
        }

        return [
            'period' => [
                'month' => $month,
                'year' => $year,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'part_one' => [
                'summary' => $partOneSummary,
                'lines' => $partOneLines,
            ],
            'part_two' => [
                'lines' => $partTwoLines,
                'total_gallons' => $totalProduced,
            ],
            'part_three' => [
                'lines' => $partThreeLines,
                'total_gallons' => $totalReceived,
            ],
            'part_four' => [
                'lines' => $partFourLines,
                'total_gallons' => $totalRemoved,
            ],
            'part_five' => [
                'lines' => $partFiveLines,
                'total_gallons' => $totalLosses,
            ],
            'needs_review' => $needsReview,
            'review_flags' => $reviewFlags,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get the closing inventory from the previous month's report.
     * Used as opening inventory for the next month.
     *
     * @param  int  $month  Current reporting month
     * @param  int  $year  Current reporting year
     */
    public function getPreviousClosingInventory(int $month, int $year): ?float
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

        return (float) ($data['part_one']['summary']['closing_inventory'] ?? 0);
    }

    /**
     * Collect review flags from all part line items.
     *
     * @param  array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $partTwo
     * @param  array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $partThree
     * @param  array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $partFour
     * @param  array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $partFive
     * @return array<int, string>
     */
    private function collectReviewFlags(array $partTwo, array $partThree, array $partFour, array $partFive): array
    {
        $flags = [];
        $allLines = array_merge($partTwo, $partThree, $partFour, $partFive);

        foreach ($allLines as $line) {
            if (! empty($line['needs_review'])) {
                $flags[] = "Line needs review: {$line['description']} ({$line['gallons']} gal)";
            }
        }

        return $flags;
    }
}
