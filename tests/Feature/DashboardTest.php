<?php

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\LeaveStatus;
use App\Models\Application;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\User;
use App\Models\WellnessLeave;
use App\Services\DashboardStats;
use App\Support\ReportingPeriod;

beforeEach(function () {
    $this->travelTo('2026-11-15 10:00:00');
    signIn(User::factory()->create(['name' => 'Rita Santos']));
});

it('greets the person and shows empty states on a fresh install', function () {
    $this->get('/')->assertOk()
        ->assertSee('Good morning, Rita')
        ->assertSee('Nothing waiting for approval.')
        ->assertSee('No payroll periods yet.')
        ->assertSee('Active employees');
});

it('counts headcount, movement and leave for the filters', function () {
    $branch = Branch::factory()->create();
    Employee::factory()->create(['station_id' => $branch->id, 'classification' => Classification::Regular, 'employment_status' => EmploymentStatus::Active, 'date_hired' => '2026-02-01', 'gender' => 'Female', 'position' => 'Clerk']);
    Employee::factory()->create(['station_id' => $branch->id, 'classification' => Classification::Cos, 'employment_status' => EmploymentStatus::Resigned, 'date_hired' => '2020-01-01', 'last_day_of_service' => '2026-03-01', 'gender' => 'Male']);
    Employee::factory()->create(['classification' => Classification::Regular, 'employment_status' => EmploymentStatus::Active, 'date_hired' => '2025-02-01']);

    $period = ReportingPeriod::resolve('this-year');
    $stats = app(DashboardStats::class);

    $all = $stats->employees(null, null);
    $scoped = $stats->employees($branch->id, 'Regular');
    $movement = $stats->period($period, '2025-12-01', $branch->id, null);

    expect($all['total'])->toBe(3)
        ->and($all['byStatus'])->toBe(['Active' => 2, 'Resigned' => 1])
        ->and($scoped['total'])->toBe(1)
        ->and($scoped['positions'])->toBe([['position' => 'Clerk', 'total' => 1]])
        ->and(array_sum($movement['hiresByDay']))->toBe(1)
        ->and(array_sum($movement['separationsByDay']))->toBe(1);

    $wide = $stats->period($period, '2025-12-01', null, null);

    expect($wide['prevHires'])->toBe(1);
});

it('adds up leave, the pipeline and the latest payroll', function () {
    $employee = Employee::factory()->create();
    WellnessLeave::factory()->create(['employee_id' => $employee->id, 'status' => LeaveStatus::Pending, 'inclusive_date_from' => '2026-03-02', 'inclusive_date_to' => '2026-03-03', 'days' => 2, 'first_name' => 'Ana', 'last_name' => 'Reyes']);
    Application::factory()->create(['application_status' => 'Pending']);
    Application::factory()->create(['application_status' => 'Withdrawn']);
    $period = PayrollPeriod::factory()->create(['name' => 'Oct A']);
    Payslip::factory()->create(['period_id' => $period->id]);

    $movement = app(DashboardStats::class)->period(ReportingPeriod::resolve('this-year'), '2025-12-01', null, null);

    expect($movement['leaveDays'])->toBe(2)
        ->and($movement['pendingLeave'])->toBe(1)
        ->and($movement['pipeline'])->toMatchArray(['Pending' => 1, 'Withdrawn' => 1])
        ->and($movement['incompleteRequirements'])->toBe(1);

    $this->get('/')->assertOk()->assertSee('Reyes, Ana')->assertSee('Oct A')->assertSee('₱23,749.50');
});

it('accepts every period and filter, ignoring bad ones', function (string $query) {
    Employee::factory()->create(['date_hired' => '2026-02-01']);

    $this->get("/?{$query}")->assertOk();
})->with([
    'period=this-month',
    'period=last-month',
    'period=90d',
    'period=12m',
    'period=10y',
    'period=nonsense',
    'classification=Regular&branch=1',
    'classification=<script>',
]);

it('shows recent activity with links, without deleted records', function () {
    $employee = Employee::factory()->create(['last_name' => 'Reyes', 'first_name' => 'Ana']);

    $this->get('/')->assertSee("/employees/{$employee->id}", false)->assertSee('added employee');
});

it('hides account changes from HR Staff in the activity feed', function () {
    User::factory()->create(['name' => 'Hidden Account']);

    $this->get('/')->assertDontSee('Hidden Account');
});
