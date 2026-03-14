<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * @mixin \App\Models\PressLog
 */
class PressLogResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lot_id' => $this->lot_id,
            'vessel_id' => $this->vessel_id,
            'press_type' => $this->press_type,
            'fruit_weight_kg' => (float) $this->fruit_weight_kg,
            'total_juice_gallons' => (float) $this->total_juice_gallons,
            'fractions' => $this->fractions,
            'yield_percent' => (float) $this->yield_percent,
            'pomace_weight_kg' => $this->pomace_weight_kg ? (float) $this->pomace_weight_kg : null,
            'pomace_destination' => $this->pomace_destination,
            'performed_by' => $this->whenLoaded('performer', fn () => [
                'id' => $this->performer->id,
                'name' => $this->performer->name,
            ]),
            'performed_at' => $this->performed_at->toIso8601String(),
            'notes' => $this->notes,
            'lot' => $this->whenLoaded('lot', fn () => [
                'id' => $this->lot->id,
                'name' => $this->lot->name,
                'variety' => $this->lot->variety,
                'vintage' => $this->lot->vintage,
            ]),
            'vessel' => $this->whenLoaded('vessel', fn () => $this->vessel ? [
                'id' => $this->vessel->id,
                'name' => $this->vessel->name,
                'type' => $this->vessel->type,
                'location' => $this->vessel->location,
            ] : null),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
