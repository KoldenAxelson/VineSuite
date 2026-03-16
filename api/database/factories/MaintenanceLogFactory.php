<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Equipment;
use App\Models\MaintenanceLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceLog>
 */
class MaintenanceLogFactory extends Factory
{
    protected $model = MaintenanceLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(MaintenanceLog::MAINTENANCE_TYPES);

        return [
            'equipment_id' => Equipment::factory(),
            'maintenance_type' => $type,
            'performed_date' => fake()->dateTimeBetween('-6 months', 'now'),
            'description' => $this->descriptionForType($type),
            'findings' => fake()->optional(0.5)->sentence(),
            'cost' => fake()->optional(0.4)->randomFloat(2, 25, 5000),
            'next_due_date' => fake()->optional(0.6)->dateTimeBetween('+1 month', '+1 year'),
            'passed' => in_array($type, ['calibration', 'inspection']) ? fake()->boolean(90) : null,
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }

    /**
     * Generate a realistic description for a maintenance type.
     */
    private function descriptionForType(string $type): string
    {
        return match ($type) {
            'cleaning' => fake()->randomElement(['Standard wash and sanitize', 'Deep clean interior and exterior', 'Caustic wash followed by citric rinse']),
            'cip' => fake()->randomElement(['CIP cycle: caustic 2%, hot water rinse, citric 1%, final rinse', 'Full CIP protocol per SOP-CIP-01', 'Abbreviated CIP: hot water flush and sanitize']),
            'calibration' => fake()->randomElement(['2-point calibration with pH 4.01 and 7.00 buffers', 'Brix calibration with distilled water', 'SO2 calibration against standard solution']),
            'repair' => fake()->randomElement(['Replaced worn gasket on valve', 'Fixed leaking seal on pump shaft', 'Replaced temperature probe', 'Rewired control panel connection']),
            'inspection' => fake()->randomElement(['Visual inspection of welds and fittings', 'Pressure test at 15 PSI for 30 minutes', 'Annual safety inspection per OSHA requirements']),
            'preventive' => fake()->randomElement(['Lubricated bearings and checked belt tension', 'Replaced O-rings and seals per PM schedule', 'Checked electrical connections and tightened']),
            default => fake()->sentence(),
        };
    }

    /**
     * Calibration log entry.
     */
    public function calibration(): static
    {
        return $this->state([
            'maintenance_type' => 'calibration',
            'passed' => true,
        ]);
    }

    /**
     * CIP log entry.
     */
    public function cip(): static
    {
        return $this->state([
            'maintenance_type' => 'cip',
            'passed' => null,
        ]);
    }

    /**
     * Failed calibration.
     */
    public function failedCalibration(): static
    {
        return $this->state([
            'maintenance_type' => 'calibration',
            'passed' => false,
            'findings' => 'Calibration drift exceeded acceptable range. Recalibration required.',
        ]);
    }
}
