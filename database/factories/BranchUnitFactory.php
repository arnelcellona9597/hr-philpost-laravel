<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchUnit>
 */
class BranchUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->words(2, true).' Section',
        ];
    }

    /**
     * A unit that has not been placed under a branch yet.
     */
    public function unplaced(): static
    {
        return $this->state(fn (): array => ['branch_id' => null]);
    }
}
