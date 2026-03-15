<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BlendTrial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlendTrial>
 */
class BlendTrialFactory extends Factory
{
    protected $model = BlendTrial::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->year().' '.fake()->randomElement(['Reserve', 'Estate', 'Heritage', 'Prestige']).' Blend Trial #'.fake()->numberBetween(1, 10),
            'status' => 'draft',
            'version' => 1,
            'notes' => fake()->optional(0.5)->sentence(),
        ];
    }

    /**
     * Finalized blend trial.
     */
    public function finalized(): static
    {
        return $this->state(fn () => [
            'status' => 'finalized',
            'finalized_at' => now(),
        ]);
    }

    /**
     * Archived blend trial.
     */
    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => 'archived',
        ]);
    }
}
