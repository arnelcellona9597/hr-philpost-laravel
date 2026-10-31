<?php

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveSetting;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Notification;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\WellnessLeave;
use App\Services\LeaveService;

beforeEach(fn () => signIn(User::factory()->create()));

function leaveForm(Employee $employee, array $overrides = []): array
{
    return [
        'employee' => $employee->id,
        'date_filing' => '2026-03-02',
        'inclusive_date_from' => '2026-03-09',
        'inclusive_date_to' => '2026-03-10',
        'status' => 'Approved',
        ...$overrides,
    ];
}

function fileLeave(Employee $employee, array $overrides = []): mixed
{
    return test()->from('/leave/new')->post('/leave', leaveForm($employee, $overrides));
}

it('files leave, counting working days and copying the name', function () {
    $employee = Employee::factory()->create(['last_name' => 'Reyes', 'first_name' => 'Ana']);

    fileLeave($employee)->assertRedirect('/leave')->assertSessionHas('flash.message', 'Wellness leave for Ana Reyes was saved (2 days).');

    $leave = WellnessLeave::query()->firstOrFail();

    expect($leave->days)->toBe(2)
        ->and($leave->year)->toBe(2026)
        ->and($leave->last_name)->toBe('Reyes')
        ->and($leave->days_by_year)->toEqual(['2026' => 2]);
});

it('skips weekends and holidays', function () {
    Holiday::factory()->create(['date' => '2026-03-10']);

    fileLeave(Employee::factory()->create(), ['inclusive_date_from' => '2026-03-06', 'inclusive_date_to' => '2026-03-10']);

    expect(WellnessLeave::query()->firstOrFail()->days)->toBe(2);
});

it('refuses dates with no working days', function () {
    fileLeave(Employee::factory()->create(), ['inclusive_date_from' => '2026-03-07', 'inclusive_date_to' => '2026-03-08'])
        ->assertSessionHasErrors(['form' => 'The selected dates contain no working days (weekends/holidays only).']);
});

it('refuses reversed dates and shows why on the page', function () {
    $this->from('/leave/new')->followingRedirects()->post('/leave', leaveForm(Employee::factory()->create(), ['inclusive_date_to' => '2026-03-01']))
        ->assertSee('The inclusive end date is before the start date.');
});

it('refuses an employee that does not exist', function () {
    $this->from('/leave/new')->post('/leave', ['employee' => 999, 'date_filing' => '2026-03-02', 'inclusive_date_from' => '2026-03-09', 'inclusive_date_to' => '2026-03-10', 'status' => 'Approved'])
        ->assertSessionHasErrors(['form' => 'Select an employee.']);
});

it('enforces the yearly allowance', function () {
    $employee = Employee::factory()->create();

    fileLeave($employee, ['inclusive_date_from' => '2026-03-02', 'inclusive_date_to' => '2026-03-06'])->assertSessionHasNoErrors();
    fileLeave($employee, ['inclusive_date_from' => '2026-04-06', 'inclusive_date_to' => '2026-04-06'])
        ->assertSessionHasErrors(['form' => 'Exceeds the 2026 wellness leave allowance: 0 of 5 day(s) left, this filing needs 1.']);
});

it('uses a year override for the allowance', function () {
    LeaveSetting::current()->update(['year_overrides' => [['year' => 2026, 'allowance' => 1]]]);

    fileLeave(Employee::factory()->create())->assertSessionHasErrors('form');
});

it('refuses overlapping filings', function () {
    $employee = Employee::factory()->create();
    fileLeave($employee);

    fileLeave($employee, ['inclusive_date_from' => '2026-03-10', 'inclusive_date_to' => '2026-03-11'])
        ->assertSessionHasErrors(['form' => 'These dates overlap another filing (2026-03-09 to 2026-03-10).']);
});

it('lets disapproved filings overlap and use no days', function () {
    $employee = Employee::factory()->create();
    fileLeave($employee);

    fileLeave($employee, ['status' => 'Disapproved'])->assertSessionHasNoErrors();

    expect(WellnessLeave::query()->count())->toBe(2);
});

it('charges a December to January filing to both years', function () {
    fileLeave(Employee::factory()->create(), ['inclusive_date_from' => '2025-12-30', 'inclusive_date_to' => '2026-01-02', 'date_filing' => '2025-12-01']);

    expect(WellnessLeave::query()->firstOrFail()->days_by_year)->toEqual(['2025' => 2, '2026' => 2]);
});

