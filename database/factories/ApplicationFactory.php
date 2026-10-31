<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => fake()->unique()->numerify('APP-2026-###'),
            'applicant_name' => fake()->name(),
            'email' => fake()->unique()->userName().'@example.test',
            'position_applied' => fake()->jobTitle(),
            'date_applied' => fake()->dateTimeBetween('-3 months')->format('Y-m-d'),
            'application_status' => ApplicationStatus::Pending,
        ];
    }

    /**
     * Indicate that the applicant has been hired.
     */
    public function hired(): static
    {
        return $this->state(fn (array $attributes) => [
            'application_status' => ApplicationStatus::Hired,
        ]);
    }
}
