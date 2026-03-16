<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * API resource for CaseGoodsSku model.
 *
 * @mixin \App\Models\CaseGoodsSku
 */
class CaseGoodsSkuResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wine_name' => $this->wine_name,
            'vintage' => $this->vintage,
            'varietal' => $this->varietal,
            'format' => $this->format,
            'case_size' => $this->case_size,
            'upc_barcode' => $this->upc_barcode,
            'price' => $this->price,
            'cost_per_bottle' => $this->cost_per_bottle,
            'is_active' => $this->is_active,
            'image_path' => $this->image_path,
            'tasting_notes' => $this->tasting_notes,
            'tech_sheet_path' => $this->tech_sheet_path,
            'lot_id' => $this->lot_id,
            'bottling_run_id' => $this->bottling_run_id,
            'lot' => $this->relationLoaded('lot') && $this->lot ? [
                'id' => $this->lot->id,
                'name' => $this->lot->name,
                'variety' => $this->lot->variety,
                'vintage' => $this->lot->vintage,
            ] : null,
            'bottling_run' => $this->relationLoaded('bottlingRun') && $this->bottlingRun ? [
                'id' => $this->bottlingRun->id,
                'bottle_format' => $this->bottlingRun->bottle_format,
                'bottles_filled' => $this->bottlingRun->bottles_filled,
                'status' => $this->bottlingRun->status,
            ] : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
