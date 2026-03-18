<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\TTBReport;
use App\Models\TTBReportLine;
use App\Models\WineryProfile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;

/**
 * TTBReportPdfGenerator — generates a PDF version of the TTB Form 5120.17.
 *
 * Uses DomPDF to render a Blade template matching the official form layout.
 * PDF is stored and linked to the report record.
 */
class TTBReportPdfGenerator
{
    /**
     * Generate PDF for a TTB report and store it.
     *
     * @return string The storage path of the generated PDF
     */
    public function generate(TTBReport $report): string
    {
        $wineryProfile = WineryProfile::first();
        $linesBySection = $this->getLinesBySection($report);
        $sectionASummary = $report->data['section_a']['summary'] ?? [];
        $sectionBSummary = $report->data['section_b']['summary'] ?? [];

        $pdf = Pdf::loadView('pdf.ttb-5120-17', [
            'report' => $report,
            'winery' => $wineryProfile,
            'linesBySection' => $linesBySection,
            'sectionASummary' => $sectionASummary,
            'sectionBSummary' => $sectionBSummary,
            'reviewFlags' => $report->data['review_flags'] ?? [],
        ]);

        $pdf->setPaper('letter', 'portrait');

        $filename = sprintf(
            'ttb-5120-17-%04d-%02d.pdf',
            $report->report_period_year,
            $report->report_period_month,
        );

        $storagePath = 'ttb-reports/'.$filename;
        $fullPath = storage_path('app/'.$storagePath);

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdf->save($fullPath);

        // Update report with PDF path
        $report->update(['pdf_path' => $storagePath]);

        return $storagePath;
    }

    /**
     * Get line items grouped by section (A/B) for rendering.
     *
     * @return array<string, Collection<int, TTBReportLine>>
     */
    private function getLinesBySection(TTBReport $report): array
    {
        $lines = $report->lines()->orderBy('section')->orderBy('line_number')->get();

        $grouped = [];
        foreach (['A', 'B'] as $section) {
            $grouped[$section] = $lines->where('section', $section)->values();
        }

        return $grouped;
    }
}
