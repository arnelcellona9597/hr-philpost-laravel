<?php

use App\Models\Application;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Models\WellnessLeave;
use App\Services\DuplicateFinder;

beforeEach(fn () => signIn(User::factory()->create()));

function twin(array $attributes = []): array
{
    $person = ['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'date_of_birth' => '1990-05-01', 'email' => null, 'tin' => null, 'sss' => null, 'philhealth' => null, 'pagibig' => null];

    return [Employee::factory()->create([...$person, ...$attributes]), Employee::factory()->create([...$person, ...$attributes])];
}

it('finds employees entered twice by name and birth date, ignoring case and spaces', function () {
    [$first] = twin();
    $second = Employee::factory()->create(['last_name' => ' DELA CRUZ ', 'first_name' => 'juan', 'date_of_birth' => '1990-05-01', 'email' => null, 'tin' => null, 'sss' => null, 'philhealth' => null, 'pagibig' => null]);
    Employee::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'date_of_birth' => '1991-01-01', 'email' => null, 'tin' => null, 'sss' => null, 'philhealth' => null, 'pagibig' => null]);

    $groups = app(DuplicateFinder::class)->groups('employees');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['ids'])->toContain($first->id, $second->id)
        ->and($groups[0]['reason'])->toBe('Same name and date of birth');
});

it('finds shared email and government numbers and joins overlapping groups', function () {
    $a = Employee::factory()->create(['last_name' => 'Aaa', 'first_name' => 'One', 'email' => 'same@example.test', 'tin' => '111-222-333-000', 'date_of_birth' => '1980-01-01']);
    $b = Employee::factory()->create(['last_name' => 'Bbb', 'first_name' => 'Two', 'email' => 'SAME@example.test', 'tin' => null, 'date_of_birth' => '1981-01-01']);
    $c = Employee::factory()->create(['last_name' => 'Ccc', 'first_name' => 'Three', 'email' => null, 'tin' => '111-222-333-000', 'date_of_birth' => '1982-01-01']);

    $groups = app(DuplicateFinder::class)->groups('employees');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['ids'])->toBe([$a->id, $b->id, $c->id])
        ->and($groups[0]['reason'])->toContain('Same email address')->toContain('Same TIN');
});

it('finds applicants entered twice', function () {
    Application::factory()->count(2)->create(['applicant_name' => 'Ana Reyes', 'position_applied' => 'Clerk', 'date_applied' => '2026-06-01']);
    Application::factory()->create(['applicant_name' => 'Ana Reyes', 'position_applied' => 'Clerk', 'date_applied' => '2026-07-01']);

    expect(app(DuplicateFinder::class)->groups('applications'))->toHaveCount(1);
    $this->get('/data/duplicates?module=applications')->assertOk()->assertSee('Same name, position and date applied');
});

it('shows the groups and an empty state', function () {
    $this->get('/data/duplicates')->assertOk()->assertSee('No duplicates found');

    twin();
    $this->get('/data/duplicates')->assertOk()->assertSee('Same name and date of birth')->assertSee('oldest, kept');
});

it('deletes the chosen copies and keeps those with linked records', function () {
    [$keep, $copy] = twin();
    [$linked] = twin(['last_name' => 'Santos', 'first_name' => 'Maria']);
    WellnessLeave::factory()->create(['employee_id' => $linked->id]);

    $this->post('/data/duplicates', ['module' => 'employees', 'ids' => [$copy->id, $linked->id]])->assertRedirect('/data/duplicates?module=employees');

    expect(Employee::query()->whereKey($copy->id)->exists())->toBeFalse()
        ->and(Employee::query()->whereKey($keep->id)->exists())->toBeTrue()
        ->and(Employee::query()->whereKey($linked->id)->exists())->toBeTrue();
    $this->get('/data/duplicates')->assertSee('has linked records');
    $this->post('/data/duplicates', ['module' => 'employees', 'ids' => []])->assertSessionHas('flash');
});

it('deletes duplicate applications', function () {
    [$first, $second] = Application::factory()->count(2)->create(['applicant_name' => 'Ana Reyes', 'position_applied' => 'Clerk', 'date_applied' => '2026-06-01']);

    $this->post('/data/duplicates', ['module' => 'applications', 'ids' => [$second->id]]);

    expect(Application::query()->count())->toBe(1)->and(Application::query()->first()->id)->toBe($first->id);
});

it('warns when a newly added employee looks like an existing one', function () {
    $branch = Branch::factory()->create();
    Employee::factory()->create(['last_name' => 'Reyes', 'first_name' => 'Ana', 'date_of_birth' => '1992-02-02']);
    $employee = Employee::factory()->make(['last_name' => 'Reyes', 'first_name' => 'Ana', 'date_of_birth' => '1992-02-02']);

    expect(app(DuplicateFinder::class)->similarTo(Employee::query()->create($employee->toArray() + ['station_id' => $branch->id])))->toHaveCount(1);
});

it('ignores a second send of the same new-application form', function () {
    $token = '3f2b8c1e-9d4a-4e6b-8a57-1c2d3e4f5a6b';
    $form = ['submission' => $token, 'applicant_name' => 'Ana Reyes', 'position_applied' => 'Clerk', 'date_applied' => '2026-06-01', 'application_status' => 'Pending'];

    $this->post('/onboarding', $form)->assertRedirect('/onboarding');
    $this->post('/onboarding', $form)->assertRedirect('/onboarding')->assertSessionHas('flash', fn (array $flash): bool => str_contains($flash['message'], 'already added'));

    expect(Application::query()->count())->toBe(1);
    $this->get('/onboarding/new')->assertSee('name="submission"', false);
});

it('lets the onboarding table choose how many rows to show', function () {
    Application::factory()->count(12)->create();

    $page = $this->get('/onboarding?perPage=10')->assertOk()->assertSee('Rows per page');
    expect($page->viewData('applications')->perPage())->toBe(10)->and($page->viewData('applications')->total())->toBe(12);
    expect($this->get('/onboarding?perPage=7')->viewData('applications')->perPage())->toBe(25);
    $this->get('/onboarding?perPage=10&page=2')->assertOk();
});

it('puts the applicant before the application ID so it can stay in view', function () {
    Application::factory()->create(['applicant_name' => 'Ana Reyes']);

    $this->get('/onboarding')->assertOk()->assertSeeInOrder(['Applicant', 'App ID'])->assertSee('sticky left-0', false);
});

it('treats a shared bank account as a duplicate', function () {
    Employee::factory()->count(2)->create(['bank_account_number' => '1234567890', 'email' => null]);

    expect(app(DuplicateFinder::class)->groups('employees')[0]['reason'])->toContain('Same bank account number');
});
