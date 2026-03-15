<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * @mixin \App\Models\BottlingRun
 *
 * @property \Illuminate\Support\Carbon|null $bottled_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class BottlingRunResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lot_id' => $this->lot_id,
            'bottle_format' => $this->bottle_format,
            'bottles_filled' => (int) $this->bottles_filled,
            'bottles_breakage' => (int) $this->bottles_breakage,
            'waste_percent' => (float) $this->waste_percent,
            'volume_bottled_gallons' => (float) $this->volume_bottled_gallons,
            'status' => $this->status,
            'sku' => $this->sku,
            'cases_produced' => $this->cases_produced ? (int) $this->cases_produced : null,
            'bottles_per_case' => (int) $this->bottles_per_case,
            'lot' => $this->whenLoaded('lot', fn () => [
                'id' => $this->lot->id,
                'name' => $this->lot->name,
                'variety' => $this->lot->variety,
                'vintage' => $this->lot->vintage,
                'volume_gallons' => (float) $this->lot->volume_gallons,
            ]),
            'performed_by' => $this->whenLoaded('performer', fn () => $this->performer ? [
                'id' => $this->performer->id,
                'name' => $this->performer->name,
            ] : null),
            'components' => $this->whenLoaded('components', fn () => $this->components->map(fn ($c) => [
                'id' => $c->id,
                'component_type' => $c->component_type,
                'product_name' => $c->product_name,
                'quantity_used' => (int) $c->quantity_used,
                'quantity_wasted' => (int) $c->quantity_wasted,
                'unit' => $c->unit,
            ])),
            'bottled_at' => $this->bottled_at instanceof \DateTimeInterface
                ? $this->bottled_at->toIso8601String()
                : $this->bottled_at,
            'completed_at' => $this->completed_at instanceof \DateTimeInterface
                ? $this->completed_at->toIso8601String()
                : $this->completed_at,
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
