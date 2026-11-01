<?php

namespace Database\Factories;

use App\Enums\PositionChangeType;
use App\Models\Employee;
use App\Models\PositionChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PositionChange>
 */
class PositionChangeFactory extends Factory
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
            'effective_on' => '2025-01-01',
            'change_type' => PositionChangeType::Promotion,
            'from_position' => 'Clerk I',
            'to_position' => 'Clerk II',
        ];
    }
}
