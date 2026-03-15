<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * @mixin \App\Models\FilterLog
 *
 * @property \Illuminate\Support\Carbon $performed_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class FilterLogResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lot_id' => $this->lot_id,
            'vessel_id' => $this->vessel_id,
            'filter_type' => $this->filter_type,
            'filter_media' => $this->filter_media,
            'flow_rate_lph' => $this->flow_rate_lph ? (float) $this->flow_rate_lph : null,
            'volume_processed_gallons' => (float) $this->volume_processed_gallons,
            'fining_agent' => $this->fining_agent,
            'fining_rate' => $this->fining_rate ? (float) $this->fining_rate : null,
            'fining_rate_unit' => $this->fining_rate_unit,
            'bench_trial_notes' => $this->bench_trial_notes,
            'treatment_notes' => $this->treatment_notes,
            'pre_analysis_id' => $this->pre_analysis_id,
            'post_analysis_id' => $this->post_analysis_id,
            'performed_by' => $this->whenLoaded('performer', fn () => [
                'id' => $this->performer->id,
                'name' => $this->performer->name,
            ]),
            'performed_at' => $this->performed_at->toIso8601String(),
            'notes' => $this->notes,
            'lot' => $this->whenLoaded('lot', fn () => [
                'id' => $this->lot->id,
                'name' => $this->lot->name,
                'variety' => $this->lot->variety,
                'vintage' => $this->lot->vintage,
            ]),
            'vessel' => $this->whenLoaded('vessel', fn () => $this->vessel ? [
                'id' => $this->vessel->id,
                'name' => $this->vessel->name,
                'type' => $this->vessel->type,
                'location' => $this->vessel->location,
            ] : null),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
