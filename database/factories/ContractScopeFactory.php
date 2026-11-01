<?php

namespace Database\Factories;

use App\Models\ContractScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractScope>
 */
class ContractScopeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'position' => 'Mail Handler',
            'items' => ['Sorts mail;', 'Performs other related duties.'],
        ];
    }
}
