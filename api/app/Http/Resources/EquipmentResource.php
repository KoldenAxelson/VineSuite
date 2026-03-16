<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Equipment
 */
class EquipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'equipment_type' => $this->equipment_type,
            'serial_number' => $this->serial_number,
            'manufacturer' => $this->manufacturer,
            'model_number' => $this->model_number,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'purchase_value' => $this->purchase_value !== null ? (float) $this->purchase_value : null,
            'location' => $this->location,
            'status' => $this->status,
            'next_maintenance_due' => $this->next_maintenance_due?->toDateString(),
            'is_maintenance_overdue' => $this->isMaintenanceOverdue(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'maintenance_logs' => $this->relationLoaded('maintenanceLogs')
                ? MaintenanceLogResource::collection($this->maintenanceLogs)
                : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
