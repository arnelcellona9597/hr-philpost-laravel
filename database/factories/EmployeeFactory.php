<?php

namespace Database\Factories;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => fake()->unique()->numerify('EMP-#####'),
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'gender' => fake()->randomElement(Gender::cases()),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-20 years')->format('Y-m-d'),
            'email' => fake()->unique()->userName().'@example.test',
            'classification' => fake()->randomElement(Classification::cases()),
            'employment_status' => EmploymentStatus::Active,
            'position' => fake()->jobTitle(),
            'date_hired' => fake()->dateTimeBetween('-10 years', '-1 month')->format('Y-m-d'),
        ];
    }
}
