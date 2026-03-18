<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PhysicalCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PhysicalCount
 */
class PhysicalCountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'location' => $this->relationLoaded('location') ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ] : null,
            'status' => $this->status,
            'started_by' => $this->started_by,
            'started_at' => $this->started_at->toIso8601String(),
            'completed_by' => $this->completed_by,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,
            'lines' => $this->relationLoaded('lines')
                ? $this->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'sku_id' => $line->sku_id,
                    'sku' => $line->relationLoaded('sku') ? [
                        'id' => $line->sku->id,
                        'wine_name' => $line->sku->wine_name,
                        'vintage' => $line->sku->vintage,
                        'varietal' => $line->sku->varietal,
                        'format' => $line->sku->format,
                        'upc_barcode' => $line->sku->upc_barcode,
                    ] : null,
                    'system_quantity' => $line->system_quantity,
                    'counted_quantity' => $line->counted_quantity,
                    'variance' => $line->variance,
                    'notes' => $line->notes,
                ])
                : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
