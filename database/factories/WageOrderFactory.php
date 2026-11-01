<?php

namespace Database\Factories;

use App\Enums\Region;
use App\Models\WageOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WageOrder>
 */
class WageOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'region' => Region::RegionXi,
            'order_number' => 'WO-'.fake()->unique()->numerify('###'),
            'sector' => 'Non-agriculture',
            'daily_rate_centavos' => 50_000,
            'effective_on' => '2026-01-01',
        ];
    }
}
