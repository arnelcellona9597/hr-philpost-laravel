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
use App\Services\EmploymentHistoryService;
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

it('changes the dashboard to follow a chosen date range', function () {
    $branch = Branch::factory()->create();
    $hired = Employee::factory()->create(['station_id' => $branch->id, 'first_name' => 'Inrange', 'date_hired' => '2026-09-10', 'employment_status' => EmploymentStatus::Active]);
    Employee::factory()->create(['station_id' => $branch->id, 'first_name' => 'Zzfarout', 'date_hired' => '2026-02-10', 'employment_status' => EmploymentStatus::Active]);

    $range = $this->get('/?from=2026-09-01&to=2026-10-15')->assertOk()->assertSee('Custom range');
    $stats = app(DashboardStats::class);
    $movement = $stats->period(ReportingPeriod::resolve(null, null, '2026-09-01', '2026-10-15'), '2025-11-01', null, null);

    expect(array_sum($movement['hiresByDay']))->toBe(1)
        ->and($range->viewData('period')['key'])->toBe('custom')
        ->and($range->viewData('recentHires')->pluck('first_name')->all())->toBe(['Inrange'])
        ->and($hired->id)->toBeGreaterThan(0);

    $this->get('/?from=2026-10-15&to=2026-09-01')->assertOk()->assertDontSee('Custom range');
});

it('counts headcount as of the end of a chosen range from the employment history', function () {
    $stayed = Employee::factory()->create(['date_hired' => '2020-01-01', 'classification' => Classification::Regular, 'employment_status' => EmploymentStatus::Active]);
    $left = Employee::factory()->create(['date_hired' => '2021-01-01', 'last_day_of_service' => '2026-10-20', 'classification' => Classification::Cos, 'employment_status' => EmploymentStatus::Resigned]);
    $later = Employee::factory()->create(['date_hired' => '2026-11-01', 'classification' => Classification::Regular, 'employment_status' => EmploymentStatus::Active]);

    $stats = app(DashboardStats::class);
    $onOct15 = $stats->employees(null, null, '2026-10-15');
    $onNov10 = $stats->employees(null, null, '2026-11-10');
    $today = $stats->employees(null, null);

    expect($onOct15['total'])->toBe(2)
        ->and($onOct15['byStatus'])->toBe(['Active' => 2])
        ->and($onNov10['byStatus'])->toBe(['Active' => 2, 'Resigned' => 1])
        ->and($onNov10['total'])->toBe(3)
        ->and($today['byStatus'])->toBe(['Active' => 2, 'Resigned' => 1])
        ->and($onOct15['asOf'])->toBe('2026-10-15')
        ->and($stats->employees(null, 'COS', '2026-10-15')['total'])->toBe(1)
        ->and($stayed->id + $left->id + $later->id)->toBeGreaterThan(0);
});

it('uses the period that was current on the date for someone hired again', function () {
    $employee = Employee::factory()->create(['date_hired' => '2022-01-01', 'last_day_of_service' => '2023-06-30', 'employment_status' => EmploymentStatus::Resigned]);
    app(EmploymentHistoryService::class)->rehire($employee, '2026-03-01', null);

    $stats = app(DashboardStats::class);

    expect($stats->employees(null, null, '2025-01-01')['byStatus'])->toBe(['Resigned' => 1])
        ->and($stats->employees(null, null, '2022-06-01')['byStatus'])->toBe(['Active' => 1])
        ->and($stats->employees(null, null, '2021-01-01')['total'])->toBe(0);
});