it('checks each year of a cross-year filing', function () {
    LeaveSetting::current()->update(['year_overrides' => [['year' => 2026, 'allowance' => 1]]]);

    fileLeave(Employee::factory()->create(), ['inclusive_date_from' => '2025-12-30', 'inclusive_date_to' => '2026-01-02'])
        ->assertSessionHasErrors(['form' => 'Exceeds the 2026 wellness leave allowance: 1 of 1 day(s) left, this filing needs 2.']);
});

it('excludes the filing itself when editing', function () {
    fileLeave($employee = Employee::factory()->create());
    $leave = WellnessLeave::query()->firstOrFail();

    $this->put("/leave/{$leave->id}", leaveForm($employee, ['remarks' => 'Updated']))->assertRedirect('/leave');

    expect($leave->fresh()->remarks)->toBe('Updated');
});

it('keeps stored days when a holiday is added later', function () {
    fileLeave(Employee::factory()->create());

    Holiday::factory()->create(['date' => '2026-03-09']);

    expect(WellnessLeave::query()->firstOrFail()->days)->toBe(2);
});

it('checks the received date', function () {
    fileLeave(Employee::factory()->create(), ['date_received' => '2026-03-01'])
        ->assertSessionHasErrors(['date_received' => 'Date received cannot be before the filing date']);
});

it('queues the filed email with frozen text and tells other staff', function () {
    $other = User::factory()->create();
    $employee = Employee::factory()->create(['email' => 'ana@example.test', 'first_name' => 'Ana']);

    fileLeave($employee);

    $recipient = MessageRecipient::query()->firstOrFail();

    expect($recipient->email)->toBe('ana@example.test')
        ->and($recipient->body)->toContain('Hi Ana,')->toContain('Mar 9, 2026 to Mar 10, 2026')->toContain('Approved')
        ->and(Notification::query()->where('user_id', $other->id)->firstOrFail()->link)->toBe('/leave/'.WellnessLeave::query()->first()->id)
        ->and(Notification::query()->where('user_id', auth()->id())->count())->toBe(0);
});

it('marks a recipient with no address as skipped', function () {
    fileLeave(Employee::factory()->create(['email' => null]));

    expect(MessageRecipient::query()->firstOrFail()->status->value)->toBe('skipped')
        ->and(Message::query()->firstOrFail()->status->value)->toBe('failed')
        ->and(WellnessLeave::query()->count())->toBe(1);
});

it('queues a status email only when the status changes', function () {
    fileLeave($employee = Employee::factory()->create(['email' => 'a@example.test']));
    $leave = WellnessLeave::query()->firstOrFail();

    $this->put("/leave/{$leave->id}", leaveForm($employee, ['remarks' => 'x']));
    expect(Message::query()->count())->toBe(1);

    $this->put("/leave/{$leave->id}", leaveForm($employee, ['status' => 'Cancelled']));
    expect(Message::query()->count())->toBe(2);
});

it('honours the email switches', function () {
    NotificationSetting::current()->update(['leave_filed_to_employee' => false]);

    fileLeave(Employee::factory()->create());

    expect(Message::query()->count())->toBe(0);
});

it('runs the balance in date order', function () {
    $employee = Employee::factory()->create();
    fileLeave($employee, ['inclusive_date_from' => '2026-03-02', 'inclusive_date_to' => '2026-03-03']);
    fileLeave($employee, ['inclusive_date_from' => '2026-04-06', 'inclusive_date_to' => '2026-04-06']);

    $leaves = WellnessLeave::query()->orderBy('inclusive_date_from')->get();
    $remaining = app(LeaveService::class)->remainingAfter($leaves);

    expect($remaining[$leaves[0]->id])->toBe(3)
        ->and($remaining[$leaves[1]->id])->toBe(2);
});

it('lists a year with figures and filters', function () {
    $this->travelTo('2026-05-01');
    $employee = Employee::factory()->create(['last_name' => 'Reyes']);
    fileLeave($employee);
    fileLeave($employee, ['inclusive_date_from' => '2026-05-04', 'inclusive_date_to' => '2026-05-04', 'status' => 'Cancelled']);

    $page = $this->get('/leave')->assertSuccessful()->assertSee('Reyes');
    $stats = $page->viewData('stats');

    expect($stats['byMonth'][3])->toBe(['filings' => 1, 'days' => 2])
        ->and($stats['byStatus']['Cancelled'])->toBe(1)
        ->and($stats['employeesWithLeave'])->toBe(1)
        ->and($stats['topUsers'][0]['days'])->toBe(2);

    expect($this->get('/leave?status=Cancelled')->viewData('leaves')->count())->toBe(1)
        ->and($this->get('/leave?q=nobody')->viewData('leaves')->count())->toBe(0)
        ->and($this->get('/leave?year=2025')->viewData('leaves')->count())->toBe(0)
        ->and($this->get("/leave?employee={$employee->id}")->viewData('leaves')->count())->toBe(2);
});

