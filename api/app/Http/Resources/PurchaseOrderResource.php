<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_name' => $this->vendor_name,
            'vendor_id' => $this->vendor_id,
            'order_date' => $this->order_date->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'status' => $this->status,
            'total_cost' => (float) $this->total_cost,
            'notes' => $this->notes,
            'lines' => $this->relationLoaded('lines')
                ? PurchaseOrderLineResource::collection($this->lines)
                : [],
            'is_fully_received' => $this->isFullyReceived(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
