<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BottlingRun;
use App\Models\Lot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BottlingRun>
 */
class BottlingRunFactory extends Factory
{
    protected $model = BottlingRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $format = $this->faker->randomElement(BottlingRun::BOTTLE_FORMATS);
        $bottlesFilled = $this->faker->numberBetween(200, 2400);
        $bottlesPerCase = 12;
        $casesProduced = intdiv($bottlesFilled, $bottlesPerCase);
        $bottlesPerGallon = BottlingRun::BOTTLES_PER_GALLON[$format] ?? 5.05;
        $volumeBottled = round($bottlesFilled / $bottlesPerGallon, 4);

        return [
            'lot_id' => Lot::factory(),
            'bottle_format' => $format,
            'bottles_filled' => $bottlesFilled,
            'bottles_breakage' => $this->faker->numberBetween(0, 10),
            'waste_percent' => round($this->faker->randomFloat(2, 0.5, 3.0), 2),
            'volume_bottled_gallons' => $volumeBottled,
            'status' => 'planned',
            'sku' => null,
            'cases_produced' => null,
            'bottles_per_case' => $bottlesPerCase,
            'performed_by' => User::factory(),
            'bottled_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'completed_at' => null,
            'notes' => $this->faker->optional(0.3)->sentence(),
        ];
    }

    /**
     * A completed bottling run.
     */
    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $bottlesFilled = $attributes['bottles_filled'] ?? 600;
            $bottlesPerCase = $attributes['bottles_per_case'] ?? 12;

            return [
                'status' => 'completed',
                'cases_produced' => intdiv($bottlesFilled, $bottlesPerCase),
                'sku' => 'CS-2024-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
                'completed_at' => now(),
            ];
        });
    }

    /**
     * An in-progress bottling run.
     */
    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
        ]);
    }
}
