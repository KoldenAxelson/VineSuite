<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Location
 */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'is_active' => $this->is_active,
            'stock_levels' => $this->relationLoaded('stockLevels')
                ? $this->stockLevels->map(fn ($level) => [
                    'id' => $level->id,
                    'sku_id' => $level->sku_id,
                    'sku' => $level->relationLoaded('sku') ? [
                        'id' => $level->sku->id,
                        'wine_name' => $level->sku->wine_name,
                        'vintage' => $level->sku->vintage,
                        'varietal' => $level->sku->varietal,
                        'format' => $level->sku->format,
                    ] : null,
                    'on_hand' => $level->on_hand,
                    'committed' => $level->committed,
                    'available' => $level->available,
                ])
                : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
