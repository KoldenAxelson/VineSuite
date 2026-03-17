<?php

declare(strict_types=1);

namespace App\Services\TTB;

use App\Models\TTBReport;
use App\Models\WineryProfile;
use Barryvdh\DomPDF\Facade\Pdf;

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
        $linesByPart = $this->getLinesByPart($report);
        $summary = $report->data['part_one']['summary'] ?? [];

        /** @phpstan-ignore-next-line */
        $pdf = Pdf::loadView('pdf.ttb-5120-17', [
            'report' => $report,
            'winery' => $wineryProfile,
            'linesByPart' => $linesByPart,
            'summary' => $summary,
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
     * Get line items grouped by part for rendering.
     *
     * @return array<string, \Illuminate\Database\Eloquent\Collection<int, \App\Models\TTBReportLine>>
     */
    private function getLinesByPart(TTBReport $report): array
    {
        $lines = $report->lines()->orderBy('part')->orderBy('line_number')->get();

        $grouped = [];
        foreach (['I', 'II', 'III', 'IV', 'V'] as $part) {
            $grouped[$part] = $lines->where('part', $part)->values();
        }

        return $grouped;
    }
}
