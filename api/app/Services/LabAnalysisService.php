<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LabAnalysis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LabAnalysisService — business logic for lab analysis entries.
 *
 * Each lab analysis is a single measurement (pH, TA, VA, etc.) for a lot on a date.
 * Event payloads are self-contained per the data-portability design constraint —
 * they include lot name and variety alongside lot_id for export readability.
 */
class LabAnalysisService
{
    public function __construct(
        protected EventLogger $eventLogger,
    ) {}

    /**
     * Record a lab analysis and write a lab_analysis_entered event.
     *
     * @param  array<string, mixed>  $data  Validated analysis data
     * @param  string  $performedBy  UUID of the user entering the record
     */
    public function createAnalysis(array $data, string $performedBy): LabAnalysis
    {
        return DB::transaction(function () use ($data, $performedBy) {
            $data['performed_by'] = $performedBy;

            $analysis = LabAnalysis::create($data);
            $analysis->load('lot');

            // Write event with self-contained payload (export-friendly per data-portability constraint)
            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $analysis->lot_id,
                operationType: 'lab_analysis_entered',
                payload: [
                    'analysis_id' => $analysis->id,
                    'lot_name' => $analysis->lot->name,
                    'lot_variety' => $analysis->lot->variety,
                    'test_type' => $analysis->test_type,
                    'value' => (float) $analysis->value,
                    'unit' => $analysis->unit,
                    'method' => $analysis->method,
                    'analyst' => $analysis->analyst,
                    'source' => $analysis->source,
                    'test_date' => $analysis->test_date->toDateString(),
                ],
                performedBy: $performedBy,
                performedAt: $analysis->test_date,
            );

            Log::info('Lab analysis recorded', [
                'analysis_id' => $analysis->id,
                'lot_id' => $analysis->lot_id,
                'test_type' => $analysis->test_type,
                'value' => $analysis->value,
                'unit' => $analysis->unit,
                'source' => $analysis->source,
                'tenant_id' => tenant('id'),
                'user_id' => $performedBy,
            ]);

            return $analysis->load('performer');
        });
    }

    /**
     * Get the latest value for a specific test type on a lot.
     */
    public function getLatestValue(string $lotId, string $testType): ?LabAnalysis
    {
        return LabAnalysis::where('lot_id', $lotId)
            ->where('test_type', $testType)
            ->orderByDesc('test_date')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Get analysis history for a lot and test type (for charting).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, LabAnalysis>
     */
    public function getHistory(string $lotId, string $testType): \Illuminate\Database\Eloquent\Collection
    {
        return LabAnalysis::where('lot_id', $lotId)
            ->where('test_type', $testType)
            ->orderBy('test_date')
            ->orderBy('created_at')
            ->get();
    }
}
