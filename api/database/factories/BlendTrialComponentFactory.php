<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BlendTrialComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlendTrialComponent>
 */
class BlendTrialComponentFactory extends Factory
{
    protected $model = BlendTrialComponent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'percentage' => fake()->randomFloat(2, 5, 80),
            'volume_gallons' => fake()->randomFloat(2, 20, 500),
        ];
    }
}
