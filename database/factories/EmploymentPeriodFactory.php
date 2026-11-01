<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmploymentPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmploymentPeriod>
 */
class EmploymentPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'hired_on' => fake()->dateTimeBetween('-10 years', '-2 years')->format('Y-m-d'),
        ];
    }

    /**
     * A period that ended, so the person can be hired again.
     */
    public function separated(string $on, string $type = 'Resigned'): static
    {
        return $this->state(['separated_on' => $on, 'separation_type' => $type]);
    }
}
