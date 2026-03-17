<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\License;
use App\Models\TTBReport;
use App\Models\TTBReportLine;
use App\Models\User;
use App\Services\TTB\WineTypeClassifier;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds realistic TTB reports and licenses/permits for the demo winery.
 *
 * Creates:
 *   - 3 TTB Form 5120.17 reports (filed, reviewed, draft) with line items
 *   - 5 licenses/permits (TTB permit, state licenses, COLAs)
 *
 * Run within a tenant context via DemoWinerySeeder.
 */
class ComplianceSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Seeding compliance data (TTB reports + licenses)...');

        $this->seedLicenses();
        $this->seedTTBReports();

        $this->command?->info('Compliance data seeded.');
    }

    // ─── Licenses & Permits ──────────────────────────────────────

    private function seedLicenses(): void
    {
        $now = Carbon::now();

        $licenses = [
            [
                'license_type' => 'ttb_permit',
                'jurisdiction' => 'Federal',
                'license_number' => 'BWC-CA-19847',
                'issued_date' => $now->copy()->subYears(3)->startOfMonth(),
                'expiration_date' => null, // Federal basic permits don't expire
                'renewal_lead_days' => 90,
                'notes' => 'Basic permit to operate a bonded wine cellar. Issued under 27 U.S.C. § 204. No expiration — remains valid unless revoked or voluntarily surrendered.',
            ],
            [
                'license_type' => 'state_license',
                'jurisdiction' => 'California',
                'license_number' => 'CA-ABC-47291',
                'issued_date' => $now->copy()->subMonths(10)->startOfMonth(),
                'expiration_date' => $now->copy()->addMonths(2)->endOfMonth(),
                'renewal_lead_days' => 90,
                'notes' => 'Type 02 — Winegrower license issued by California ABC. Annual renewal required.',
            ],
            [
                'license_type' => 'state_license',
                'jurisdiction' => 'Oregon',
                'license_number' => 'OR-OLCC-WN-88412',
                'issued_date' => $now->copy()->subMonths(6)->startOfMonth(),
                'expiration_date' => $now->copy()->addMonths(6)->endOfMonth(),
                'renewal_lead_days' => 60,
                'notes' => 'Oregon direct shipper permit. Required for DTC sales to OR residents.',
            ],
            [
                'license_type' => 'cola',
                'jurisdiction' => 'Federal',
                'license_number' => 'COLA-25-00147',
                'issued_date' => $now->copy()->subMonths(4),
                'expiration_date' => null, // COLAs don't expire
                'renewal_lead_days' => 0,
                'notes' => 'Certificate of Label Approval for 2022 Estate Cabernet Sauvignon. TTB ID: 25001470000.',
            ],
            [
                'license_type' => 'cola',
                'jurisdiction' => 'Federal',
                'license_number' => 'COLA-25-00203',
                'issued_date' => $now->copy()->subMonths(2),
                'expiration_date' => null,
                'renewal_lead_days' => 0,
                'notes' => 'Certificate of Label Approval for 2023 Adelaida District GSM Blend. TTB ID: 25002030000.',
            ],
        ];

        foreach ($licenses as $license) {
            License::updateOrCreate(
                ['license_number' => $license['license_number']],
                $license,
            );
        }
    }

    // ─── TTB Reports ─────────────────────────────────────────────

    private function seedTTBReports(): void
    {
        $reviewer = User::where('role', 'winemaker')->first()
            ?? User::where('role', 'admin')->first();

        $now = Carbon::now();

        // Report 1: Filed — two months ago
        $this->createReport(
            month: $now->copy()->subMonths(2)->month,
            year: $now->copy()->subMonths(2)->year,
            status: 'filed',
            reviewer: $reviewer,
            filedAt: $now->copy()->subMonths(1)->day(5),
            openingBulk: 8200,
            produced: 3400,
            received: 0,
            bottled: 1800,
            losses: 42,
            openingBottled: 2400,
            bottledToB: 1800,
            soldFromB: 620,
        );

        // Report 2: Reviewed — last month
        $this->createReport(
            month: $now->copy()->subMonth()->month,
            year: $now->copy()->subMonth()->year,
            status: 'reviewed',
            reviewer: $reviewer,
            filedAt: null,
            openingBulk: 9758,
            produced: 1200,
            received: 500,
            bottled: 2600,
            losses: 58,
            openingBottled: 3580,
            bottledToB: 2600,
            soldFromB: 1450,
        );

        // Report 3: Draft — current month
        $this->createReport(
            month: $now->month,
            year: $now->year,
            status: 'draft',
            reviewer: null,
            filedAt: null,
            openingBulk: 8800,
            produced: 0,
            received: 0,
            bottled: 0,
            losses: 0,
            openingBottled: 4730,
            bottledToB: 0,
            soldFromB: 380,
        );
    }

    private function createReport(
        int $month,
        int $year,
        string $status,
        ?User $reviewer,
        ?Carbon $filedAt,
        float $openingBulk,
        float $produced,
        float $received,
        float $bottled,
        float $losses,
        float $openingBottled,
        float $bottledToB,
        float $soldFromB,
    ): void {
        $now = Carbon::now();
        $generatedAt = Carbon::create($year, $month, 1)->endOfMonth()->addDays(1);

        // Section A (Bulk) balance
        $totalIncreasesA = $openingBulk + $produced + $received;
        $totalDecreasesA = $bottled + $losses;
        $closingBulk = $totalIncreasesA - $totalDecreasesA;

        // Section B (Bottled) balance
        $totalIncreasesB = $openingBottled + $bottledToB;
        $totalDecreasesB = $soldFromB;
        $closingBottled = $totalIncreasesB - $totalDecreasesB;

        $sectionASummary = [
            'opening_inventory' => $openingBulk,
            'total_produced' => $produced,
            'total_received' => $received,
            'total_increases' => $totalIncreasesA,
            'total_bottled' => $bottled,
            'total_removed_taxpaid' => 0.0,
            'total_transferred' => 0.0,
            'total_losses' => $losses,
            'total_decreases' => $totalDecreasesA,
            'closing_inventory' => $closingBulk,
            'balanced' => true,
        ];

        $sectionBSummary = [
            'opening_inventory' => $openingBottled,
            'total_bottled' => $bottledToB,
            'total_received_in_bond' => 0.0,
            'total_increases' => $totalIncreasesB,
            'total_removed_taxpaid' => $soldFromB,
            'total_transferred' => 0.0,
            'total_breakage' => 0.0,
            'total_other_losses' => 0.0,
            'total_decreases' => $totalDecreasesB,
            'closing_inventory' => $closingBottled,
            'balanced' => true,
        ];

        $col = WineTypeClassifier::COL_A_NOT_OVER_16;
        $colLabel = WineTypeClassifier::COLUMN_LABELS[$col];

        // Build line items
        $sectionALines = [
            ['line_number' => 1, 'section' => 'A', 'category' => 'on_hand_beginning', 'wine_type' => 'all', 'description' => 'On hand beginning of period', 'gallons' => $openingBulk, 'source_event_ids' => [], 'needs_review' => false],
        ];
        if ($produced > 0) {
            $sectionALines[] = ['line_number' => 2, 'section' => 'A', 'category' => 'wine_produced', 'wine_type' => $col, 'description' => "Wine produced by fermentation — {$colLabel}", 'gallons' => $produced, 'source_event_ids' => [], 'needs_review' => false];
        }
        if ($received > 0) {
            $sectionALines[] = ['line_number' => 7, 'section' => 'A', 'category' => 'wine_received_transfer', 'wine_type' => $col, 'description' => "Wine received in bond — {$colLabel}", 'gallons' => $received, 'source_event_ids' => [], 'needs_review' => false];
        }
        $sectionALines[] = ['line_number' => 12, 'section' => 'A', 'category' => 'total_increases', 'wine_type' => 'all', 'description' => 'TOTAL (Lines 1-11)', 'gallons' => $totalIncreasesA, 'source_event_ids' => [], 'needs_review' => false];
        if ($bottled > 0) {
            $sectionALines[] = ['line_number' => 13, 'section' => 'A', 'category' => 'wine_bottled', 'wine_type' => $col, 'description' => "Wine bottled (removed from bulk) — {$colLabel}", 'gallons' => $bottled, 'source_event_ids' => [], 'needs_review' => false];
        }
        if ($losses > 0) {
            $sectionALines[] = ['line_number' => 29, 'section' => 'A', 'category' => 'losses_bottling_waste', 'wine_type' => $col, 'description' => "Bottling waste + transfer variance — {$colLabel}", 'gallons' => $losses, 'source_event_ids' => [], 'needs_review' => false];
        }
        $sectionALines[] = ['line_number' => 31, 'section' => 'A', 'category' => 'on_hand_end', 'wine_type' => 'all', 'description' => 'On hand end of period', 'gallons' => $closingBulk, 'source_event_ids' => [], 'needs_review' => false];
        $sectionALines[] = ['line_number' => 32, 'section' => 'A', 'category' => 'total_decreases', 'wine_type' => 'all', 'description' => 'TOTAL (Lines 13-31)', 'gallons' => $totalIncreasesA, 'source_event_ids' => [], 'needs_review' => false];

        $sectionBLines = [
            ['line_number' => 1, 'section' => 'B', 'category' => 'on_hand_beginning', 'wine_type' => 'all', 'description' => 'On hand beginning of period', 'gallons' => $openingBottled, 'source_event_ids' => [], 'needs_review' => false],
        ];
        if ($bottledToB > 0) {
            $sectionBLines[] = ['line_number' => 2, 'section' => 'B', 'category' => 'wine_bottled', 'wine_type' => $col, 'description' => "Bottled wine received (from bulk) — {$colLabel}", 'gallons' => $bottledToB, 'source_event_ids' => [], 'needs_review' => false];
        }
        $sectionBLines[] = ['line_number' => 7, 'section' => 'B', 'category' => 'total_increases', 'wine_type' => 'all', 'description' => 'TOTAL (Lines 1-6)', 'gallons' => $totalIncreasesB, 'source_event_ids' => [], 'needs_review' => false];
        if ($soldFromB > 0) {
            $sectionBLines[] = ['line_number' => 8, 'section' => 'B', 'category' => 'wine_sold', 'wine_type' => $col, 'description' => "Wine removed by sale (taxpaid) — {$colLabel}", 'gallons' => $soldFromB, 'source_event_ids' => [], 'needs_review' => false];
        }
        $sectionBLines[] = ['line_number' => 20, 'section' => 'B', 'category' => 'on_hand_end', 'wine_type' => 'all', 'description' => 'On hand end of period', 'gallons' => $closingBottled, 'source_event_ids' => [], 'needs_review' => false];
        $sectionBLines[] = ['line_number' => 21, 'section' => 'B', 'category' => 'total_decreases', 'wine_type' => 'all', 'description' => 'TOTAL (Lines 8-20)', 'gallons' => $totalIncreasesB, 'source_event_ids' => [], 'needs_review' => false];

        $reportData = [
            'period' => [
                'month' => $month,
                'year' => $year,
                'from' => Carbon::create($year, $month, 1)->toDateString(),
                'to' => Carbon::create($year, $month, 1)->endOfMonth()->toDateString(),
            ],
            'section_a' => [
                'summary' => $sectionASummary,
                'lines' => $sectionALines,
            ],
            'section_b' => [
                'summary' => $sectionBSummary,
                'lines' => $sectionBLines,
            ],
            'needs_review' => $status === 'draft',
            'review_flags' => $status === 'draft' ? ['Pending initial review'] : [],
            'generated_at' => $generatedAt->toIso8601String(),
        ];

        $report = TTBReport::updateOrCreate(
            [
                'report_period_month' => $month,
                'report_period_year' => $year,
            ],
            [
                'status' => $status,
                'generated_at' => $generatedAt,
                'reviewed_by' => $status !== 'draft' && $reviewer ? $reviewer->id : null,
                'reviewed_at' => $status !== 'draft' && $reviewer ? $generatedAt->copy()->addDays(3) : null,
                'filed_at' => $filedAt,
                'data' => $reportData,
                'notes' => $status === 'filed' ? 'Filed on time. No discrepancies.' : null,
            ],
        );

        // Clear existing lines on re-seed
        $report->lines()->delete();

        // Create line items
        $allLines = array_merge($sectionALines, $sectionBLines);
        foreach ($allLines as $line) {
            TTBReportLine::create([
                'ttb_report_id' => $report->id,
                'part' => 'I',
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
