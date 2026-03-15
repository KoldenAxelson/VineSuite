<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\LabImport\LabImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lab CSV Import controller — two-phase import workflow.
 *
 * POST /lab-import/preview  — Upload CSV, get parsed preview with lot matches
 * POST /lab-import/commit   — Confirm and commit the previewed records
 */
class LabImportController extends Controller
{
    public function __construct(
        protected LabImportService $importService,
    ) {}

    /**
     * Phase 1: Upload CSV and get a preview of parsed records.
     *
     * Accepts a file upload (multipart/form-data) with the CSV file.
     * Returns parsed records with lot match suggestions for user review.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'], // 5MB max
        ]);

        $csvContent = file_get_contents($request->file('file')->getRealPath());

        if ($csvContent === false || $csvContent === '') {
            return ApiResponse::error('Could not read the uploaded file.', 422);
        }

        $result = $this->importService->preview($csvContent);

        return ApiResponse::success($result);
    }

    /**
     * Phase 2: Commit confirmed records to the database.
     *
     * Accepts an array of records (from the preview response) with
     * user-confirmed lot_id assignments. Records without a lot_id are skipped.
     */
    public function commit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'records' => ['required', 'array', 'min:1'],
            'records.*.lot_id' => ['required', 'uuid'],
            'records.*.test_date' => ['required', 'date'],
            'records.*.test_type' => ['required', 'string'],
            'records.*.value' => ['required', 'numeric'],
            'records.*.unit' => ['required', 'string', 'max:30'],
            'records.*.method' => ['nullable', 'string', 'max:100'],
            'records.*.analyst' => ['nullable', 'string', 'max:255'],
            'records.*.notes' => ['nullable', 'string'],
            'source' => ['required', 'string', 'in:ets_labs,oenofoss,wine_scan,csv_import'],
        ]);

        $result = $this->importService->commit(
            records: $validated['records'],
            source: $validated['source'],
            performedBy: $request->user()->id,
        );

        $status = empty($result['errors']) ? 200 : 207; // 207 Multi-Status if partial failures

        return ApiResponse::success($result, meta: [
            'message' => "{$result['imported']} records imported successfully.",
        ]);
    }
}
