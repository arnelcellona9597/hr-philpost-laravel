<?php

namespace Database\Factories;

use App\Enums\Region;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' Post Office',
            'code' => fake()->unique()->bothify('BR-####'),
            'address' => fake()->address(),
            'region' => fake()->randomElement(Region::cases()),
        ];
    }
}
