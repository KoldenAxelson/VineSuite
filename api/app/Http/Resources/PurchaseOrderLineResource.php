<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PurchaseOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrderLine
 */
class PurchaseOrderLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'item_type' => $this->item_type,
            'item_id' => $this->item_id,
            'item_name' => $this->item_name,
            'quantity_ordered' => (float) $this->quantity_ordered,
            'quantity_received' => (float) $this->quantity_received,
            'quantity_remaining' => $this->quantityRemaining(),
            'cost_per_unit' => $this->cost_per_unit !== null ? (float) $this->cost_per_unit : null,
            'line_total' => round((float) $this->quantity_ordered * (float) ($this->cost_per_unit ?? 0), 2),
            'is_fully_received' => $this->isFullyReceived(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
