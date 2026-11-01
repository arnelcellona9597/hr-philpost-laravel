<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template_id' => ContractTemplate::factory(),
            'employee_id' => Employee::factory(),
            'control_number' => fake()->unique()->numerify('COS-2026-####'),
            'template_name' => 'Contract of Service',
            'employee_name' => fake()->name(),
            'position' => 'Mail Handler',
            'office' => 'Sample Area Office',
            'place_of_service' => 'Sample City',
            'term_start' => '2026-07-01',
            'term_end' => '2026-12-31',
            'daily_wage_centavos' => 45_000,
            'premium_percent' => 15,
            'content' => [],
        ];
    }
}
