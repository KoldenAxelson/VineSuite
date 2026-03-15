<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LabAnalysis;
use App\Models\Lot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabAnalysis>
 */
class LabAnalysisFactory extends Factory
{
    protected $model = LabAnalysis::class;

    /**
     * Realistic value ranges per test type.
     *
     * @var array<string, array{min: float, max: float, unit: string}>
     */
    private const VALUE_RANGES = [
        'pH' => ['min' => 2.9, 'max' => 4.2, 'unit' => 'pH'],
        'TA' => ['min' => 4.0, 'max' => 10.0, 'unit' => 'g/L'],
        'VA' => ['min' => 0.02, 'max' => 0.10, 'unit' => 'g/100mL'],
        'free_SO2' => ['min' => 10.0, 'max' => 50.0, 'unit' => 'mg/L'],
        'total_SO2' => ['min' => 30.0, 'max' => 150.0, 'unit' => 'mg/L'],
        'residual_sugar' => ['min' => 0.0, 'max' => 15.0, 'unit' => 'g/L'],
        'alcohol' => ['min' => 11.0, 'max' => 16.5, 'unit' => '%v/v'],
        'malic_acid' => ['min' => 0.0, 'max' => 5.0, 'unit' => 'g/L'],
        'glucose_fructose' => ['min' => 0.0, 'max' => 10.0, 'unit' => 'g/L'],
        'turbidity' => ['min' => 0.5, 'max' => 50.0, 'unit' => 'NTU'],
        'color' => ['min' => 0.5, 'max' => 15.0, 'unit' => 'AU'],
    ];

    private const METHODS = [
        'pH' => ['pH meter', 'Titration'],
        'TA' => ['NaOH titration to pH 8.2', 'Auto-titrator'],
        'VA' => ['Cash still', 'Enzymatic'],
        'free_SO2' => ['Aeration-oxidation', 'Ripper titration'],
        'total_SO2' => ['Aeration-oxidation', 'Ripper titration'],
        'residual_sugar' => ['Clinitest', 'Enzymatic', 'Rebelein'],
        'alcohol' => ['Ebulliometer', 'Hydrometer', 'NIR'],
        'malic_acid' => ['Enzymatic', 'Paper chromatography'],
        'glucose_fructose' => ['Enzymatic'],
        'turbidity' => ['Nephelometer'],
        'color' => ['Spectrophotometer 420nm'],
    ];

    public function definition(): array
    {
        $testType = $this->faker->randomElement(array_keys(self::VALUE_RANGES));
        $range = self::VALUE_RANGES[$testType];

        return [
            'lot_id' => Lot::factory(),
            'test_date' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'test_type' => $testType,
            'value' => round($this->faker->randomFloat(6, $range['min'], $range['max']), 6),
            'unit' => $range['unit'],
            'method' => $this->faker->randomElement(self::METHODS[$testType]),
            'analyst' => $this->faker->optional(0.7)->name(),
            'notes' => $this->faker->optional(0.3)->sentence(),
            'source' => 'manual',
            'performed_by' => User::factory(),
        ];
    }

    /**
     * Create a pH analysis.
     */
    public function ph(): static
    {
        return $this->state(fn () => [
            'test_type' => 'pH',
            'value' => round($this->faker->randomFloat(2, 3.0, 4.0), 6),
            'unit' => 'pH',
            'method' => $this->faker->randomElement(self::METHODS['pH']),
        ]);
    }

    /**
     * Create a VA analysis.
     */
    public function va(): static
    {
        return $this->state(fn () => [
            'test_type' => 'VA',
            'value' => round($this->faker->randomFloat(3, 0.02, 0.10), 6),
            'unit' => 'g/100mL',
            'method' => $this->faker->randomElement(self::METHODS['VA']),
        ]);
    }

    /**
     * Create a VA analysis near the legal limit.
     */
    public function vaNearLimit(): static
    {
        return $this->state(fn () => [
            'test_type' => 'VA',
            'value' => round($this->faker->randomFloat(3, 0.10, 0.13), 6),
            'unit' => 'g/100mL',
            'method' => 'Cash still',
        ]);
    }

    /**
     * Create a TA analysis.
     */
    public function ta(): static
    {
        return $this->state(fn () => [
            'test_type' => 'TA',
            'value' => round($this->faker->randomFloat(2, 5.0, 8.5), 6),
            'unit' => 'g/L',
            'method' => $this->faker->randomElement(self::METHODS['TA']),
        ]);
    }

    /**
     * Create a free SO2 analysis.
     */
    public function freeSo2(): static
    {
        return $this->state(fn () => [
            'test_type' => 'free_SO2',
            'value' => round($this->faker->randomFloat(1, 15.0, 45.0), 6),
            'unit' => 'mg/L',
            'method' => $this->faker->randomElement(self::METHODS['free_SO2']),
        ]);
    }

    /**
     * Mark as imported from ETS Labs.
     */
    public function fromEtsLabs(): static
    {
        return $this->state(fn () => [
            'source' => 'ets_labs',
            'analyst' => 'ETS Laboratories',
        ]);
    }
}
