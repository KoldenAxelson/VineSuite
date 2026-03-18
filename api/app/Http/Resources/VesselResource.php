<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Vessel;
use Illuminate\Http\Request;

/**
 * API resource for Vessel model.
 *
 * Wraps vessel data in the standard API envelope via BaseResource.
 * Includes current contents (lot + volume + fill %) when loaded.
 *
 * @mixin Vessel
 */
class VesselResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'capacity_gallons' => (float) $this->capacity_gallons,
            'material' => $this->material,
            'location' => $this->location,
            'status' => $this->status,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'notes' => $this->notes,
            'current_volume' => $this->current_volume,
            'fill_percent' => $this->fill_percent,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];

        // Include current lot info when the relationship is loaded
        if ($this->relationLoaded('currentLot') && $this->currentLot->isNotEmpty()) {
            $currentLot = $this->currentLot->first();
            $data['current_lot'] = [
                'id' => $currentLot->id,
                'name' => $currentLot->name,
                'variety' => $currentLot->variety,
                'vintage' => $currentLot->vintage,
            ];
        } else {
            $data['current_lot'] = null;
        }

        // Include barrel metadata when loaded (only for type=barrel)
        if ($this->relationLoaded('barrel') && $this->barrel) {
            $data['barrel'] = [
                'id' => $this->barrel->id,
                'cooperage' => $this->barrel->cooperage,
                'toast_level' => $this->barrel->toast_level,
                'oak_type' => $this->barrel->oak_type,
                'forest_origin' => $this->barrel->forest_origin,
                'volume_gallons' => (float) $this->barrel->volume_gallons,
                'years_used' => $this->barrel->years_used,
                'qr_code' => $this->barrel->qr_code,
            ];
        }

        return $data;
    }
}
