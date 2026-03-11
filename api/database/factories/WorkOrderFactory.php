<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WorkOrder;
use App\Models\WorkOrderTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    protected $model = WorkOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation_type' => $this->faker->randomElement(WorkOrderTemplate::DEFAULT_OPERATION_TYPES),
            'due_date' => $this->faker->dateTimeBetween('now', '+2 weeks')->format('Y-m-d'),
            'status' => 'pending',
            'priority' => $this->faker->randomElement(WorkOrder::PRIORITIES),
            'notes' => $this->faker->optional(0.4)->sentence(),
        ];
    }

    /**
     * In-progress work order.
     */
    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
        ]);
    }

    /**
     * Completed work order.
     */
    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'completed_at' => now(),
            'completion_notes' => $this->faker->optional(0.6)->sentence(),
        ]);
    }

    /**
     * High priority.
     */
    public function highPriority(): static
    {
        return $this->state(fn () => [
            'priority' => 'high',
        ]);
    }
}
