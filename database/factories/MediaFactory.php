<?php

namespace Database\Factories;

use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'filename' => fake()->unique()->word().'.png',
            'path' => 'media/'.fake()->unique()->uuid().'.png',
            'mime_type' => 'image/png',
            'size' => fake()->numberBetween(1_000, 500_000),
        ];
    }

    /**
     * Indicate that the file is a scanned document rather than an image.
     */
    public function document(): static
    {
        return $this->state(fn (array $attributes) => [
            'filename' => fake()->unique()->word().'.pdf',
            'mime_type' => 'application/pdf',
        ]);
    }

    /**
     * Indicate that the file is visible without signing in.
     */
    public function public(): static
    {
        return $this->state(fn (array $attributes) => ['is_public' => true]);
    }
}
