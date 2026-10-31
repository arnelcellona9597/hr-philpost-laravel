<?php

use App\Enums\EmploymentStatus;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\IpcrRating;
use App\Models\ItrSubmission;
use App\Models\PdsSubmission;
use App\Models\RequirementDeadlineSetting;
use App\Models\SwornDeclaration;
use App\Models\User;

beforeEach(function () {
    $this->travelTo('2026-06-01');
    signIn(User::factory()->create());
});

it('shows each requirement on the overview', function () {
    $employee = Employee::factory()->create();
    Employee::factory()->create();
    ItrSubmission::factory()->for($employee)->create(['year' => 2026, 'date_submitted' => '2026-04-01']);

    $page = $this->get('/requirements?year=2026')->assertSuccessful()->assertSee('50%')->assertSee('1 of 2 active');

    expect($page->viewData('cards')[0]['timely']['counts']['on-time'])->toBe(1)
        ->and($page->viewData('cards')[0]['timely']['counts']['overdue'])->toBe(1);
});

it('counts active employees only', function () {
    Employee::factory()->create(['employment_status' => EmploymentStatus::Resigned]);

    expect($this->get('/requirements')->viewData('cards')[0]['stats']['active'])->toBe(0);
});

it('asks for deadlines when none are set', function () {
    RequirementDeadlineSetting::current()->update(['itr' => [], 'sworn' => [], 'pds' => [], 'ipcr_jan_jun' => [], 'ipcr_jul_dec' => []]);

    $this->get('/requirements')->assertSee('Set the submission deadlines');
});

it('answers 404 for an unknown requirement', function () {
    $this->get('/requirements/nope')->assertNotFound();
});

it('lists records with timeliness badges', function (string $route, string $model) {
    $model::factory()->create(['year' => 2026, 'date_submitted' => '2026-01-02']);

    $this->get("/requirements/{$route}?year=2026")->assertSuccessful()->assertSee('On time');
})->with([
    ['itr', ItrSubmission::class],
    ['sworn-declaration', SwornDeclaration::class],
    ['pds', PdsSubmission::class],
    ['ipcr', IpcrRating::class],
]);

it('adds a record and fills blank details from the employee', function () {
    $branch = Branch::factory()->create(['name' => 'Manila']);
    $employee = Employee::factory()->for($branch, 'station')->create(['last_name' => 'Reyes', 'position' => 'Clerk', 'tin' => '111-222-333']);

    $this->post('/requirements/sworn-declaration?year=2026', ['employee' => $employee->id, 'year' => 2026, 'date_submitted' => '2026-03-01'])
        ->assertRedirect('/requirements/sworn-declaration?year=2026');

    $record = SwornDeclaration::query()->firstOrFail();

    expect($record->last_name)->toBe('Reyes')
        ->and($record->station)->toBe('Manila')
        ->and($record->job_title)->toBe('Clerk')
        ->and($record->tin)->toBe('111-222-333');
});

it('keeps details typed on a new record', function () {
    $employee = Employee::factory()->create(['position' => 'Clerk']);

    $this->post('/requirements/sworn-declaration?year=2026', ['employee' => $employee->id, 'year' => 2026, 'job_title' => 'Officer']);

    expect(SwornDeclaration::query()->firstOrFail()->job_title)->toBe('Officer');
});

it('replaces details when the employee changes', function () {
    $record = SwornDeclaration::factory()->create(['year' => 2026]);
    $other = Employee::factory()->create(['last_name' => 'Santos']);

    $this->put("/requirements/sworn-declaration/{$record->id}", ['employee' => $other->id, 'year' => 2026]);

    expect($record->fresh()->last_name)->toBe('Santos');
});

it('explains a duplicate record', function () {
    $record = ItrSubmission::factory()->create(['year' => 2026]);

    $this->from('/requirements/itr/new')->post('/requirements/itr?year=2026', ['employee' => $record->employee_id, 'year' => 2026])
        ->assertSessionHasErrors(['form' => 'This employee already has a Annual Income Tax Return record for 2026.']);
});

it('refuses an employee that does not exist', function () {
    $this->from('/requirements/itr/new')->post('/requirements/itr?year=2026', ['employee' => 999, 'year' => 2026])->assertSessionHasErrors('employee');
});

it('checks the dates and the rating', function (array $form, string $field) {
    $employee = Employee::factory()->create();

    $this->from('/requirements/ipcr/new')->post('/requirements/ipcr?year=2026', ['employee' => $employee->id, 'year' => 2026, 'rating_period' => 'Jan-Jun', ...$form])
        ->assertSessionHasErrors($field);
})->with([
    [['date_submitted' => '2026-03-02', 'date_received' => '2026-03-01'], 'date_received'],
    [['period_from' => '2026-06-30', 'period_to' => '2026-01-01'], 'period_to'],
    [['rating' => '5.5'], 'rating'],
    [['rating_period' => ''], 'rating_period'],
    [['year' => '1999'], 'year'],
]);

