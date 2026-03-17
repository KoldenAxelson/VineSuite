<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LabelProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabelProfile>
 */
class LabelProfileFactory extends Factory
{
    protected $model = LabelProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'varietal_claim' => $this->faker->randomElement([
                'Cabernet Sauvignon', 'Syrah', 'Zinfandel', 'Pinot Noir', 'Chardonnay',
            ]),
            'ava_claim' => 'Paso Robles',
            'vintage_claim' => $this->faker->numberBetween(2020, 2025),
            'alcohol_claim' => $this->faker->randomFloat(2, 12.0, 16.0),
            'compliance_status' => 'unchecked',
        ];
    }

    /**
     * State: profile with sub-AVA claim (triggers conjunctive labeling).
     */
    public function withSubAva(string $subAva = 'Adelaida District', string $parentAva = 'Paso Robles'): static
    {
        return $this->state(fn () => [
            'ava_claim' => $parentAva,
            'sub_ava_claim' => $subAva,
        ]);
    }

    /**
     * State: locked profile (post-bottling, immutable).
     */
    public function locked(): static
    {
        return $this->state(fn () => [
            'locked_at' => now(),
        ]);
    }

    /**
     * State: non-vintage wine (no vintage claim).
     */
    public function nonVintage(): static
    {
        return $this->state(fn () => [
            'vintage_claim' => null,
        ]);
    }
}
