<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * @mixin \App\Models\SensoryNote
 */
class SensoryNoteResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lot_id' => $this->lot_id,
            'taster_id' => $this->taster_id,
            'date' => $this->date->toDateString(),
            'rating' => $this->rating !== null ? (float) $this->rating : null,
            'rating_scale' => $this->rating_scale,
            'nose_notes' => $this->nose_notes,
            'palate_notes' => $this->palate_notes,
            'overall_notes' => $this->overall_notes,
            'lot' => $this->whenLoaded('lot', fn () => [
                'id' => $this->lot->id,
                'name' => $this->lot->name,
                'variety' => $this->lot->variety,
                'vintage' => $this->lot->vintage,
            ]),
            'taster' => $this->whenLoaded('taster', fn () => [
                'id' => $this->taster->id,
                'name' => $this->taster->name,
            ]),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
