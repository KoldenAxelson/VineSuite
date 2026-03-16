<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku_id' => $this->sku_id,
            'location_id' => $this->location_id,
            'movement_type' => $this->movement_type,
            'quantity' => $this->quantity,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'performed_by' => $this->performed_by,
            'performed_at' => $this->performed_at->toIso8601String(),
            'notes' => $this->notes,
            'sku' => $this->relationLoaded('sku') ? [
                'id' => $this->sku->id,
                'wine_name' => $this->sku->wine_name,
                'vintage' => $this->sku->vintage,
                'varietal' => $this->sku->varietal,
                'format' => $this->sku->format,
            ] : null,
            'location' => $this->relationLoaded('location') ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
