<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FilterLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FilterLog>
 */
class FilterLogFactory extends Factory
{
    protected $model = FilterLog::class;

    /**
     * Common filter media by type.
     *
     * @var array<string, list<string>>
     */
    private const MEDIA_BY_TYPE = [
        'pad' => ['Coarse pad (K100)', 'Polish pad (K300)', 'Sterile pad (EK)'],
        'crossflow' => ['0.2µm ceramic membrane', '0.45µm PES membrane', '100kDa UF membrane'],
        'cartridge' => ['0.45µm cartridge', '0.65µm cartridge', '1.0µm nominal cartridge'],
        'plate_and_frame' => ['DE pre-coat', 'Cellulose sheets', 'Depth filter sheets'],
        'de' => ['Medium grade DE', 'Fine grade DE', 'Perlite blend'],
        'lenticular' => ['Coarse lenticular module', 'Fine lenticular module'],
    ];

    /**
     * Common fining agents with typical rates.
     *
     * @var array<string, array{rate: float, unit: string}>
     */
    private const FINING_AGENTS = [
        'Bentonite' => ['rate' => 0.5, 'unit' => 'g/L'],
        'Gelatin' => ['rate' => 0.1, 'unit' => 'g/L'],
        'Egg White' => ['rate' => 0.05, 'unit' => 'g/L'],
        'PVPP' => ['rate' => 0.25, 'unit' => 'g/L'],
        'Isinglass' => ['rate' => 0.02, 'unit' => 'g/L'],
        'Casein' => ['rate' => 0.3, 'unit' => 'g/L'],
        'Activated Carbon' => ['rate' => 0.1, 'unit' => 'g/L'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filterType = fake()->randomElement(FilterLog::FILTER_TYPES);
        $media = self::MEDIA_BY_TYPE[$filterType];

        return [
            'filter_type' => $filterType,
            'filter_media' => fake()->randomElement($media),
            'flow_rate_lph' => fake()->randomFloat(2, 50, 2000),
            'volume_processed_gallons' => fake()->randomFloat(2, 50, 1000),
            'performed_at' => now(),
            'notes' => fake()->optional(0.5)->sentence(),
        ];
    }

    /**
     * Include fining agent details.
     */
    public function withFining(): static
    {
        return $this->state(function () {
            $agent = fake()->randomElement(array_keys(self::FINING_AGENTS));
            $config = self::FINING_AGENTS[$agent];

            return [
                'fining_agent' => $agent,
                'fining_rate' => $config['rate'] * fake()->randomFloat(1, 0.8, 1.2),
                'fining_rate_unit' => $config['unit'],
                'bench_trial_notes' => 'Bench trial at 3 rates: low, medium, high. Medium rate selected.',
                'treatment_notes' => 'Applied to full lot after 48-hour contact time.',
            ];
        });
    }

    /**
     * Crossflow filter.
     */
    public function crossflow(): static
    {
        return $this->state(fn () => [
            'filter_type' => 'crossflow',
            'filter_media' => '0.2µm ceramic membrane',
            'flow_rate_lph' => fake()->randomFloat(2, 500, 1500),
        ]);
    }

    /**
     * Pad filter.
     */
    public function pad(): static
    {
        return $this->state(fn () => [
            'filter_type' => 'pad',
            'filter_media' => 'Polish pad (K300)',
        ]);
    }
}
