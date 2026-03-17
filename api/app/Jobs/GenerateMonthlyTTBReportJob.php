<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\TTBReport;
use App\Models\TTBReportLine;
use App\Services\EventLogger;
use App\Services\TTB\TTBReportGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates the monthly TTB Form 5120.17 report for a tenant.
 *
 * Scheduled to run on the 1st of each month for the previous month.
 * Idempotent: regenerating for the same month replaces the existing draft.
 * Only draft reports can be regenerated — reviewed/filed reports are immutable.
 */
class GenerateMonthlyTTBReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $tenantId,
        private readonly int $month,
        private readonly int $year,
        private readonly float $openingInventory = 0.0,
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);

        $tenant->run(function () {
            Log::info('GenerateMonthlyTTBReportJob: starting', [
                'month' => $this->month,
                'year' => $this->year,
            ]);

            // Check for existing report
            $existing = TTBReport::where('report_period_month', $this->month)
                ->where('report_period_year', $this->year)
                ->first();

            if ($existing !== null && ! $existing->canRegenerate()) {
                Log::info('GenerateMonthlyTTBReportJob: report already reviewed/filed, skipping', [
                    'report_id' => $existing->id,
                    'status' => $existing->status,
                ]);

                return;
            }

            // Determine opening inventory
            $openingInventory = $this->openingInventory;
            if ($openingInventory === 0.0) {
                $generator = app(TTBReportGenerator::class);
                $previous = $generator->getPreviousClosingInventory($this->month, $this->year);
                if ($previous !== null) {
                    $openingInventory = $previous;
                }
            }

            // Generate report data
            $generator = app(TTBReportGenerator::class);
            $reportData = $generator->generate($this->month, $this->year, $openingInventory);

            DB::transaction(function () use ($existing, $reportData) {
                // Delete existing draft and its lines if regenerating
                if ($existing !== null) {
                    $existing->lines()->delete();
                    $existing->delete();
                }

                // Create report
                $report = TTBReport::create([
                    'report_period_month' => $this->month,
                    'report_period_year' => $this->year,
                    'status' => 'draft',
                    'generated_at' => now(),
                    'data' => $reportData,
                ]);

                // Create line items for each part
                $this->createLineItems($report, 'I', $reportData['part_one']['lines']);
                $this->createLineItems($report, 'II', $reportData['part_two']['lines']);
                $this->createLineItems($report, 'III', $reportData['part_three']['lines']);
                $this->createLineItems($report, 'IV', $reportData['part_four']['lines']);
                $this->createLineItems($report, 'V', $reportData['part_five']['lines']);

                // Log the event
                app(EventLogger::class)->log(
                    entityType: 'ttb_report',
                    entityId: $report->id,
                    operationType: 'ttb_report_generated',
                    payload: [
                        'report_id' => $report->id,
                        'period_month' => $this->month,
                        'period_year' => $this->year,
                        'status' => 'draft',
                        'needs_review' => $reportData['needs_review'],
                        'review_flag_count' => count($reportData['review_flags']),
                    ],
                    performedAt: now(),
                );

                Log::info('GenerateMonthlyTTBReportJob: completed', [
                    'report_id' => $report->id,
                    'line_count' => $report->lines()->count(),
                ]);
            });
        });
    }

    /**
     * Create line items for a report part.
     *
     * @param  array<int, array{line_number: int, category: string, wine_type: string, description: string, gallons: float, source_event_ids: array<int, string>, needs_review: bool}>  $lines
     */
    private function createLineItems(TTBReport $report, string $part, array $lines): void
    {
        foreach ($lines as $line) {
            TTBReportLine::create([
                'ttb_report_id' => $report->id,
                'part' => $part,
                'line_number' => $line['line_number'],
                'category' => $line['category'],
                'wine_type' => $line['wine_type'],
                'description' => $line['description'],
                'gallons' => $line['gallons'],
                'source_event_ids' => $line['source_event_ids'],
                'needs_review' => $line['needs_review'],
            ]);
        }
    }
}
