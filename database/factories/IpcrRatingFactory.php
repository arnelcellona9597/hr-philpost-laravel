<?php

namespace Database\Factories;

use App\Enums\IpcrPeriod;
use App\Models\Employee;
use App\Models\IpcrRating;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpcrRating>
 */
class IpcrRatingFactory extends Factory
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
            'year' => 2026,
            'rating_period' => IpcrPeriod::JanJun,
        ];
    }
}
