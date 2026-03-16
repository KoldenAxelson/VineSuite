<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    protected $model = Equipment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(Equipment::EQUIPMENT_TYPES);

        return [
            'name' => $this->nameForType($type),
            'equipment_type' => $type,
            'serial_number' => fake()->optional(0.7)->bothify('??-####-####'),
            'manufacturer' => fake()->optional(0.6)->company(),
            'model_number' => fake()->optional(0.5)->bothify('MOD-####'),
            'purchase_date' => fake()->optional(0.6)->dateTimeBetween('-5 years', '-6 months'),
            'purchase_value' => fake()->optional(0.5)->randomFloat(2, 500, 150000),
            'location' => fake()->optional(0.6)->randomElement(['Crush Pad', 'Barrel Room', 'Bottling Hall', 'Lab', 'Warehouse', 'Tank Farm']),
            'status' => 'operational',
            'next_maintenance_due' => fake()->optional(0.5)->dateTimeBetween('+1 week', '+6 months'),
            'is_active' => true,
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }

    /**
     * Generate a realistic name for a given equipment type.
     */
    private function nameForType(string $type): string
    {
        return match ($type) {
            'tank' => fake()->randomElement(['SS Fermentation Tank #1', 'Variable Capacity Tank 500L', 'Jacketed Red Tank T-12', 'Open-Top Fermenter OT-3']),
            'pump' => fake()->randomElement(['Peristaltic Pump P-1', 'Centrifugal Transfer Pump', 'Must Pump MP-2', 'Diaphragm Pump DP-1']),
            'press' => fake()->randomElement(['Bladder Press 2-Ton', 'Basket Press Heritage', 'Pneumatic Press PP-1']),
            'filter' => fake()->randomElement(['Crossflow Filter CF-500', 'Plate & Frame Filter PF-1', 'Cartridge Filter Housing', 'DE Filter']),
            'bottling_line' => fake()->randomElement(['Mobile Bottling Line', 'In-House Bottling System BL-1', 'Labeling Machine LM-2']),
            'lab_instrument' => fake()->randomElement(['pH Meter Hanna HI2020', 'Refractometer Brix', 'Spectrophotometer UV-Vis', 'SO2 Aspirator', 'Ebulliometer']),
            'forklift' => fake()->randomElement(['Electric Forklift EF-1', 'Propane Forklift FL-2', 'Pallet Jack PJ-1']),
            'other' => fake()->randomElement(['Ozone Generator OZ-1', 'Nitrogen Generator', 'Barrel Washer BW-1', 'Steam Generator']),
            default => fake()->words(3, true),
        };
    }

    /**
     * Equipment in maintenance status.
     */
    public function inMaintenance(): static
    {
        return $this->state(['status' => 'maintenance']);
    }

    /**
     * Retired equipment.
     */
    public function retired(): static
    {
        return $this->state([
            'status' => 'retired',
            'is_active' => false,
        ]);
    }

    /**
     * Equipment with overdue maintenance.
     */
    public function maintenanceOverdue(): static
    {
        return $this->state([
            'next_maintenance_due' => now()->subDays(15),
        ]);
    }

    /**
     * Inactive equipment.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
