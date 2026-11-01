<?php

namespace Database\Factories;

use App\Enums\RemittanceKind;
use App\Enums\RemittanceStatus;
use App\Models\Remittance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Remittance>
 */
class RemittanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => RemittanceKind::Sss,
            'year' => 2026,
            'month' => fake()->numberBetween(1, 12),
            'status' => RemittanceStatus::Draft,
        ];
    }
}
