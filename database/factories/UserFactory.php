<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->userName().'@example.test',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::HrStaff,
            'status' => UserStatus::Approved,
        ];
    }

    /**
     * Indicate that the account is a System Admin.
     */
    public function systemAdmin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::SystemAdmin]);
    }

    /**
     * Indicate that the account is waiting for approval.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Pending]);
    }

    /**
     * Indicate that the account has been disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Disabled]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
