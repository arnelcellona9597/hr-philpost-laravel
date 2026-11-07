<?php

use App\Enums\ApplicationStatus;
use App\Enums\OnboardingRequirement;
use App\Enums\RequirementsCompletion;
use App\Enums\RequirementStatus;
use App\Models\Application;
use App\Models\Employee;
use App\Models\IpcrRating;
use App\Models\ItrSubmission;
use App\Models\LeaveSetting;
use App\Models\NotificationSetting;
use App\Models\Payslip;
use App\Models\PayslipSetting;
use App\Models\RequirementDeadlineSetting;
use App\Models\SiteSetting;
use App\Models\SmtpSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

it('stores the listing name when an employee is saved', function () {
    $employee = Employee::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'Pedro', 'extension' => 'Jr.']);

    expect($employee->full_name)->toBe('Dela Cruz, Juan P. Jr.');

    $employee->update(['first_name' => 'Jose']);

    expect($employee->fresh()->full_name)->toBe('Dela Cruz, Jose P. Jr.');
});

it('computes age and length of service in Manila time', function () {
    $this->travelTo('2026-06-30T16:30:00Z');

    $employee = Employee::factory()->create(['date_of_birth' => '2000-07-01', 'date_hired' => '2024-07-01', 'last_day_of_service' => null]);

    expect($employee->fresh()->age)->toBe(26)
        ->and($employee->fresh()->length_of_service)->toBe('2 yrs');
});

it('keeps dates as plain dates in MySQL', function () {
    $employee = Employee::factory()->create(['date_hired' => '2024-03-05']);

    expect(DB::table('employees')->where('id', $employee->id)->value('date_hired'))->toBe('2024-03-05')
        ->and($employee->fresh()->toArray()['date_hired'])->toBe('2024-03-05');
});

it('counts missing onboarding requirements on save', function () {
    $application = Application::factory()->create();

    expect($application->fresh()->requirements_missing)->toBe(10)
        ->and($application->fresh()->requirements_status)->toBe(RequirementsCompletion::Incomplete);

    $application->update(collect(OnboardingRequirement::cases())
        ->mapWithKeys(fn (OnboardingRequirement $requirement) => [$requirement->value => RequirementStatus::NotApplicable])
        ->all());

    expect($application->fresh()->requirements_missing)->toBe(0)
        ->and($application->fresh()->requirements_status)->toBe(RequirementsCompletion::Complete);
});

it('names the requirements still pending', function () {
    $application = Application::factory()->create(['req_pds' => RequirementStatus::Submitted, 'req_or_cr' => RequirementStatus::Verified]);

    expect($application->missingRequirements())->not->toContain('PDS', 'OR/CR')
        ->and($application->missingRequirements())->toContain('Medical Lab')
        ->and($application->requirements_missing)->toBe(8);
});

it('stamps the moment an applicant is hired and clears it afterwards', function () {
    $this->travelTo('2026-03-01 09:00:00');
    $application = Application::factory()->create();

    expect($application->hired_at)->toBeNull();

    $application->update(['application_status' => ApplicationStatus::Hired]);
    $hiredAt = $application->fresh()->hired_at;

    expect($hiredAt->toDateTimeString())->toBe('2026-03-01 09:00:00');

    $this->travelTo('2026-03-02 09:00:00');
    $application->update(['remarks' => 'Started']);

    expect($application->fresh()->hired_at->toDateTimeString())->toBe('2026-03-01 09:00:00');

    $application->update(['application_status' => ApplicationStatus::NotHired]);

    expect($application->fresh()->hired_at)->toBeNull();
});

it('stamps applicants created as hired', function () {
    expect(Application::factory()->hired()->create()->hired_at)->not->toBeNull();
});

it('rounds IPCR ratings and derives the adjectival rating', function () {
    $rating = IpcrRating::factory()->create(['rating' => 4.4996]);

    expect($rating->fresh()->rating)->toBe('4.50')
        ->and($rating->fresh()->adjectival_rating)->toBe('Outstanding');

    $rating->update(['rating' => null]);

    expect($rating->fresh()->adjectival_rating)->toBeNull();
});

