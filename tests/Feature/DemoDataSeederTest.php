<?php

use App\Enums\EmploymentStatus;
use App\Enums\PayrollStatus;
use App\Enums\RecipientStatus;
use App\Models\Application;
use App\Models\Branch;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\ItrSubmission;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\User;
use App\Models\WellnessLeave;
use App\Support\GovId;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Mail;

function seedDemo(int $employees = 40): void
{
    $seeder = app(DemoDataSeeder::class);
    $seeder->employeeCount = $employees;
    $seeder->run();
}

it('fills every module with linked records', function () {
    seedDemo();

    $active = Employee::query()->where('employment_status', EmploymentStatus::Active)->count();

    expect(Employee::query()->count())->toBe(40)
        ->and(Branch::query()->count())->toBe(12)
        ->and(WellnessLeave::query()->count())->toBeGreaterThan(0)
        ->and(ItrSubmission::query()->count())->toBeGreaterThan(0)
        ->and(Application::query()->count())->toBe(10)
        ->and(Certificate::query()->where('status', 'Void')->count())->toBe(1)
        ->and(PayrollPeriod::query()->where('status', PayrollStatus::Released)->count())->toBe(1)
        ->and(Payslip::query()->count())->toBe($active * 2);
});

it('can never send an email', function () {
    Mail::fake();
    $admin = User::factory()->systemAdmin()->create(['email' => 'real@phlposthr.com']);

    seedDemo();

    Mail::assertNothingSent();

    expect(MessageRecipient::query()->whereIn('status', [RecipientStatus::Queued, RecipientStatus::Sending])->count())->toBe(0)
        ->and(Employee::query()->where('email', 'not like', '%@example.test')->count())->toBe(0)
        ->and(MessageRecipient::query()->where('email', 'not like', '%@example.test')->count())->toBe(0)
        ->and(Application::query()->where('email', 'not like', '%@example.test')->count())->toBe(0)
        ->and(User::query()->whereKeyNot($admin->id)->where('email', 'not like', '%@example.test')->count())->toBe(0)
        ->and(Message::query()->count())->toBe(3);
});

it('creates valid government IDs and consistent dates', function () {
    seedDemo();

    foreach (Employee::query()->get() as $employee) {
        foreach (['sss', 'pagibig', 'tin', 'philhealth'] as $kind) {
            expect(GovId::validate($kind, $employee->{$kind}))->toBeNull();
        }

        if ($employee->last_day_of_service !== null) {
            expect($employee->last_day_of_service->gte($employee->date_hired))->toBeTrue();
        }
    }
});

it('keeps released payslips filled so the release rules hold', function () {
    seedDemo();

    $released = PayrollPeriod::query()->where('status', PayrollStatus::Released)->sole();

    expect($released->payslips()->where('gross_pay_centavos', 0)->count())->toBe(0)
        ->and($released->payslips()->whereColumn('total_deductions_centavos', '>', 'gross_pay_centavos')->count())->toBe(0);
});

it('refuses to run over existing employees', function () {
    Employee::factory()->create();

    seedDemo();

    expect(Employee::query()->count())->toBe(1);
});

it('refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => seedDemo())->toThrow(RuntimeException::class, 'Refusing to add demo data in production.');
});

it('shows the dashboard with demo data', function () {
    seedDemo();
    signIn(User::factory()->create());

    $this->get('/')->assertOk()->assertSee('Active employees')->assertSee('Central Office');
});
