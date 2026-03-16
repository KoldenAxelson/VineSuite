<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DryGoodsItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DryGoodsItem>
 */
class DryGoodsItemFactory extends Factory
{
    protected $model = DryGoodsItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(DryGoodsItem::ITEM_TYPES);

        return [
            'name' => $this->nameForType($type),
            'item_type' => $type,
            'unit_of_measure' => fake()->randomElement(DryGoodsItem::UNITS_OF_MEASURE),
            'on_hand' => fake()->randomFloat(2, 0, 5000),
            'reorder_point' => fake()->optional(0.7)->randomFloat(2, 50, 500),
            'cost_per_unit' => fake()->randomFloat(4, 0.01, 5.00),
            'vendor_name' => fake()->optional(0.6)->company(),
            'is_active' => true,
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }

    /**
     * Generate a realistic name for a given item type.
     */
    private function nameForType(string $type): string
    {
        return match ($type) {
            'bottle' => fake()->randomElement(['750ml Burgundy Green', '750ml Bordeaux Clear', '375ml Bordeaux Antique', '1.5L Magnum Dark Green']),
            'cork' => fake()->randomElement(['Natural Cork Grade A', 'Synthetic Cork Premium', '1+1 Technical Cork', 'Micro-Agglomerate Cork']),
            'screw_cap' => fake()->randomElement(['Stelvin Saranex Liner', 'Stelvin Tin Liner', 'Premium Screw Cap Gold']),
            'capsule' => fake()->randomElement(['Tin Capsule Black', 'PVC Capsule Burgundy', 'Polylaminate Capsule Silver']),
            'label_front' => fake()->randomElement(['Front Label — Reserve', 'Front Label — Estate', 'Front Label — Club']),
            'label_back' => fake()->randomElement(['Back Label — Standard', 'Back Label — Reserve', 'Back Label — Library']),
            'label_neck' => fake()->randomElement(['Neck Band — Gold Foil', 'Neck Band — Vintage Year']),
            'carton' => fake()->randomElement(['12-Pack Shipper Box', '6-Pack Gift Box', '2-Pack Mailer Box']),
            'divider' => fake()->randomElement(['12-Cell Divider Insert', '6-Cell Divider Insert']),
            'tissue' => fake()->randomElement(['Acid-Free Tissue Wrap', 'Branded Tissue Paper']),
            default => fake()->words(3, true),
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
            'on_hand' => 10,
            'reorder_point' => 100,
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
}
