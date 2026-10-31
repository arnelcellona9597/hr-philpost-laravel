<?php

namespace Database\Factories;

use App\Enums\LoginChallengePurpose;
use App\Models\LoginChallenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoginChallenge>
 */
class LoginChallengeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => hash('sha256', fake()->unique()->uuid()),
            'purpose' => LoginChallengePurpose::Login,
            'user_id' => User::factory(),
            'code_hash' => hash('sha256', '123456'),
            'expires_at' => now()->addMinutes(10),
        ];
    }
}
