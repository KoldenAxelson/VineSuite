<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CaseGoodsSku;
use App\Models\PhysicalCount;
use App\Models\PhysicalCountLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhysicalCountLine>
 */
class PhysicalCountLineFactory extends Factory
{
    protected $model = PhysicalCountLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $system = $this->faker->numberBetween(0, 200);
        $counted = $this->faker->numberBetween(0, 200);

        return [
            'physical_count_id' => PhysicalCount::factory(),
            'sku_id' => CaseGoodsSku::factory(),
            'system_quantity' => $system,
            'counted_quantity' => $counted,
            'variance' => $counted - $system,
        ];
    }
}
