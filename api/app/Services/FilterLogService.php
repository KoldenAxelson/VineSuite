<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FilterLog;
use App\Support\LogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FilterLogService — business logic for filtering and fining operations.
 *
 * Simple log entry per spec: "Keep it simple — this is a log entry,
 * not a complex workflow." Pre/post analysis references will link to
 * lab analysis entries once 03-lab-fermentation.md is built.
 */
class FilterLogService
{
    public function __construct(
        private readonly EventLogger $eventLogger,
    ) {}

    /**
     * Log a filtering or fining operation.
     *
     * @param  array<string, mixed>  $data  Validated filter log data
     * @param  string  $performedBy  UUID of the user
     */
    public function logFiltering(array $data, string $performedBy): FilterLog
    {
        return DB::transaction(function () use ($data, $performedBy) {
            $data['performed_by'] = $performedBy;
            $data['performed_at'] = $data['performed_at'] ?? now();

            $filterLog = FilterLog::create($data);

            // Build event payload
            $payload = [
                'filter_log_id' => $filterLog->id,
                'filter_type' => $filterLog->filter_type,
                'filter_media' => $filterLog->filter_media,
                'volume_processed_gallons' => (float) $filterLog->volume_processed_gallons,
                'flow_rate_lph' => $filterLog->flow_rate_lph ? (float) $filterLog->flow_rate_lph : null,
            ];

            // Include fining details in event if present
            if ($filterLog->fining_agent) {
                $payload['fining_agent'] = $filterLog->fining_agent;
                $payload['fining_rate'] = $filterLog->fining_rate ? (float) $filterLog->fining_rate : null;
                $payload['fining_rate_unit'] = $filterLog->fining_rate_unit;
            }

            // Include analysis references if present
            if ($filterLog->pre_analysis_id) {
                $payload['pre_analysis_id'] = $filterLog->pre_analysis_id;
            }
            if ($filterLog->post_analysis_id) {
                $payload['post_analysis_id'] = $filterLog->post_analysis_id;
            }

            $this->eventLogger->log(
                entityType: 'lot',
                entityId: $filterLog->lot_id,
                operationType: 'filtering_logged',
                payload: $payload,
                performedBy: $performedBy,
                performedAt: $filterLog->performed_at,
            );

            Log::info('Filtering logged', LogContext::with([
                'filter_log_id' => $filterLog->id,
                'lot_id' => $filterLog->lot_id,
                'filter_type' => $filterLog->filter_type,
                'volume' => $filterLog->volume_processed_gallons,
                'fining_agent' => $filterLog->fining_agent,
            ], $performedBy));

            return $filterLog->load(['lot', 'vessel', 'performer']);
        });
    }
}
