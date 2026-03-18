<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BlendTrial;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * @mixin BlendTrial
 *
 * @property Carbon|null $finalized_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BlendTrialResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'version' => $this->version,
            'variety_composition' => $this->variety_composition,
            'ttb_label_variety' => $this->ttb_label_variety,
            'total_volume_gallons' => $this->total_volume_gallons ? (float) $this->total_volume_gallons : null,
            'resulting_lot_id' => $this->resulting_lot_id,
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'components' => $this->whenLoaded('components', fn () => $this->components->map(fn ($c) => [ // @phpstan-ignore return.type
                'id' => $c->id,
                'source_lot_id' => $c->source_lot_id,
                'percentage' => (float) $c->percentage,
                'volume_gallons' => (float) $c->volume_gallons,
                'source_lot' => $c->relationLoaded('sourceLot') ? [
                    'id' => $c->sourceLot->id,
                    'name' => $c->sourceLot->name,
                    'variety' => $c->sourceLot->variety,
                    'vintage' => $c->sourceLot->vintage,
                ] : null,
            ])),
            'resulting_lot' => $this->whenLoaded('resultingLot', fn () => $this->resultingLot ? [
                'id' => $this->resultingLot->id,
                'name' => $this->resultingLot->name,
                'variety' => $this->resultingLot->variety,
                'vintage' => $this->resultingLot->vintage,
                'volume_gallons' => (float) $this->resultingLot->volume_gallons,
            ] : null),
            'finalized_at' => $this->finalized_at instanceof \DateTimeInterface
                ? $this->finalized_at->format('c')
                : $this->finalized_at,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
