<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\RawMaterial
 */
class RawMaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'unit_of_measure' => $this->unit_of_measure,
            'on_hand' => (float) $this->on_hand,
            'reorder_point' => $this->reorder_point !== null ? (float) $this->reorder_point : null,
            'cost_per_unit' => $this->cost_per_unit !== null ? (float) $this->cost_per_unit : null,
            'needs_reorder' => $this->needsReorder(),
            'expiration_date' => $this->expiration_date?->toDateString(),
            'is_expired' => $this->isExpired(),
            'vendor_name' => $this->vendor_name,
            'vendor_id' => $this->vendor_id,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
