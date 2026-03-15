<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FermentationRound;
use App\Models\Lot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FermentationRound>
 */
class FermentationRoundFactory extends Factory
{
    protected $model = FermentationRound::class;

    /**
     * Common yeast strains used in winemaking.
     *
     * @var array<int, string>
     */
    private const YEAST_STRAINS = [
        'EC-1118', 'D-254', 'BM-45', 'RC-212', 'CY-3079',
        'VIN-13', 'QA-23', 'ICV-D47', 'BDX', 'Montrachet',
    ];

    /**
     * Common ML bacteria strains.
     *
     * @var array<int, string>
     */
    private const ML_BACTERIA = [
        'VP41', 'CH16', 'Alpha', 'Beta', 'MBR 31',
        'Elios 1', 'PN4', 'Lactoenos PreAc',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(FermentationRound::FERMENTATION_TYPES);
        $isPrimary = $type === 'primary';

        return [
            'lot_id' => Lot::factory(),
            'round_number' => $isPrimary ? 1 : 2,
            'fermentation_type' => $type,
            'inoculation_date' => $this->faker->dateTimeBetween('-60 days', '-7 days'),
            'yeast_strain' => $isPrimary ? $this->faker->randomElement(self::YEAST_STRAINS) : null,
            'ml_bacteria' => ! $isPrimary ? $this->faker->randomElement(self::ML_BACTERIA) : null,
            'target_temp' => $isPrimary
                ? $this->faker->randomFloat(1, 55, 90) // 55-65°F whites, 75-90°F reds
                : $this->faker->randomFloat(1, 65, 72), // ML at 65-72°F
            'nutrients_schedule' => $isPrimary ? [
                ['day' => 1, 'addition' => 'Fermaid O', 'amount' => '0.5 g/L'],
                ['day' => 3, 'addition' => 'Fermaid K', 'amount' => '0.5 g/L'],
            ] : null,
            'status' => 'active',
            'completion_date' => null,
            'confirmation_date' => null,
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Primary fermentation round.
     */
    public function primary(): static
    {
        return $this->state(fn () => [
            'fermentation_type' => 'primary',
            'round_number' => 1,
            'yeast_strain' => $this->faker->randomElement(self::YEAST_STRAINS),
            'ml_bacteria' => null,
            'target_temp' => $this->faker->randomFloat(1, 75, 85),
        ]);
    }

    /**
     * Malolactic fermentation round.
     */
    public function malolactic(): static
    {
        return $this->state(fn () => [
            'fermentation_type' => 'malolactic',
            'round_number' => 2,
            'yeast_strain' => null,
            'ml_bacteria' => $this->faker->randomElement(self::ML_BACTERIA),
            'target_temp' => $this->faker->randomFloat(1, 65, 72),
        ]);
    }

    /**
     * Completed round.
     */
    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'completion_date' => $this->faker->dateTimeBetween('-30 days', '-1 day'),
        ]);
    }

    /**
     * Stuck fermentation.
     */
    public function stuck(): static
    {
        return $this->state(fn () => [
            'status' => 'stuck',
        ]);
    }
}
