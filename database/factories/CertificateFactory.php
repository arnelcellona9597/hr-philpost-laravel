<?php

namespace Database\Factories;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template_id' => CertificateTemplate::factory(),
            'employee_id' => Employee::factory(),
            'purpose' => 'whatever legal purpose it may serve',
            'issued_date' => '2026-10-01',
            'control_number' => fake()->unique()->numerify('COE-2026-####'),
            'template_name' => 'Certificate of Employment',
            'employee_name' => fake()->name(),
            'content' => [],
        ];
    }
}
