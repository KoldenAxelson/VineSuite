<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Addition;
use App\Models\Lot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Addition>
 */
class AdditionFactory extends Factory
{
    protected $model = Addition::class;

    /**
     * Common winemaking products organized by addition type.
     *
     * @var array<string, array<int, array{name: string, rate: float, rate_unit: string, total_unit: string}>>
     */
    private const PRODUCTS = [
        'sulfite' => [
            ['name' => 'Potassium Metabisulfite', 'rate' => 25.0, 'rate_unit' => 'ppm', 'total_unit' => 'g'],
            ['name' => 'Sodium Metabisulfite', 'rate' => 30.0, 'rate_unit' => 'ppm', 'total_unit' => 'g'],
            ['name' => 'Liquid SO2', 'rate' => 20.0, 'rate_unit' => 'ppm', 'total_unit' => 'mL'],
        ],
        'nutrient' => [
            ['name' => 'Fermaid O', 'rate' => 0.4, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
            ['name' => 'Fermaid K', 'rate' => 0.3, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
            ['name' => 'Go-Ferm Protect', 'rate' => 0.3, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
            ['name' => 'DAP (Diammonium Phosphate)', 'rate' => 0.5, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
        ],
        'fining' => [
            ['name' => 'Bentonite', 'rate' => 0.5, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
            ['name' => 'Isinglass', 'rate' => 0.02, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
            ['name' => 'Egg White', 'rate' => 0.1, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
            ['name' => 'PVPP', 'rate' => 0.3, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
        ],
        'acid' => [
            ['name' => 'Tartaric Acid', 'rate' => 1.0, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
            ['name' => 'Citric Acid', 'rate' => 0.3, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
            ['name' => 'Malic Acid', 'rate' => 0.5, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
        ],
        'enzyme' => [
            ['name' => 'Lallzyme EX-V', 'rate' => 0.03, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
            ['name' => 'Pectinex Ultra SP-L', 'rate' => 0.02, 'rate_unit' => 'mL/L', 'total_unit' => 'mL'],
        ],
        'tannin' => [
            ['name' => 'FT Rouge', 'rate' => 0.2, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
            ['name' => 'Tannin Galalcool', 'rate' => 0.1, 'rate_unit' => 'g/L', 'total_unit' => 'g'],
        ],
    ];

    private const REASONS = [
        'Pre-fermentation sulfite addition',
        'Yeast rehydration nutrient',
        'Staggered nutrient addition — 1/3 sugar depletion',
        'Staggered nutrient addition — 2/3 sugar depletion',
        'Post-fermentation stabilization',
        'Cold stabilization fining',
        'Acid adjustment before fermentation',
        'Pre-bottling SO2 adjustment',
        'Clarity improvement before bottling',
        'Tannin addition for structure',
    ];

    public function definition(): array
    {
        $type = $this->faker->randomElement(array_keys(self::PRODUCTS));
        $product = $this->faker->randomElement(self::PRODUCTS[$type]);

        // Vary the rate slightly from the default
        $rateVariation = $product['rate'] * $this->faker->randomFloat(2, 0.8, 1.2);

        return [
            'lot_id' => Lot::factory(),
            'vessel_id' => null,
            'addition_type' => $type,
            'product_name' => $product['name'],
            'rate' => round($rateVariation, 4),
            'rate_unit' => $product['rate_unit'],
            'total_amount' => round($rateVariation * $this->faker->randomFloat(1, 50, 500), 4),
            'total_unit' => $product['total_unit'],
            'reason' => $this->faker->randomElement(self::REASONS),
            'performed_by' => User::factory(),
            'performed_at' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'inventory_item_id' => null,
        ];
    }

    /**
     * SO2/sulfite addition.
     */
    public function sulfite(): static
    {
        $product = $this->faker->randomElement(self::PRODUCTS['sulfite']);

        return $this->state(fn () => [
            'addition_type' => 'sulfite',
            'product_name' => $product['name'],
            'rate' => $product['rate'],
            'rate_unit' => $product['rate_unit'],
            'total_unit' => $product['total_unit'],
        ]);
    }

    /**
     * Nutrient addition.
     */
    public function nutrient(): static
    {
        $product = $this->faker->randomElement(self::PRODUCTS['nutrient']);

        return $this->state(fn () => [
            'addition_type' => 'nutrient',
            'product_name' => $product['name'],
            'rate' => $product['rate'],
            'rate_unit' => $product['rate_unit'],
            'total_unit' => $product['total_unit'],
        ]);
    }

    /**
     * Fining agent addition.
     */
    public function fining(): static
    {
        $product = $this->faker->randomElement(self::PRODUCTS['fining']);

        return $this->state(fn () => [
            'addition_type' => 'fining',
            'product_name' => $product['name'],
            'rate' => $product['rate'],
            'rate_unit' => $product['rate_unit'],
            'total_unit' => $product['total_unit'],
        ]);
    }
}
