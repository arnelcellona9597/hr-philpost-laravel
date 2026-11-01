<?php

namespace Database\Factories;

use App\Enums\PayChangeType;
use App\Enums\RateType;
use App\Models\Employee;
use App\Models\PayChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayChange>
 */
class PayChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'effective_on' => '2025-01-01',
            'change_type' => PayChangeType::Starting,
            'rate_type' => RateType::Daily,
            'amount_centavos' => 45_000,
        ];
    }
}