it('stores an IPCR rating with its adjectival rating', function () {
    $employee = Employee::factory()->create();

    $this->post('/requirements/ipcr?year=2026', ['employee' => $employee->id, 'year' => 2026, 'rating_period' => 'Jan-Jun', 'rating' => '4.6']);

    $record = IpcrRating::query()->firstOrFail();

    expect($record->adjectival_rating)->toBe('Outstanding');
    $this->get("/requirements/ipcr/{$record->id}")->assertSuccessful()->assertSee('Outstanding')->assertSee('Pending');
});

it('shows the deadline in the help text', function () {
    $this->get('/requirements/itr/new?year=2026')->assertSuccessful()->assertSee('Deadline for 2026: Apr 30, 2026');
});

it('filters records by timeliness', function (string $status, array $expected) {
    ItrSubmission::factory()->create(['year' => 2026, 'date_submitted' => '2026-04-01', 'last_name' => 'OnTime']);
    ItrSubmission::factory()->create(['year' => 2026, 'date_submitted' => '2026-05-01', 'last_name' => 'Late']);
    ItrSubmission::factory()->create(['year' => 2026, 'last_name' => 'Blank']);

    expect($this->get("/requirements/itr?year=2026&status={$status}")->viewData('records')->pluck('last_name')->all())->toBe($expected);
})->with([
    ['on-time', ['OnTime']],
    ['late', ['Late']],
    ['overdue', ['Blank']],
    ['pending', []],
    ['submitted', []],
]);

it('applies IPCR status per rating period', function () {
    IpcrRating::factory()->create(['year' => 2026, 'rating_period' => 'Jul-Dec', 'last_name' => 'Waiting']);
    IpcrRating::factory()->create(['year' => 2026, 'rating_period' => 'Jan-Jun', 'last_name' => 'Soon']);

    expect($this->get('/requirements/ipcr?year=2026&status=pending')->viewData('records'))->toHaveCount(2);

    $this->travelTo('2026-08-01');
    signIn(User::factory()->create());

    expect($this->get('/requirements/ipcr?year=2026&status=pending')->viewData('records')->pluck('last_name')->all())->toBe(['Waiting'])
        ->and($this->get('/requirements/ipcr?year=2026&status=overdue')->viewData('records')->pluck('last_name')->all())->toBe(['Soon']);
});

it('searches only columns the requirement has', function () {
    ItrSubmission::factory()->create(['year' => 2026, 'last_name' => 'Reyes']);

    expect($this->get('/requirements/itr?year=2026&q=reyes')->viewData('records'))->toHaveCount(1);
});

it('lists who has not complied', function () {
    $employee = Employee::factory()->create(['last_name' => 'Missing']);

    $this->get('/requirements/itr?year=2026&view=missing')->assertSuccessful()->assertSee('Missing')->assertSee('Overdue')->assertSee("employees={$employee->id}", false);
});

it('shows when everyone has complied', function () {
    $this->get('/requirements/itr?year=2026&view=missing')->assertSee('Everyone has complied');
});

it('generates rows for active employees only and only once', function () {
    Employee::factory()->count(2)->create();
    Employee::factory()->create(['employment_status' => EmploymentStatus::Retired]);

    $this->post('/requirements/itr/generate?year=2026')->assertSessionHas('flash.message', 'Added 2 rows for 2026. Fill in the dates as documents arrive.');
    $this->post('/requirements/itr/generate?year=2026')->assertSessionHas('flash.message', 'Every active employee already has a row.');

    expect(ItrSubmission::query()->count())->toBe(2);
});

it('needs a rating period to generate IPCR rows', function () {
    Employee::factory()->create();

    $this->post('/requirements/ipcr/generate?year=2026')->assertSessionHas('flash.type', 'error');
    $this->post('/requirements/ipcr/generate?year=2026', ['rating_period' => 'Jul-Dec']);

    expect(IpcrRating::query()->firstOrFail()->rating_period->value)->toBe('Jul-Dec');
});

it('changes a deadline for one year from the list and resets it', function () {
    $this->from('/requirements/itr')->put('/requirements/itr/deadline?year=2026', ['deadline' => '2026-05-15'])
        ->assertSessionHas('flash.message', 'Deadline for 2026 set to May 15, 2026.');

    $this->get('/requirements/itr?year=2026')->assertSee('changed for 2026');
    $this->get('/requirements/itr?year=2027')->assertDontSee('changed for 2027');

    $this->from('/requirements/itr')->put('/requirements/itr/deadline?year=2026', ['reset' => '1']);

    expect(RequirementDeadlineSetting::current()->overrides)->toBe([]);
});

