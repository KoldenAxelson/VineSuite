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
        private readonly float $openingBulkInventory = 0.0,
        private readonly float $openingBottledInventory = 0.0,
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

            // Determine opening inventories
            $openingBulkInventory = $this->openingBulkInventory;
            $openingBottledInventory = $this->openingBottledInventory;
            if ($openingBulkInventory === 0.0 && $openingBottledInventory === 0.0) {
                $generator = app(TTBReportGenerator::class);
                $previous = $generator->getPreviousClosingInventory($this->month, $this->year);
                if ($previous !== null) {
                    $openingBulkInventory = $previous['bulk'];
                    $openingBottledInventory = $previous['bottled'];
                }
            }

            // Generate report data
            $generator = app(TTBReportGenerator::class);
            $reportData = $generator->generate($this->month, $this->year, $openingBulkInventory, $openingBottledInventory);

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

                // Create line items for Section A (bulk wines)
                $this->createLineItems($report, 'I', $reportData['section_a']['lines']);
                // Create line items for Section B (bottled wines)
                $this->createLineItems($report, 'I', $reportData['section_b']['lines']);

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
                        'section_a' => $reportData['section_a']['summary'],
                        'section_b' => $reportData['section_b']['summary'],
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
     * Create line items for a report section.
     *
     * @param  array<int, array{section: string, line_number: int, category: string, wine_type: string, description: string, gallons: int, source_event_ids: array<int, string>, needs_review: bool}>  $lines
     */
    private function createLineItems(TTBReport $report, string $part, array $lines): void
    {
        foreach ($lines as $line) {
            TTBReportLine::create([
                'ttb_report_id' => $report->id,
                'part' => $part,
                'section' => $line['section'],
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
