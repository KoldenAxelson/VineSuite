<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    private const VENDORS = [
        'Pacific Coast Bottles',
        'Cork Supply USA',
        'Enartis Vinquiry',
        'Scott Laboratories',
        'Laffort USA',
        'Tonnellerie Sylvain',
        'Amorim Cork America',
        'Berlin Packaging',
        'All American Containers',
        'StaVin Inc.',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $orderDate = $this->faker->dateTimeBetween('-3 months', 'now');

        return [
            'vendor_name' => $this->faker->randomElement(self::VENDORS),
            'order_date' => $orderDate,
            'expected_date' => $this->faker->dateTimeBetween($orderDate, '+2 months'),
            'status' => 'ordered',
            'total_cost' => $this->faker->randomFloat(2, 200, 15000),
            'notes' => $this->faker->optional(0.3)->sentence(),
        ];
    }

    public function received(): static
    {
        return $this->state(fn () => [
            'status' => 'received',
        ]);
    }

    public function partial(): static
    {
        return $this->state(fn () => [
            'status' => 'partial',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => 'ordered',
            'expected_date' => now()->subWeeks(2),
        ]);
    }
}