it('replaces rather than stacks a deadline change', function () {
    foreach (['2026-05-15', '2026-05-20'] as $date) {
        $this->from('/requirements/itr')->put('/requirements/itr/deadline?year=2026', ['deadline' => $date]);
    }

    expect(RequirementDeadlineSetting::current()->overrides)->toHaveCount(1);
});

it('needs a period for an IPCR deadline', function () {
    $this->from('/requirements/ipcr')->put('/requirements/ipcr/deadline?year=2026', ['deadline' => '2026-05-15'])->assertSessionHas('flash.type', 'error');
});

it('edits and deletes a record', function () {
    $record = ItrSubmission::factory()->create(['year' => 2026]);

    $this->get("/requirements/itr/{$record->id}")->assertSuccessful();
    $this->put("/requirements/itr/{$record->id}", ['employee' => $record->employee_id, 'year' => 2026, 'remarks' => 'Seen'])->assertRedirect('/requirements/itr?year=2026');

    expect($record->fresh()->remarks)->toBe('Seen');

    $this->delete("/requirements/itr/{$record->id}")->assertRedirect('/requirements/itr?year=2026');

    expect($record->fresh())->toBeNull();
});

it('finds records only of the right type', function () {
    $record = ItrSubmission::factory()->create();

    $this->get("/requirements/pds/{$record->id}")->assertNotFound();
});

it('saves usual deadlines', function () {
    $this->from('/requirements/deadlines')->put('/requirements/deadlines/usual', [
        'itr-month' => 3,
        'itr-day' => 31,
        'itr-next' => 'on',
        'pds-month' => 6,
        'pds-day' => 30,
    ])->assertRedirect('/requirements/deadlines');

    $settings = RequirementDeadlineSetting::current();

    expect($settings->itr)->toEqual(['month' => 3, 'day' => 31, 'next_year' => true])
        ->and($settings->sworn)->toEqual(['month' => null, 'day' => null, 'next_year' => false]);
});

it('refuses incomplete or impossible usual deadlines', function (array $form, string $message) {
    $this->from('/requirements/deadlines')->put('/requirements/deadlines/usual', $form)->assertSessionHasErrors(['form' => $message]);
})->with([
    [['itr-month' => 3], 'Income Tax Return (ITR): choose both the month and the day, or leave both empty.'],
    [['itr-month' => 2, 'itr-day' => 30], 'Income Tax Return (ITR): 2/30 is not a valid date.'],
]);

it('saves year-specific deadlines', function () {
    $this->from('/requirements/deadlines')->put('/requirements/deadlines/overrides', [
        'requirement' => ['itr-submissions', 'ipcr-ratings', ''],
        'period' => ['Jan-Jun', 'Jul-Dec', ''],
        'year' => ['2026', '2026', ''],
        'deadline' => ['2026-05-15', '2027-02-01', ''],
    ])->assertRedirect('/requirements/deadlines');

    $overrides = RequirementDeadlineSetting::current()->overrides;

    expect($overrides)->toHaveCount(2)
        ->and($overrides[0]['period'])->toBeNull()
        ->and($overrides[1]['period'])->toBe('Jul-Dec');

    $this->get('/requirements/deadlines')->assertSuccessful()->assertSee('2026-05-15');
});

it('refuses bad year-specific deadlines', function (array $form, string $message) {
    $this->from('/requirements/deadlines')->put('/requirements/deadlines/overrides', $form)->assertSessionHasErrors(['form' => $message]);
})->with([
    'no requirement' => [['requirement' => [''], 'year' => ['2026'], 'deadline' => ['2026-05-15']], 'Choose a requirement for every row.'],
    'bad year' => [['requirement' => ['itr-submissions'], 'year' => ['1999'], 'deadline' => ['2026-05-15']], 'Income Tax Return (ITR): enter the year (2000–2100).'],
    'bad date' => [['requirement' => ['itr-submissions'], 'year' => ['2026'], 'deadline' => ['']], 'Income Tax Return (ITR) 2026: pick the deadline date.'],
    'no period' => [['requirement' => ['ipcr-ratings'], 'year' => ['2026'], 'deadline' => ['2026-05-15']], 'IPCR 2026: choose the rating period.'],
    'duplicate' => [['requirement' => ['itr-submissions', 'itr-submissions'], 'year' => ['2026', '2026'], 'deadline' => ['2026-05-15', '2026-05-16']], 'Income Tax Return (ITR) 2026 has more than one deadline.'],
]);
