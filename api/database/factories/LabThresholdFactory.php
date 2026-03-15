<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LabThreshold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabThreshold>
 */
class LabThresholdFactory extends Factory
{
    protected $model = LabThreshold::class;

    public function definition(): array
    {
        return [
            'test_type' => 'pH',
            'variety' => null,
            'min_value' => 3.0,
            'max_value' => 3.8,
            'alert_level' => 'warning',
        ];
    }

    /**
     * VA threshold for table wine (legal limit).
     */
    public function vaCritical(): static
    {
        return $this->state(fn () => [
            'test_type' => 'VA',
            'variety' => null,
            'min_value' => null,
            'max_value' => 0.12,
            'alert_level' => 'critical',
        ]);
    }

    /**
     * VA warning threshold (approaching limit).
     */
    public function vaWarning(): static
    {
        return $this->state(fn () => [
            'test_type' => 'VA',
            'variety' => null,
            'min_value' => null,
            'max_value' => 0.10,
            'alert_level' => 'warning',
        ]);
    }

    /**
     * Set as critical level.
     */
    public function critical(): static
    {
        return $this->state(fn () => [
            'alert_level' => 'critical',
        ]);
    }

    /**
     * Set for a specific variety.
     */
    public function forVariety(string $variety): static
    {
        return $this->state(fn () => [
            'variety' => $variety,
        ]);
    }
}
