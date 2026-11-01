<?php

use App\Enums\ApplicationStatus;
use App\Enums\RecipientStatus;
use App\Enums\RequirementsCompletion;
use App\Enums\RequirementStatus;
use App\Models\Application;
use App\Models\Branch;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\ApplicationService;
use App\Services\MessageQueue;

beforeEach(fn () => signIn(User::factory()->create()));

function applicantForm(array $overrides = []): array
{
    return ['applicant_name' => 'Ana Reyes', 'position_applied' => 'Clerk', 'date_applied' => '2026-06-01', 'application_status' => 'Pending', ...$overrides];
}

it('numbers a blank application ID on save, not on viewing the form', function () {
    $this->travelTo('2026-06-15');
    $this->get('/onboarding/new')->assertSuccessful();
    $this->get('/onboarding/new')->assertSuccessful();

    $this->post('/onboarding', applicantForm())->assertRedirect('/onboarding');
    $this->post('/onboarding', applicantForm(['applicant_name' => 'Ben Santos']))->assertRedirect('/onboarding');

    expect(Application::query()->orderBy('id')->pluck('application_id')->all())->toBe(['APP-2026-001', 'APP-2026-002']);
});

it('never reuses a number after a delete', function () {
    $this->travelTo('2026-06-15');
    $this->post('/onboarding', applicantForm());

    $this->delete('/onboarding/'.Application::query()->firstOrFail()->id);
    $this->post('/onboarding', applicantForm());

    expect(Application::query()->firstOrFail()->application_id)->toBe('APP-2026-002');
});

it('keeps a typed application ID and refuses duplicates', function () {
    $this->post('/onboarding', applicantForm(['application_id' => 'OLD-1']));

    expect(Application::query()->firstOrFail()->application_id)->toBe('OLD-1');

    $this->from('/onboarding/new')->post('/onboarding', applicantForm(['application_id' => 'OLD-1']))
        ->assertSessionHasErrors(['application_id' => 'That Application ID is already used.']);
});

it('checks the dates', function () {
    $this->from('/onboarding/new')->post('/onboarding', applicantForm(['start_ojt' => '2026-05-01']))
        ->assertSessionHasErrors(['start_ojt' => 'Start OJT cannot be before the date applied']);
});

it('lists applicants with status counts and movement', function () {
    Application::factory()->create(['applicant_name' => 'Ana Reyes']);
    Application::factory()->hired()->create();
    Employee::factory()->create(['employment_status' => 'Resigned', 'last_day_of_service' => now()->toDateString()]);
    Employee::factory()->create(['date_hired' => now()->subDays(5)->toDateString()]);

    $page = $this->get('/onboarding')->assertSuccessful()->assertSee('Ana Reyes');

    expect((int) $page->viewData('statusCounts')['Hired'])->toBe(1)
        ->and((int) $page->viewData('separated')['Resigned'])->toBe(1)
        ->and($page->viewData('newHireCount'))->toBeGreaterThanOrEqual(1);
});

it('filters and searches', function () {
    $branch = Branch::factory()->create();
    Application::factory()->for($branch, 'station')->create(['applicant_name' => 'Ana', 'replacement' => 'Ben']);
    Application::factory()->hired()->create(['applicant_name' => 'Cara']);

    $names = fn (string $query) => $this->get("/onboarding?{$query}")->viewData('applications')->pluck('applicant_name')->all();

    expect($names('q=ben'))->toBe(['Ana'])
        ->and($names('status=Hired'))->toBe(['Cara'])
        ->and($names('requirements=Complete'))->toBe([])
        ->and($names("station={$branch->id}"))->toBe(['Ana']);
});

it('updates one requirement inline', function () {
    $application = Application::factory()->create();

    $this->postJson("/onboarding/{$application->id}/requirement", ['key' => 'req_pds', 'value' => 'Verified'])
        ->assertSuccessful()
        ->assertJson(['requirementsStatus' => 'Incomplete', 'requirementsMissing' => 9]);

    expect($application->fresh()->req_pds)->toBe(RequirementStatus::Verified);
});

it('refuses other columns through the inline endpoint', function () {
    $application = Application::factory()->create();

    $this->postJson("/onboarding/{$application->id}/requirement", ['key' => 'application_status', 'value' => 'Hired'])->assertUnprocessable();
    $this->postJson("/onboarding/{$application->id}/requirement", ['key' => 'req_pds', 'value' => 'Nope'])->assertUnprocessable();

    expect($application->fresh()->application_status)->toBe(ApplicationStatus::Pending);
});

