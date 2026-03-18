<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LabThreshold;
use Illuminate\Http\Request;

/**
 * API resource for LabThreshold model.
 *
 * @mixin LabThreshold
 */
class LabThresholdResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_type' => $this->test_type,
            'variety' => $this->variety,
            'min_value' => $this->min_value !== null ? (float) $this->min_value : null,
            'max_value' => $this->max_value !== null ? (float) $this->max_value : null,
            'alert_level' => $this->alert_level,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
