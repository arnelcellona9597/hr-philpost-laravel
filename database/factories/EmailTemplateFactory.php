<?php

namespace Database\Factories;

use App\Enums\MessageCategory;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->sentence(3),
            'category' => MessageCategory::General,
            'subject' => fake()->sentence(),
            'body' => 'Hi {{firstName}},',
        ];
    }
}
