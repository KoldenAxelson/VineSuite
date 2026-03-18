<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Barrel;
use Illuminate\Http\Request;

/**
 * API resource for Barrel model.
 *
 * Combines barrel metadata with the parent vessel's operational data.
 * Returns a flat structure with both barrel and vessel fields for ease
 * of use by the client.
 *
 * @mixin Barrel
 */
class BarrelResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $vessel = $this->vessel;

        return [
            'id' => $this->id,
            'vessel_id' => $this->vessel_id,

            // Vessel fields
            'name' => $vessel->name,
            'location' => $vessel->location,
            'status' => $vessel->status,
            'purchase_date' => $vessel->purchase_date?->toDateString(),
            'notes' => $vessel->notes,
            'current_volume' => $vessel->current_volume,
            'fill_percent' => $vessel->fill_percent,

            // Barrel-specific fields
            'cooperage' => $this->cooperage,
            'toast_level' => $this->toast_level,
            'oak_type' => $this->oak_type,
            'forest_origin' => $this->forest_origin,
            'volume_gallons' => (float) $this->volume_gallons,
            'years_used' => $this->years_used,
            'qr_code' => $this->qr_code,

            // Current lot info (from the vessel's currentLot relationship)
            'current_lot' => $vessel->relationLoaded('currentLot') && $vessel->currentLot->isNotEmpty()
                ? [
                    'id' => $vessel->currentLot->first()->id,
                    'name' => $vessel->currentLot->first()->name,
                    'variety' => $vessel->currentLot->first()->variety,
                    'vintage' => $vessel->currentLot->first()->vintage,
                ]
                : null,

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
