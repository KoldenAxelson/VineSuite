<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BottlingComponent;
use App\Models\BottlingRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BottlingComponent>
 */
class BottlingComponentFactory extends Factory
{
    protected $model = BottlingComponent::class;

    /** @var array<string, array<int, string>> */
    private const PRODUCTS_BY_TYPE = [
        'bottle' => ['750ml Bordeaux Green', '750ml Burgundy Clear', '375ml Split Clear'],
        'cork' => ['Natural Cork #9', 'Synthetic Cork Premium', '1+1 Technical Cork'],
        'capsule' => ['Tin Capsule Black', 'PVC Capsule Burgundy', 'Polylaminate Gold'],
        'label' => ['Front Label 2024 CS', 'Back Label TTB Approved', 'Neck Label Gold'],
        'carton' => ['12-Bottle Shipper', '6-Bottle Gift Box', '12-Bottle Flat'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(BottlingComponent::COMPONENT_TYPES);
        $products = self::PRODUCTS_BY_TYPE[$type] ?? ['Generic'];

        return [
            'bottling_run_id' => BottlingRun::factory(),
            'component_type' => $type,
            'product_name' => $this->faker->randomElement($products),
            'quantity_used' => $this->faker->numberBetween(200, 2400),
            'quantity_wasted' => $this->faker->numberBetween(0, 20),
            'unit' => 'each',
            'inventory_item_id' => null,
            'notes' => null,
        ];
    }

    /**
     * A bottle component.
     */
    public function bottle(): static
    {
        return $this->state(fn () => [
            'component_type' => 'bottle',
            'product_name' => '750ml Bordeaux Green',
        ]);
    }

    /**
     * A cork component.
     */
    public function cork(): static
    {
        return $this->state(fn () => [
            'component_type' => 'cork',
            'product_name' => 'Natural Cork #9',
        ]);
    }
}
