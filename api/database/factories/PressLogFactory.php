<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PressLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PressLog>
 */
class PressLogFactory extends Factory
{
    protected $model = PressLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fruitWeight = fake()->randomFloat(2, 200, 2000); // kg
        $totalJuice = fake()->randomFloat(2, 30, 400); // gallons
        // Typical yield: 150-180 gallons per ton (907 kg), so ~16-20% by weight-to-gallon ratio
        $yieldPercent = ($totalJuice / $fruitWeight) * 100;

        $fractions = [
            [
                'fraction' => 'free_run',
                'volume_gallons' => round($totalJuice * 0.65, 4),
                'child_lot_id' => null,
            ],
            [
                'fraction' => 'light_press',
                'volume_gallons' => round($totalJuice * 0.25, 4),
                'child_lot_id' => null,
            ],
            [
                'fraction' => 'heavy_press',
                'volume_gallons' => round($totalJuice * 0.10, 4),
                'child_lot_id' => null,
            ],
        ];

        return [
            'press_type' => fake()->randomElement(PressLog::PRESS_TYPES),
            'fruit_weight_kg' => $fruitWeight,
            'total_juice_gallons' => $totalJuice,
            'fractions' => $fractions,
            'yield_percent' => round($yieldPercent, 4),
            'pomace_weight_kg' => fake()->randomFloat(2, 50, 500),
            'pomace_destination' => fake()->randomElement(PressLog::POMACE_DESTINATIONS),
            'performed_at' => now(),
            'notes' => fake()->optional(0.5)->sentence(),
        ];
    }

    /**
     * Basket press — traditional, lower yield.
     */
    public function basket(): static
    {
        return $this->state(fn () => [
            'press_type' => 'basket',
        ]);
    }

    /**
     * Pneumatic press — modern, higher yield.
     */
    public function pneumatic(): static
    {
        return $this->state(fn () => [
            'press_type' => 'pneumatic',
        ]);
    }

    /**
     * Free run only — no press fractions (whole cluster or gravity).
     */
    public function freeRunOnly(): static
    {
        return $this->state(function (array $attributes) {
            $totalJuice = (float) $attributes['total_juice_gallons'];

            return [
                'fractions' => [
                    [
                        'fraction' => 'free_run',
                        'volume_gallons' => $totalJuice,
                        'child_lot_id' => null,
                    ],
                ],
            ];
        });
    }
}
