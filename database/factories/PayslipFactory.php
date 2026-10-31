<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payslip>
 */
class PayslipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period_id' => PayrollPeriod::factory(),
            'employee_id' => Employee::factory(),
            'full_name' => fake()->name(),
            'employee_code' => fake()->numerify('EMP-#####'),
            'earnings' => [['label' => 'Basic Pay', 'amount_centavos' => 2_500_000]],
            'deductions' => [['label' => 'PhilHealth', 'amount_centavos' => 125_050]],
        ];
    }
}
