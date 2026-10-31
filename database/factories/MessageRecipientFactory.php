<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageRecipient>
 */
class MessageRecipientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->userName().'@example.test',
            'subject' => fake()->sentence(),
            'body' => fake()->paragraph(),
        ];
    }
}
