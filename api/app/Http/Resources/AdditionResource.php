<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Addition;
use Illuminate\Http\Request;

/**
 * API resource for Addition model.
 *
 * @mixin Addition
 */
class AdditionResource extends BaseResource
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
            'addition_type' => $this->addition_type,
            'product_name' => $this->product_name,
            'rate' => $this->rate ? (float) $this->rate : null,
            'rate_unit' => $this->rate_unit,
            'total_amount' => (float) $this->total_amount,
            'total_unit' => $this->total_unit,
            'reason' => $this->reason,
            'performed_at' => $this->performed_at->toIso8601String(),
            'inventory_item_id' => $this->inventory_item_id,
            'lot' => $this->relationLoaded('lot') && $this->lot ? [
                'id' => $this->lot->id,
                'name' => $this->lot->name,
                'variety' => $this->lot->variety,
                'vintage' => $this->lot->vintage,
            ] : null,
            'vessel' => $this->relationLoaded('vessel') && $this->vessel ? [
                'id' => $this->vessel->id,
                'name' => $this->vessel->name,
                'type' => $this->vessel->type,
                'location' => $this->vessel->location,
            ] : null,
            'performed_by' => $this->relationLoaded('performer') && $this->performer ? [
                'id' => $this->performer->id,
                'name' => $this->performer->name,
            ] : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
