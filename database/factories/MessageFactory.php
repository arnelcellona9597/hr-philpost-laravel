<?php

namespace Database\Factories;

use App\Enums\MessageCategory;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'category' => MessageCategory::General,
        ];
    }
}
