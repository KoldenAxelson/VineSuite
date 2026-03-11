<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * API resource for WorkOrder model.
 *
 * Includes related lot, vessel, and user info when loaded.
 *
 * @mixin \App\Models\WorkOrder
 */
class WorkOrderResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operation_type' => $this->operation_type,
            'status' => $this->status,
            'priority' => $this->priority,
            'due_date' => $this->due_date?->toDateString(),
            'notes' => $this->notes,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completion_notes' => $this->completion_notes,
            'template_id' => $this->template_id,

            // Related lot (when loaded)
            'lot' => $this->relationLoaded('lot') && $this->lot
                ? [
                    'id' => $this->lot->id,
                    'name' => $this->lot->name,
                    'variety' => $this->lot->variety,
                    'vintage' => $this->lot->vintage,
                ]
                : null,

            // Related vessel (when loaded)
            'vessel' => $this->relationLoaded('vessel') && $this->vessel
                ? [
                    'id' => $this->vessel->id,
                    'name' => $this->vessel->name,
                    'type' => $this->vessel->type,
                ]
                : null,

            // Assigned user
            'assigned_to' => $this->relationLoaded('assignedUser') && $this->assignedUser
                ? [
                    'id' => $this->assignedUser->id,
                    'name' => $this->assignedUser->name,
                ]
                : ($this->assigned_to ? ['id' => $this->assigned_to] : null),

            // Completed by user
            'completed_by' => $this->relationLoaded('completedByUser') && $this->completedByUser
                ? [
                    'id' => $this->completedByUser->id,
                    'name' => $this->completedByUser->name,
                ]
                : ($this->completed_by ? ['id' => $this->completed_by] : null),

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
