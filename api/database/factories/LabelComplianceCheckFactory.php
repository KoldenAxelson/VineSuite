<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LabelComplianceCheck;
use App\Models\LabelProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabelComplianceCheck>
 */
class LabelComplianceCheckFactory extends Factory
{
    protected $model = LabelComplianceCheck::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label_profile_id' => LabelProfile::factory(),
            'rule_type' => $this->faker->randomElement(LabelComplianceCheck::RULE_TYPES),
            'threshold' => 75.00,
            'actual_percentage' => $this->faker->randomFloat(4, 50, 100),
            'passes' => true,
            'details' => null,
            'checked_at' => now(),
        ];
    }
}
