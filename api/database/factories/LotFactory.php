<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lot>
 */
class LotFactory extends Factory
{
    protected $model = Lot::class;

    /**
     * Realistic lot data for testing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $varieties = [
            'Cabernet Sauvignon', 'Pinot Noir', 'Chardonnay', 'Merlot',
            'Zinfandel', 'Syrah', 'Sauvignon Blanc', 'Petite Sirah',
            'Grenache', 'Mourvèdre', 'Viognier', 'Tempranillo',
        ];

        $avas = [
            'Paso Robles', 'Adelaida District', 'Willow Creek District',
            'Templeton Gap District', 'El Pomar District', 'San Miguel District',
            'Creston District', 'Estrella District', 'Geneseo District',
            'Highlands District', 'San Juan Creek',
        ];

        $variety = $this->faker->randomElement($varieties);
        $vintage = $this->faker->numberBetween(2020, 2025);
        $sourceType = $this->faker->randomElement(['estate', 'purchased']);

        return [
            'name' => "{$vintage} {$variety} Lot ".$this->faker->unique()->numberBetween(1, 999),
            'variety' => $variety,
            'vintage' => $vintage,
            'source_type' => $sourceType,
            'source_details' => $sourceType === 'estate'
                ? [
                    'vineyard' => $this->faker->randomElement(['Estate', 'Home Ranch', 'Hillside']),
                    'block' => $this->faker->randomElement(['A', 'B', 'C', 'D', 'E']),
                ]
                : [
                    'grower' => $this->faker->company(),
                    'vineyard' => $this->faker->randomElement(['Bien Nacido', 'Paso Creek', 'Sierra Madre']),
                ],
            'source_ava' => $this->faker->randomElement($avas),
            'volume_gallons' => $this->faker->randomFloat(4, 50, 5000),
            'status' => $this->faker->randomElement(Lot::STATUSES),
        ];
    }

    /**
     * State: new lot with in_progress status.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
        ]);
    }

    /**
     * State: aging lot.
     */
    public function aging(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'aging',
        ]);
    }

    /**
     * State: bottled lot.
     */
    public function bottled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'bottled',
            'volume_gallons' => 0,
        ]);
    }
}
