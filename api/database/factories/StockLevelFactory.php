<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CaseGoodsSku;
use App\Models\Location;
use App\Models\StockLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLevel>
 */
class StockLevelFactory extends Factory
{
    protected $model = StockLevel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku_id' => CaseGoodsSku::factory(),
            'location_id' => Location::factory(),
            'on_hand' => $this->faker->numberBetween(0, 500),
            'committed' => $this->faker->numberBetween(0, 50),
        ];
    }

    /**
     * Create a stock level with no inventory.
     */
    public function empty(): static
    {
        return $this->state(fn () => [
            'on_hand' => 0,
            'committed' => 0,
        ]);
    }

    /**
     * Create a stock level with high inventory.
     */
    public function wellStocked(): static
    {
        return $this->state(fn () => [
            'on_hand' => $this->faker->numberBetween(200, 1000),
            'committed' => $this->faker->numberBetween(0, 20),
        ]);
    }
}
