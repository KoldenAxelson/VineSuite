<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FermentationRound;
use Illuminate\Http\Request;

/**
 * @mixin FermentationRound
 */
class FermentationRoundResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lot_id' => $this->lot_id,
            'round_number' => $this->round_number,
            'fermentation_type' => $this->fermentation_type,
            'inoculation_date' => $this->inoculation_date->toDateString(),
            'yeast_strain' => $this->yeast_strain,
            'ml_bacteria' => $this->ml_bacteria,
            'target_temp' => $this->target_temp !== null ? (float) $this->target_temp : null,
            'nutrients_schedule' => $this->nutrients_schedule,
            'status' => $this->status,
            'completion_date' => $this->completion_date?->toDateString(),
            'confirmation_date' => $this->confirmation_date?->toDateString(),
            'notes' => $this->notes,
            'lot' => $this->whenLoaded('lot', fn () => [
                'id' => $this->lot->id,
                'name' => $this->lot->name,
                'variety' => $this->lot->variety,
                'vintage' => $this->lot->vintage,
            ]),
            'entries_count' => $this->whenCounted('entries'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
