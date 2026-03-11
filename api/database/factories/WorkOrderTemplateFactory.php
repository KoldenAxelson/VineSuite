<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WorkOrderTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderTemplate>
 */
class WorkOrderTemplateFactory extends Factory
{
    protected $model = WorkOrderTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $operationType = $this->faker->randomElement(WorkOrderTemplate::DEFAULT_OPERATION_TYPES);

        return [
            'name' => $operationType,
            'operation_type' => $operationType,
            'default_notes' => $this->faker->optional(0.5)->sentence(),
            'is_active' => true,
        ];
    }

    /**
     * Inactive template.
     */
    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
