<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CaseGoodsSku;
use App\Models\Location;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku_id' => CaseGoodsSku::factory(),
            'location_id' => Location::factory(),
            'movement_type' => $this->faker->randomElement(StockMovement::MOVEMENT_TYPES),
            'quantity' => $this->faker->numberBetween(-50, 200),
            'reference_type' => $this->faker->optional(0.7)->randomElement(StockMovement::REFERENCE_TYPES),
            'reference_id' => $this->faker->optional(0.7)->uuid(),
            'performed_by' => null,
            'performed_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'notes' => $this->faker->optional(0.3)->sentence(),
        ];
    }

    /**
     * A stock receipt (positive quantity).
     */
    public function received(int $quantity = 100): static
    {
        return $this->state(fn () => [
            'movement_type' => 'received',
            'quantity' => $quantity,
        ]);
    }

    /**
     * A sale (negative quantity).
     */
    public function sold(int $quantity = 1): static
    {
        return $this->state(fn () => [
            'movement_type' => 'sold',
            'quantity' => -abs($quantity),
        ]);
    }

    /**
     * An adjustment (positive or negative).
     */
    public function adjusted(int $quantity = 0): static
    {
        return $this->state(fn () => [
            'movement_type' => 'adjusted',
            'quantity' => $quantity,
            'reference_type' => 'adjustment',
        ]);
    }
}