it('previews a filing as JSON', function () {
    $employee = Employee::factory()->create();

    $this->getJson("/leave/preview?employee={$employee->id}&from=2026-03-09&to=2026-03-10&status=Approved")
        ->assertSuccessful()
        ->assertJson(['ok' => true, 'days' => 2, 'years' => [['year' => 2026, 'allowance' => 5, 'usedBefore' => 0, 'thisFiling' => 2, 'remaining' => 3]]]);

    $this->getJson('/leave/preview?employee=0')->assertJson(['ok' => false, 'error' => 'Select an employee.']);
});

it('excludes the filing being edited from the preview', function () {
    fileLeave($employee = Employee::factory()->create());
    $leave = WellnessLeave::query()->firstOrFail();

    $this->getJson("/leave/preview?employee={$employee->id}&from=2026-03-09&to=2026-03-10")->assertJson(['ok' => false]);
    $this->getJson("/leave/preview?employee={$employee->id}&from=2026-03-09&to=2026-03-10&exclude={$leave->id}")->assertJson(['ok' => true]);
});

it('deletes a filing and returns the days', function () {
    fileLeave($employee = Employee::factory()->create());

    $this->delete('/leave/'.WellnessLeave::query()->firstOrFail()->id)->assertRedirect('/leave');

    expect(app(LeaveService::class)->usedInYear($employee, 2026))->toBe(0);
});

it('shows the forms', function () {
    $employee = Employee::factory()->create();
    fileLeave($employee);

    $this->get("/leave/new?employee={$employee->id}")->assertSuccessful()->assertSee('How days are counted');
    $this->get('/leave/'.WellnessLeave::query()->first()->id)->assertSuccessful()->assertSee('data-exclude', false);
});

it('offers only active employees when filing', function () {
    $inactive = Employee::factory()->create(['employment_status' => 'Resigned']);

    expect(collect($this->get('/leave/new')->viewData('employees'))->pluck('value'))->not->toContain($inactive->id);
});

it('saves the allowance with year overrides', function () {
    $this->from('/leave/settings')->put('/leave/settings/allowance', [
        'annual_allowance' => 8,
        'override_year' => ['2025', ''],
        'override_days' => ['3', ''],
    ])->assertRedirect('/leave/settings');

    $settings = LeaveSetting::current();

    expect($settings->annual_allowance)->toBe(8)
        ->and($settings->allowanceForYear(2025))->toBe(3);
});

it('refuses bad allowances', function () {
    $this->from('/leave/settings')->put('/leave/settings/allowance', ['annual_allowance' => 99])->assertSessionHasErrors('annual_allowance');
    $this->from('/leave/settings')->put('/leave/settings/allowance', ['annual_allowance' => 5, 'override_year' => ['2025', '2025'], 'override_days' => ['1', '2']])
        ->assertSessionHasErrors(['form' => 'Each year can only have one override.']);
});

it('manages holidays', function () {
    $this->from('/leave/settings')->post('/leave/settings/holidays', ['date' => '2026-12-25', 'name' => 'Christmas', 'type' => 'Regular Holiday'])->assertRedirect('/leave/settings');

    $holiday = Holiday::query()->firstOrFail();
    $this->get('/leave/settings?year=2026')->assertSee('Christmas');

    $this->from('/leave/settings')->post('/leave/settings/holidays', ['date' => '2026-12-25', 'name' => 'Again', 'type' => 'Regular Holiday'])
        ->assertSessionHasErrors(['date' => 'That date is already in the holiday list.']);

    $this->from('/leave/settings')->delete("/leave/settings/holidays/{$holiday->id}")->assertRedirect('/leave/settings');

    expect(Holiday::query()->count())->toBe(0);
});

it('stores the status as a real enum', function () {
    fileLeave(Employee::factory()->create(), ['status' => 'Pending']);

    expect(WellnessLeave::query()->firstOrFail()->status)->toBe(LeaveStatus::Pending);
});
