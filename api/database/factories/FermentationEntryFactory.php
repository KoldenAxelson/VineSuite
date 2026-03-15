<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FermentationEntry;
use App\Models\FermentationRound;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FermentationEntry>
 */
class FermentationEntryFactory extends Factory
{
    protected $model = FermentationEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fermentation_round_id' => FermentationRound::factory(),
            'entry_date' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'temperature' => $this->faker->randomFloat(1, 55, 90),
            'brix_or_density' => $this->faker->randomFloat(1, -2, 26),
            'measurement_type' => 'brix',
            'free_so2' => null,
            'notes' => null,
            'performed_by' => User::factory(),
        ];
    }

    /**
     * Entry measured in Brix (primary fermentation).
     */
    public function brix(?float $value = null): static
    {
        return $this->state(fn () => [
            'brix_or_density' => $value ?? $this->faker->randomFloat(1, -2, 26),
            'measurement_type' => 'brix',
        ]);
    }

    /**
     * Entry measured in specific gravity.
     */
    public function specificGravity(?float $value = null): static
    {
        return $this->state(fn () => [
            'brix_or_density' => $value ?? $this->faker->randomFloat(4, 0.990, 1.110),
            'measurement_type' => 'specific_gravity',
        ]);
    }

    /**
     * Entry with SO2 reading (common during ML fermentation).
     */
    public function withSo2(): static
    {
        return $this->state(fn () => [
            'free_so2' => $this->faker->randomFloat(1, 5, 35),
        ]);
    }
}
