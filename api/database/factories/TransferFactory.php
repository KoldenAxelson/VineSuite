<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lot;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transfer>
 */
class TransferFactory extends Factory
{
    protected $model = Transfer::class;

    public function definition(): array
    {
        return [
            'lot_id' => Lot::factory(),
            'from_vessel_id' => Vessel::factory(),
            'to_vessel_id' => Vessel::factory(),
            'volume_gallons' => $this->faker->randomFloat(4, 10, 500),
            'transfer_type' => $this->faker->randomElement(Transfer::TRANSFER_TYPES),
            'variance_gallons' => $this->faker->randomFloat(4, 0, 2),
            'performed_by' => User::factory(),
            'performed_at' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'notes' => $this->faker->optional(0.3)->sentence(),
        ];
    }

    /**
     * Gravity transfer (no pump, minimal loss).
     */
    public function gravity(): static
    {
        return $this->state(fn () => [
            'transfer_type' => 'gravity',
            'variance_gallons' => $this->faker->randomFloat(4, 0, 0.5),
        ]);
    }

    /**
     * Pump transfer.
     */
    public function pump(): static
    {
        return $this->state(fn () => [
            'transfer_type' => 'pump',
            'variance_gallons' => $this->faker->randomFloat(4, 0.1, 1.5),
        ]);
    }
}
