<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vessel>
 */
class VesselFactory extends Factory
{
    protected $model = Vessel::class;

    /**
     * Vessel naming conventions by type.
     */
    private const TYPE_PREFIXES = [
        'tank' => 'T',
        'barrel' => 'B',
        'flexitank' => 'FT',
        'tote' => 'TO',
        'demijohn' => 'DJ',
        'concrete_egg' => 'CE',
        'amphora' => 'AM',
    ];

    /**
     * Typical capacity ranges by type (in gallons).
     */
    private const CAPACITY_RANGES = [
        'tank' => [100, 5000],
        'barrel' => [55, 65],
        'flexitank' => [200, 400],
        'tote' => [250, 350],
        'demijohn' => [5, 15],
        'concrete_egg' => [150, 500],
        'amphora' => [50, 200],
    ];

    private const MATERIALS = [
        'tank' => ['stainless steel', 'stainless steel (variable capacity)', 'stainless steel (jacketed)'],
        'barrel' => ['French oak', 'American oak', 'Hungarian oak'],
        'flexitank' => ['food-grade polyethylene'],
        'tote' => ['food-grade HDPE', 'stainless steel'],
        'demijohn' => ['glass'],
        'concrete_egg' => ['concrete', 'concrete (wax-lined)'],
        'amphora' => ['terracotta', 'clay'],
    ];

    private const LOCATIONS = [
        'Barrel Room A',
        'Barrel Room B',
        'Tank Hall',
        'Tank Hall - North',
        'Tank Hall - South',
        'Crush Pad',
        'Cave',
        'Outdoor Pad',
        'Warehouse',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(Vessel::TYPES);
        $prefix = self::TYPE_PREFIXES[$type];
        $number = $this->faker->numberBetween(1, 200);
        $capacityRange = self::CAPACITY_RANGES[$type];
        $materials = self::MATERIALS[$type];

        return [
            'name' => "{$prefix}-".str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'type' => $type,
            'capacity_gallons' => $this->faker->randomFloat(2, $capacityRange[0], $capacityRange[1]),
            'material' => $this->faker->randomElement($materials),
            'location' => $this->faker->randomElement(self::LOCATIONS),
            'status' => 'empty',
            'purchase_date' => $this->faker->dateTimeBetween('-5 years', 'now'),
            'notes' => $this->faker->optional(0.3)->sentence(),
        ];
    }

    /**
     * Vessel is currently in use.
     */
    public function inUse(): static
    {
        return $this->state(fn () => ['status' => 'in_use']);
    }

    /**
     * Vessel is being cleaned.
     */
    public function cleaning(): static
    {
        return $this->state(fn () => ['status' => 'cleaning']);
    }

    /**
     * Vessel is out of service.
     */
    public function outOfService(): static
    {
        return $this->state(fn () => ['status' => 'out_of_service']);
    }

    /**
     * Create a tank specifically.
     */
    public function tank(): static
    {
        return $this->state(fn () => [
            'type' => 'tank',
            'material' => $this->faker->randomElement(self::MATERIALS['tank']),
            'capacity_gallons' => $this->faker->randomFloat(2, 100, 5000),
        ]);
    }

    /**
     * Create a barrel specifically.
     */
    public function barrel(): static
    {
        return $this->state(fn () => [
            'type' => 'barrel',
            'material' => $this->faker->randomElement(self::MATERIALS['barrel']),
            'capacity_gallons' => $this->faker->randomFloat(2, 55, 65),
        ]);
    }
}
