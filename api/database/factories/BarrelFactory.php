<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Barrel;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Barrel>
 */
class BarrelFactory extends Factory
{
    protected $model = Barrel::class;

    private const COOPERAGES = [
        'François Frères',
        'Seguin Moreau',
        'Demptos',
        'Tonnellerie Sylvain',
        'Berthomieu',
        'Taransaud',
        'Radoux',
        'World Cooperage',
        'Independent Stave',
        'Canton Cooperage',
    ];

    private const FORESTS = [
        'Allier',
        'Tronçais',
        'Nevers',
        'Vosges',
        'Limousin',
        'Centre-France',
        'Bertranges',
        null,
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vessel_id' => Vessel::factory()->barrel(),
            'cooperage' => $this->faker->randomElement(self::COOPERAGES),
            'toast_level' => $this->faker->randomElement(Barrel::TOAST_LEVELS),
            'oak_type' => $this->faker->randomElement(Barrel::OAK_TYPES),
            'forest_origin' => $this->faker->randomElement(self::FORESTS),
            'volume_gallons' => $this->faker->randomFloat(4, 55.0, 65.0),
            'years_used' => $this->faker->numberBetween(0, 8),
            'qr_code' => 'BRL-'.$this->faker->unique()->numerify('####'),
        ];
    }

    /**
     * New barrel (0 years used).
     */
    public function newBarrel(): static
    {
        return $this->state(fn () => [
            'years_used' => 0,
        ]);
    }

    /**
     * French oak barrel.
     */
    public function frenchOak(): static
    {
        return $this->state(fn () => [
            'oak_type' => 'french',
            'forest_origin' => $this->faker->randomElement(['Allier', 'Tronçais', 'Nevers', 'Vosges']),
        ]);
    }

    /**
     * American oak barrel.
     */
    public function americanOak(): static
    {
        return $this->state(fn () => [
            'oak_type' => 'american',
            'forest_origin' => null,
        ]);
    }

    /**
     * Heavy toast barrel.
     */
    public function heavyToast(): static
    {
        return $this->state(fn () => [
            'toast_level' => 'heavy',
        ]);
    }
}
