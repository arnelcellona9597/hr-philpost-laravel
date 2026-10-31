<?php

namespace Database\Factories;

use App\Enums\ImportJobStatus;
use App\Models\ImportJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportJob>
 */
class ImportJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'file_name' => 'employees.xlsx',
            'module' => 'employees',
            'status' => ImportJobStatus::Committed,
        ];
    }
}
