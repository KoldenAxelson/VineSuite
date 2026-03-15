<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lot;
use App\Models\SensoryNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SensoryNote>
 */
class SensoryNoteFactory extends Factory
{
    protected $model = SensoryNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lot_id' => Lot::factory(),
            'taster_id' => User::factory(),
            'date' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'rating' => $this->faker->randomFloat(1, 2.5, 5.0),
            'rating_scale' => 'five_point',
            'nose_notes' => $this->faker->randomElement([
                'Dark cherry, cassis, and subtle oak spice',
                'Bright citrus, green apple, and floral aromatics',
                'Ripe blackberry, vanilla, and earthy undertones',
                'Tropical fruit, passion fruit, and mineral notes',
                'Red cherry, raspberry, and light herbal notes',
                'Pear, honeysuckle, and toasted almond',
                'Plum, tobacco leaf, and graphite',
            ]),
            'palate_notes' => $this->faker->randomElement([
                'Medium body, firm tannins, good acidity, long finish',
                'Light body, crisp acidity, clean mineral finish',
                'Full body, velvety tannins, balanced oak, persistent finish',
                'Medium body, bright fruit, soft tannins, refreshing finish',
                'Rich texture, round mouthfeel, integrated tannins',
                'Lean and angular, sharp acidity, needs time',
                'Plush and generous, ripe fruit, smooth finish',
            ]),
            'overall_notes' => $this->faker->randomElement([
                'Developing nicely, ready for barrel aging',
                'Shows great potential, needs 6 more months',
                'Clean and varietal-correct, good commercial quality',
                'Outstanding complexity, reserve quality candidate',
                'Needs further evaluation after racking',
                'Approaching peak, consider bottling timeline',
                null,
            ]),
        ];
    }

    /**
     * Use the hundred-point rating scale.
     */
    public function hundredPoint(): static
    {
        return $this->state(fn () => [
            'rating' => $this->faker->randomFloat(0, 78, 98),
            'rating_scale' => 'hundred_point',
        ]);
    }

    /**
     * Use the five-point rating scale.
     */
    public function fivePoint(?float $rating = null): static
    {
        return $this->state(fn () => [
            'rating' => $rating ?? $this->faker->randomFloat(1, 2.5, 5.0),
            'rating_scale' => 'five_point',
        ]);
    }
}
