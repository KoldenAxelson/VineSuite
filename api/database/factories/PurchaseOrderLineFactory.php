<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PurchaseOrderLine>
 */
class PurchaseOrderLineFactory extends Factory
{
    protected $model = PurchaseOrderLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $isDryGoods = $this->faker->boolean(60);

        return [
            'item_type' => $isDryGoods ? 'dry_goods' : 'raw_material',
            'item_id' => Str::uuid()->toString(),
            'item_name' => $isDryGoods
                ? $this->faker->randomElement(['750ml Burgundy Bottle', 'Natural Cork #9x45', 'Tin Capsule Gold', 'Front Label Cab 2024', 'Back Label Generic', '12-Bottle Carton'])
                : $this->faker->randomElement(['Potassium Metabisulfite', 'EC-1118 Yeast', 'Fermaid O Nutrient', 'Bentonite', 'Tartaric Acid', 'Lallzyme EX-V']),
            'quantity_ordered' => $this->faker->randomFloat(2, 10, 5000),
            'quantity_received' => 0,
            'cost_per_unit' => $this->faker->randomFloat(4, 0.05, 25.00),
        ];
    }

    public function dryGoods(): static
    {
        return $this->state(fn () => [
            'item_type' => 'dry_goods',
            'item_name' => $this->faker->randomElement(['750ml Burgundy Bottle', 'Natural Cork #9x45', 'Tin Capsule Gold']),
        ]);
    }

    public function rawMaterial(): static
    {
        return $this->state(fn () => [
            'item_type' => 'raw_material',
            'item_name' => $this->faker->randomElement(['Potassium Metabisulfite', 'EC-1118 Yeast', 'Tartaric Acid']),
        ]);
    }

    public function fullyReceived(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'quantity_received' => $attributes['quantity_ordered'] ?? 100,
            ];
        });
    }

    public function partiallyReceived(): static
    {
        return $this->state(function (array $attributes) {
            $ordered = $attributes['quantity_ordered'] ?? 100;

            return [
                'quantity_received' => round($ordered * 0.5, 2),
            ];
        });
    }
}
