<?php

namespace Database\Factories;

use App\Models\ReceivedMail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceivedMail>
 */
class ReceivedMailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'received_on' => fake()->dateTimeBetween('-60 days', 'now')->format('Y-m-d'),
            'tracking_number' => 'RL '.fake()->numerify('### ### ###').' ZZ',
            'post_office' => fake()->randomElement(['Tagum', 'Digos', 'Toril', 'Panabo', 'Midsayap']),
            'description' => 'DTR for wages for the period of '.fake()->monthName().' 16-30',
        ];
    }
}
