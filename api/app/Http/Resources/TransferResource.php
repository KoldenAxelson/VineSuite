<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Transfer;
use Illuminate\Http\Request;

/**
 * API resource for Transfer model.
 *
 * @mixin Transfer
 */
class TransferResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lot_id' => $this->lot_id,
            'from_vessel_id' => $this->from_vessel_id,
            'to_vessel_id' => $this->to_vessel_id,
            'volume_gallons' => (float) $this->volume_gallons,
            'transfer_type' => $this->transfer_type,
            'variance_gallons' => (float) $this->variance_gallons,
            'performed_at' => $this->performed_at->toIso8601String(),
            'notes' => $this->notes,
            'lot' => $this->relationLoaded('lot') && $this->lot ? [
                'id' => $this->lot->id,
                'name' => $this->lot->name,
                'variety' => $this->lot->variety,
            ] : null,
            'from_vessel' => $this->relationLoaded('fromVessel') && $this->fromVessel ? [
                'id' => $this->fromVessel->id,
                'name' => $this->fromVessel->name,
                'type' => $this->fromVessel->type,
            ] : null,
            'to_vessel' => $this->relationLoaded('toVessel') && $this->toVessel ? [
                'id' => $this->toVessel->id,
                'name' => $this->toVessel->name,
                'type' => $this->toVessel->type,
            ] : null,
            'performed_by' => $this->relationLoaded('performer') && $this->performer ? [
                'id' => $this->performer->id,
                'name' => $this->performer->name,
            ] : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
