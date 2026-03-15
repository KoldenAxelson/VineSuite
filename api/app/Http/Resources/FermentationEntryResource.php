<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * @mixin \App\Models\FermentationEntry
 */
class FermentationEntryResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fermentation_round_id' => $this->fermentation_round_id,
            'entry_date' => $this->entry_date->toDateString(),
            'temperature' => $this->temperature !== null ? (float) $this->temperature : null,
            'brix_or_density' => $this->brix_or_density !== null ? (float) $this->brix_or_density : null,
            'measurement_type' => $this->measurement_type,
            'free_so2' => $this->free_so2 !== null ? (float) $this->free_so2 : null,
            'notes' => $this->notes,
            'performer' => $this->whenLoaded('performer', fn () => [
                'id' => $this->performer->id,
                'name' => $this->performer->name,
            ]),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
