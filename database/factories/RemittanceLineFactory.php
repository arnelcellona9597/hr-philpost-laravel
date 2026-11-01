<?php

namespace Database\Factories;

use App\Models\Remittance;
use App\Models\RemittanceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemittanceLine>
 */
class RemittanceLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'remittance_id' => Remittance::factory(),
            'position' => 0,
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->lexify('?'),
            'birth_date' => fake()->dateTimeBetween('-55 years', '-22 years')->format('Y-m-d'),
            'station' => 'Main Office',
            'member_number' => fake()->numerify('##-#######-#'),
            'amount_centavos' => 75_000,
            'employer_share_centavos' => 0,
            'compensation_centavos' => 0,
        ];
    }
}
