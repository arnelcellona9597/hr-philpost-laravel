<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_name' => 'System',
            'action' => 'update',
            'collection_slug' => 'employees',
            'doc_id' => (string) fake()->randomNumber(),
            'doc_label' => fake()->name(),
        ];
    }

    /**
     * Indicate that the entry records a change to an HR account.
     */
    public function forUsers(): static
    {
        return $this->state(fn (array $attributes) => ['collection_slug' => 'users']);
    }

    /**
     * Indicate that the entry records a change to the SMTP settings.
     */
    public function forSmtpSettings(): static
    {
        return $this->state(fn (array $attributes) => ['collection_slug' => 'smtp-settings']);
    }
}
