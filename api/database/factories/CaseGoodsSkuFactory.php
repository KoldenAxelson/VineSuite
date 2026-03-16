<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CaseGoodsSku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CaseGoodsSku>
 */
class CaseGoodsSkuFactory extends Factory
{
    protected $model = CaseGoodsSku::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $varietals = [
            'Cabernet Sauvignon', 'Pinot Noir', 'Chardonnay', 'Merlot',
            'Zinfandel', 'Syrah', 'Sauvignon Blanc', 'Riesling',
        ];

        $varietal = $this->faker->randomElement($varietals);
        $vintage = $this->faker->numberBetween(2020, 2025);

        return [
            'wine_name' => "{$vintage} Estate {$varietal}",
            'vintage' => $vintage,
            'varietal' => $varietal,
            'format' => '750ml',
            'case_size' => 12,
            'upc_barcode' => $this->faker->optional(0.7)->ean13(),
            'price' => $this->faker->randomFloat(2, 15, 85),
            'cost_per_bottle' => $this->faker->optional(0.5)->randomFloat(2, 5, 25),
            'is_active' => true,
            'tasting_notes' => $this->faker->optional(0.6)->paragraph(),
        ];
    }

    /**
     * Indicate the SKU is inactive (discontinued).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a half-bottle (375ml) SKU.
     */
    public function halfBottle(): static
    {
        return $this->state(fn (array $attributes) => [
            'format' => '375ml',
            'case_size' => 12,
        ]);
    }

    /**
     * Create a magnum (1.5L) SKU.
     */
    public function magnum(): static
    {
        return $this->state(fn (array $attributes) => [
            'format' => '1.5L',
            'case_size' => 6,
        ]);
    }
}
