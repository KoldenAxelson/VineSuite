<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use App\Models\PhysicalCount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhysicalCount>
 */
class PhysicalCountFactory extends Factory
{
    protected $model = PhysicalCount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'status' => 'in_progress',
            'started_by' => $this->faker->uuid(),
            'started_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'completed_by' => $this->faker->uuid(),
            'completed_at' => now(),
        ]);
    }
}
