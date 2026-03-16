<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\MaintenanceLog
 */
class MaintenanceLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'maintenance_type' => $this->maintenance_type,
            'performed_date' => $this->performed_date->toDateString(),
            'performed_by' => $this->performed_by,
            'description' => $this->description,
            'findings' => $this->findings,
            'cost' => $this->cost !== null ? (float) $this->cost : null,
            'next_due_date' => $this->next_due_date?->toDateString(),
            'passed' => $this->passed,
            'notes' => $this->notes,
            'equipment' => $this->relationLoaded('equipment')
                ? new EquipmentResource($this->equipment)
                : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
