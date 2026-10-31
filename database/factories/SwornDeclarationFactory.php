<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\SwornDeclaration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwornDeclaration>
 */
class SwornDeclarationFactory extends Factory
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
        ];
    }
}
