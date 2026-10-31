<?php

namespace Database\Factories;

use App\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollPeriod>
 */
class PayrollPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'October 2026 - 1st half',
            'code' => fake()->unique()->bothify('2026-10-??##'),
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-15',
            'pay_date' => '2026-10-20',
        ];
    }
}