it('keeps a stored three-decimal IPCR rating when other fields change, and shows it with two', function () {
    $rating = IpcrRating::factory()->create(['rating' => 4.5]);
    DB::table('ipcr_ratings')->where('id', $rating->id)->update(['rating' => 4.495, 'adjectival_rating' => 'Very Satisfactory']);

    $rating->fresh()->update(['remarks' => 'Checked']);

    expect((string) DB::table('ipcr_ratings')->where('id', $rating->id)->value('rating'))->toBe('4.495')
        ->and($rating->fresh()->adjectival_rating)->toBe('Very Satisfactory')
        ->and($rating->fresh()->rating)->toBe('4.50');
});

it('adds up payslip totals in centavos', function () {
    $payslip = Payslip::factory()->create([
        'earnings' => [['label' => 'Basic', 'amount_centavos' => 10], ['label' => 'PERA', 'amount_centavos' => 20]],
        'deductions' => [['label' => 'Tax', 'amount_centavos' => 5]],
    ]);

    expect($payslip->fresh()->gross_pay_centavos)->toBe(30)
        ->and($payslip->fresh()->total_deductions_centavos)->toBe(5)
        ->and($payslip->fresh()->net_pay_centavos)->toBe(25);
});

it('derives compliance deadlines and timeliness from settings on read', function () {
    $this->travelTo('2026-06-01');

    $submission = ItrSubmission::factory()->create(['year' => 2026, 'date_submitted' => '2026-05-02']);

    expect($submission->deadline)->toBe('2026-04-30')
        ->and($submission->timeliness['label'])->toBe('Late (2 days)');

    RequirementDeadlineSetting::current()->update([
        'overrides' => [['requirement' => 'itr-submissions', 'period' => null, 'year' => 2026, 'deadline' => '2026-05-15']],
    ]);

    expect($submission->fresh()->timeliness['label'])->toBe('On time');
});

it('uses the rating period for IPCR deadlines', function () {
    expect(IpcrRating::factory()->create(['year' => 2026])->deadline)->toBe('2026-07-15');
});

it('creates each settings group once with defaults', function (string $settings) {
    $first = $settings::current();
    $second = $settings::current();

    expect($first->id)->toBe(1)
        ->and($second->id)->toBe(1)
        ->and($settings::query()->count())->toBe(1);
})->with([
    SiteSetting::class,
    LeaveSetting::class,
    NotificationSetting::class,
    PayslipSetting::class,
    SmtpSetting::class,
    RequirementDeadlineSetting::class,
]);

it('ships usable defaults on a fresh install', function () {
    expect(SiteSetting::current()->palette['primary'])->toBe('#00539b')
        ->and(LeaveSetting::current()->allowanceForYear(2026))->toBe(5)
        ->and(PayslipSetting::current()->earning_items)->toContain('Basic Pay')
        ->and(NotificationSetting::current()->hourly_limit)->toBe(100)
        ->and(NotificationSetting::current()->footer)->not->toBeEmpty()
        ->and(RequirementDeadlineSetting::current()->itr)->toEqual(['month' => 4, 'day' => 30, 'next_year' => false])
        ->and(SmtpSetting::current()->port)->toBe(465);
});

it('generates a cron key once and keeps it out of serialised output', function () {
    $settings = NotificationSetting::current();

    expect($settings->queue_key)->toHaveLength(36)
        ->and(NotificationSetting::current()->queue_key)->toBe($settings->queue_key)
        ->and($settings->toArray())->not->toHaveKey('queue_key');
});

it('uses a year override for the leave allowance', function () {
    $settings = LeaveSetting::current();
    $settings->update(['year_overrides' => [['year' => 2025, 'allowance' => 8]]]);

    expect($settings->allowanceForYear(2025))->toBe(8)
        ->and($settings->allowanceForYear(2026))->toBe(5);
});

it('encrypts the SMTP password and never serialises it', function () {
    $settings = SmtpSetting::current();

    expect($settings->password_set)->toBeFalse();

    $settings->update(['password' => 'not-a-real-password']);

    $stored = DB::table('smtp_settings')->value('password');

    expect($stored)->not->toContain('not-a-real-password')
        ->and(Crypt::decryptString($stored))->toBe('not-a-real-password')
        ->and($settings->fresh()->password)->toBe('not-a-real-password')
        ->and($settings->fresh()->password_set)->toBeTrue()
        ->and($settings->fresh()->toArray())->not->toHaveKey('password');
});

it('does not let mass assignment set a role or status', function () {
    $user = User::factory()->create();

    $user->fill(['role' => 'system-admin', 'status' => 'disabled']);

    expect($user->isDirty(['role', 'status']))->toBeFalse();
});
