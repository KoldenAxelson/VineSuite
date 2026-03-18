<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StockLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockLevel
 */
class StockLevelResource extends JsonResource
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
            'on_hand' => $this->on_hand,
            'committed' => $this->committed,
            'available' => $this->available,
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
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