it('queues the email once when an applicant is hired', function () {
    $application = Application::factory()->create(['email' => 'ANA@example.test', 'applicant_name' => 'Ana Reyes']);

    $this->put("/onboarding/{$application->id}", applicantForm(['application_status' => 'Hired', 'email' => 'ana@example.test']));

    $recipient = MessageRecipient::query()->firstOrFail();

    expect($recipient->email)->toBe('ana@example.test')
        ->and($recipient->status)->toBe(RecipientStatus::Queued)
        ->and($recipient->body)->toContain('Hi Ana,')->toContain('• PDS')
        ->and($recipient->message->automatic)->toBeTrue()
        ->and($application->fresh()->requirements_email_sent_at)->not->toBeNull()
        ->and($application->fresh()->requirements_email_to)->toBe('ana@example.test');

    $this->put("/onboarding/{$application->id}", applicantForm(['application_status' => 'Hired', 'remarks' => 'x', 'email' => 'ana@example.test']));

    expect(Message::query()->count())->toBe(1);
});

it('queues the email when created as hired', function () {
    $this->post('/onboarding', applicantForm(['application_status' => 'Hired', 'email' => 'ana@example.test']));

    expect(Message::query()->count())->toBe(1);
});

it('sends once an address appears after the hire', function () {
    $application = Application::factory()->hired()->create(['email' => null]);

    $this->put("/onboarding/{$application->id}", applicantForm(['application_status' => 'Hired', 'email' => '']));
    expect(Message::query()->count())->toBe(0);

    $this->put("/onboarding/{$application->id}", applicantForm(['application_status' => 'Hired', 'email' => 'ana@example.test']));
    expect(Message::query()->count())->toBe(1);
});

it('falls back to the linked employee address', function () {
    $application = Application::factory()->for(Employee::factory()->state(['email' => 'emp@example.test']))->create(['email' => null]);

    expect(app(ApplicationService::class)->emailAddress($application))->toBe('emp@example.test');
});

it('respects the setting', function () {
    NotificationSetting::current()->update(['hired_requirements_to_applicant' => false]);

    $this->post('/onboarding', applicantForm(['application_status' => 'Hired', 'email' => 'ana@example.test']));

    expect(Message::query()->count())->toBe(0);
});

it('uses the saved template when there is one', function () {
    EmailTemplate::query()->where('key', 'hired-requirements')->update(['subject' => 'Welcome {{firstName}}', 'body' => '{{requirementsList}}']);

    $this->post('/onboarding', applicantForm(['application_status' => 'Hired', 'email' => 'ana@example.test']));

    expect(MessageRecipient::query()->firstOrFail()->subject)->toBe('Welcome Ana');
});

it('resends by hand and explains a missing address', function () {
    $application = Application::factory()->create(['email' => null]);

    $this->post("/onboarding/{$application->id}/send-requirements")->assertSessionHas('flash.type', 'error');

    $application->update(['email' => 'ana@example.test']);

    $this->post("/onboarding/{$application->id}/send-requirements")->assertSessionHas('flash.message', 'Requirements email queued for ana@example.test.');

    expect(Message::query()->firstOrFail()->automatic)->toBeFalse();
});

it('never blocks a save when queueing fails', function () {
    $this->mock(MessageQueue::class, fn ($mock) => $mock->shouldReceive('template')->andThrow(new RuntimeException('boom')));

    $this->post('/onboarding', applicantForm(['application_status' => 'Hired', 'email' => 'ana@example.test']))->assertRedirect('/onboarding');

    expect(Application::query()->count())->toBe(1);
});

it('shows the applicant page in each email state', function () {
    $application = Application::factory()->create(['email' => null]);

    $this->get("/onboarding/{$application->id}")->assertSuccessful()->assertSee("Can't be sent yet", false)->assertSee('Convert to employee');

    $application->update(['email' => 'ana@example.test']);
    $this->get("/onboarding/{$application->id}")->assertSee('when the status becomes Hired');
});

it('counts requirements on the applicant page', function () {
    $application = Application::factory()->create();
    $application->update(['req_pds' => RequirementStatus::NotApplicable]);

    $this->get("/onboarding/{$application->id}")->assertSee('Requirements still needed (9)');

    expect($application->fresh()->requirements_status)->toBe(RequirementsCompletion::Incomplete);
});

