<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LabAnalysis;
use Illuminate\Http\Request;

/**
 * API resource for LabAnalysis model.
 *
 * @mixin LabAnalysis
 */
class LabAnalysisResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lot_id' => $this->lot_id,
            'test_date' => $this->test_date->toDateString(),
            'test_type' => $this->test_type,
            'value' => (float) $this->value,
            'unit' => $this->unit,
            'method' => $this->method,
            'analyst' => $this->analyst,
            'notes' => $this->notes,
            'source' => $this->source,
            'lot' => $this->relationLoaded('lot') && $this->lot ? [
                'id' => $this->lot->id,
                'name' => $this->lot->name,
                'variety' => $this->lot->variety,
                'vintage' => $this->lot->vintage,
            ] : null,
            'performed_by' => $this->relationLoaded('performer') && $this->performer ? [
                'id' => $this->performer->id,
                'name' => $this->performer->name,
            ] : null,
            'threshold_alerts' => $this->getAttribute('threshold_alerts') ?? [],
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
