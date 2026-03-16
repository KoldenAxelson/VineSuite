<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $locations = [
            'Tasting Room Floor',
            'Back Stock',
            'Offsite Warehouse',
            '3PL Fulfillment Center',
            'Wine Cave',
            'Production Floor',
            'Club Storage',
        ];

        return [
            'name' => $this->faker->randomElement($locations),
            'address' => $this->faker->optional(0.7)->address(),
            'is_active' => true,
        ];
    }

    /**
     * Mark the location as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