it('deletes an application', function () {
    $application = Application::factory()->create();

    $this->delete("/onboarding/{$application->id}")->assertRedirect('/onboarding');

    expect($application->fresh())->toBeNull();
});

it('keeps the FDS date apart from the requirements status', function () {
    Application::factory()->create(['applicant_name' => 'Ana Reyes', 'fds_date' => '2026-07-15']);

    $this->get('/onboarding')->assertSuccessful()->assertSeeInOrder(['>FDS', 'Requirements'], false)->assertDontSee('FDS Status')->assertSee('Jul 15, 2026');
});

it('saves the FDS date and checks it against the date applied', function () {
    $this->post('/onboarding', applicantForm(['fds_date' => '2026-07-01']))->assertRedirect('/onboarding');

    expect(Application::query()->firstOrFail()->fds_date->toDateString())->toBe('2026-07-01');

    $this->from('/onboarding/new')->post('/onboarding', applicantForm(['fds_date' => '2026-05-01']))
        ->assertSessionHasErrors(['fds_date' => 'The FDS date cannot be before the date applied']);
});

it('filters by FDS date range and by position', function () {
    Application::factory()->create(['applicant_name' => 'Early Bird', 'position_applied' => 'Clerk', 'fds_date' => '2026-07-01']);
    Application::factory()->create(['applicant_name' => 'Mid Month', 'position_applied' => 'Driver', 'fds_date' => '2026-08-15']);
    Application::factory()->create(['applicant_name' => 'No Date', 'position_applied' => 'Clerk', 'fds_date' => null]);

    $this->get('/onboarding?fdsFrom=2026-08-01&fdsTo=2026-08-31')->assertSee('Mid Month')->assertDontSee('Early Bird')->assertDontSee('No Date');
    $this->get('/onboarding?fdsFrom=2026-07-01')->assertSee('Early Bird')->assertSee('Mid Month')->assertDontSee('No Date');
    $this->get('/onboarding?position=Clerk')->assertSee('Early Bird')->assertSee('No Date')->assertDontSee('Mid Month');
    $this->get('/onboarding?position=Clerk&fdsTo=2026-07-31')->assertSee('Early Bird')->assertDontSee('No Date');
    $this->get('/onboarding?fdsFrom=not-a-date')->assertSuccessful()->assertSee('Mid Month')->assertSee('No Date');
});

it('hires on the FDS date when converting an applicant', function () {
    $application = Application::factory()->create(['applicant_name' => 'Maria Santos', 'start_ojt' => '2026-06-10', 'fds_date' => '2026-07-01']);

    $values = $this->get("/employees/new?fromApplication={$application->id}")->assertSuccessful()->viewData('values');

    expect($values['date_hired'])->toBe('2026-07-01');
});

it('does not add a second applicant with the same name', function (string $second) {
    $existing = Application::factory()->create(['applicant_name' => 'Ana Reyes']);

    $this->from('/onboarding/new')->post('/onboarding', applicantForm(['applicant_name' => $second]))
        ->assertRedirect('/onboarding/new')
        ->assertSessionHasErrors(['applicant_name' => "An applicant named Ana Reyes is already listed ({$existing->application_id}). Open that application instead of adding a second one."]);

    expect(Application::query()->count())->toBe(1);
})->with(['same' => 'Ana Reyes', 'capitals' => 'ANA REYES', 'extra spaces' => '  ana   reyes ']);

it('lets an applicant be edited without clashing with itself, but not renamed to another', function () {
    $ana = Application::factory()->create(['applicant_name' => 'Ana Reyes']);
    $ben = Application::factory()->create(['applicant_name' => 'Ben Santos']);

    $this->put("/onboarding/{$ana->id}", applicantForm(['application_id' => $ana->application_id, 'applicant_name' => 'Ana Reyes', 'position_applied' => 'Driver']))->assertSessionHasNoErrors();
    expect($ana->fresh()->position_applied)->toBe('Driver');

    $this->from("/onboarding/{$ben->id}/edit")->put("/onboarding/{$ben->id}", applicantForm(['application_id' => $ben->application_id, 'applicant_name' => 'ana reyes']))
        ->assertSessionHasErrors('applicant_name');
    expect($ben->fresh()->applicant_name)->toBe('Ben Santos');
});
