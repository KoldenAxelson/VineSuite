<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RawMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawMaterial>
 */
class RawMaterialFactory extends Factory
{
    protected $model = RawMaterial::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category = fake()->randomElement(RawMaterial::CATEGORIES);

        return [
            'name' => $this->nameForCategory($category),
            'category' => $category,
            'unit_of_measure' => $this->unitForCategory($category),
            'on_hand' => fake()->randomFloat(2, 0, 500),
            'reorder_point' => fake()->optional(0.7)->randomFloat(2, 5, 50),
            'cost_per_unit' => fake()->randomFloat(4, 0.50, 200.00),
            'expiration_date' => fake()->optional(0.6)->dateTimeBetween('+1 month', '+2 years'),
            'vendor_name' => fake()->optional(0.6)->company(),
            'is_active' => true,
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }

    /**
     * Generate a realistic name for a given category.
     */
    private function nameForCategory(string $category): string
    {
        return match ($category) {
            'additive' => fake()->randomElement(['Potassium Metabisulfite', 'Copper Sulfate', 'Ascorbic Acid', 'Potassium Sorbate']),
            'yeast' => fake()->randomElement(['EC-1118', 'RC-212', 'D254', 'BM45', 'CY3079', 'QA23']),
            'nutrient' => fake()->randomElement(['Go-Ferm Protect Evolution', 'Fermaid O', 'Fermaid K', 'DAP (Diammonium Phosphate)', 'Opti-Red']),
            'fining_agent' => fake()->randomElement(['Bentonite', 'Isinglass', 'Egg White Powder', 'PVPP', 'Gelatin', 'Sparkolloid']),
            'acid' => fake()->randomElement(['Tartaric Acid', 'Citric Acid', 'Malic Acid', 'Lactic Acid']),
            'enzyme' => fake()->randomElement(['Lallzyme EX-V', 'Lafase HE Grand Cru', 'Scottzyme Color Pro', 'Pectinex Ultra SP-L']),
            'oak_alternative' => fake()->randomElement(['French Oak Chips Medium Toast', 'American Oak Spirals Heavy Toast', 'Hungarian Oak Cubes', 'French Oak Staves Medium Plus']),
            default => fake()->words(3, true),
        };
    }

    /**
     * Suggest a sensible unit of measure for a given category.
     */
    private function unitForCategory(string $category): string
    {
        return match ($category) {
            'yeast' => fake()->randomElement(['g', 'each']),
            'oak_alternative' => fake()->randomElement(['kg', 'each']),
            'enzyme' => 'L',
            default => fake()->randomElement(['g', 'kg']),
        };
    }

    /**
     * Mark item as inactive.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Item with low stock (below reorder point).
     */
    public function lowStock(): static
    {
        return $this->state([
            'on_hand' => 2,
            'reorder_point' => 20,
        ]);
    }

    /**
     * Item with no reorder point set.
     */
    public function noReorderPoint(): static
    {
        return $this->state([
            'reorder_point' => null,
        ]);
    }

    /**
     * Item that has already expired.
     */
    public function expired(): static
    {
        return $this->state([
            'expiration_date' => now()->subDays(30),
        ]);
    }

    /**
     * Item expiring within the next 30 days.
     */
    public function expiringSoon(): static
    {
        return $this->state([
            'expiration_date' => now()->addDays(15),
        ]);
    }
}
