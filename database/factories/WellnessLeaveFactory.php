<?php

namespace Database\Factories;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\WellnessLeave;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WellnessLeave>
 */
class WellnessLeaveFactory extends Factory
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
            'date_filing' => '2026-03-02',
            'inclusive_date_from' => '2026-03-09',
            'inclusive_date_to' => '2026-03-10',
            'status' => LeaveStatus::Approved,
            'days' => 2,
            'year' => 2026,
            'days_by_year' => ['2026' => 2],
        ];
    }
}
