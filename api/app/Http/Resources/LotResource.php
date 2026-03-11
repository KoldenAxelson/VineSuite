<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * API resource for Lot model.
 *
 * Wraps lot data in the standard API envelope via BaseResource.
 *
 * @mixin \App\Models\Lot
 */
class LotResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'variety' => $this->variety,
            'vintage' => $this->vintage,
            'source_type' => $this->source_type,
            'source_details' => $this->source_details,
            'volume_gallons' => (float) $this->volume_gallons,
            'status' => $this->status,
            'parent_lot_id' => $this->parent_lot_id,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
