<?php

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\MessageCategory;
use App\Enums\RecipientStatus;
use App\Mail\SystemMessage;
use App\Models\Branch;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\Message;
use App\Models\User;
use App\Services\MailOutbox;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

function compose(array $overrides = []): array
{
    return ['subject' => 'Notice', 'body' => 'Hi {{firstName}}', 'category' => 'Announcement', 'emails' => 'one@example.test', ...$overrides];
}

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    $this->hr = User::factory()->create(['name' => 'Rita Santos']);
    signIn($this->hr);
});

it('queues a message to chosen employees, groups, users and addresses', function () {
    $branch = Branch::factory()->create();
    $chosen = Employee::factory()->create(['first_name' => 'Ana', 'email' => 'ana@example.test']);
    Employee::factory()->create(['email' => 'group@example.test', 'station_id' => $branch->id, 'classification' => Classification::Regular, 'employment_status' => EmploymentStatus::Active]);
    Employee::factory()->create(['email' => 'nomatch@example.test', 'station_id' => $branch->id, 'classification' => Classification::Cos, 'employment_status' => EmploymentStatus::Active]);
    $user = User::factory()->create(['email' => 'hr@example.test']);

    $this->post('/messages', compose([
        'employees' => [$chosen->id],
        'group_branch' => [$branch->id],
        'group_classification' => ['Regular'],
        'users' => [$user->id],
    ]))->assertRedirect();

    $message = Message::query()->sole();

    expect($message->recipients()->pluck('email')->sort()->values()->all())->toBe(['ana@example.test', 'group@example.test', 'hr@example.test', 'one@example.test'])
        ->and($message->automatic)->toBeFalse()
        ->and($message->sent_by_name)->toBe('Rita Santos')
        ->and($message->audience)->toContain('1 selected employee(s)')->toContain('1 HR user(s)')->toContain('1 other address(es)')
        ->and($message->recipients()->where('email', 'ana@example.test')->sole()->body)->toBe('Hi Ana');
});

it('records people with no address as skipped', function () {
    $employee = Employee::factory()->create(['email' => null, 'first_name' => 'Ben', 'last_name' => 'Cruz']);

    $this->post('/messages', compose(['employees' => [$employee->id]]));

    $row = Message::query()->sole()->recipients()->where('name', 'Ben Cruz')->sole();

    expect($row->status)->toBe(RecipientStatus::Skipped)->and($row->error)->toBe('No email address on file');
});

it('refuses what cannot be sent', function (array $overrides, string $message) {
    $this->post('/messages', compose($overrides))->assertSessionHasErrors(['form' => $message]);

    expect(Message::query()->count())->toBe(0);
})->with([
    'no subject' => [['subject' => ''], 'Enter a subject.'],
    'no body' => [['body' => ' '], 'Write the message.'],
    'no recipient' => [['emails' => ''], 'Choose at least one recipient.'],
    'bad address' => [['emails' => 'nope'], '"nope" is not a valid email address.'],
    'unknown placeholder' => [['body' => '{{salary}}'], 'Unknown placeholder(s): {{salary}}'],
    'automatic-only placeholder' => [['body' => '{{netPay}}'], 'Unknown placeholder(s): {{netPay}} ({{netPay}} only work in automatic emails)'],
]);

it('accepts placeholders carried by a reminder link', function () {
    $this->post('/messages', compose(['body' => 'Your {{requirement}} for {{year}}', 'ctx_requirement' => 'ITR', 'ctx_year' => '2026']))->assertRedirect();

    expect(Message::query()->sole()->recipients()->sole()->body)->toBe('Your ITR for 2026');
});

it('stores checked attachments and removes them when the message fails', function () {
    $pdf = UploadedFile::fake()->createWithContent('note.pdf', "%PDF-1.4\n%%EOF\n");

    $this->post('/messages', compose(['attachments' => [$pdf]]))->assertRedirect();
    expect(Message::query()->sole()->attachments()->sole()->filename)->toBe('note.pdf');

    $this->post('/messages', compose(['attachments' => [UploadedFile::fake()->createWithContent('page.html', '<html>')]]))->assertSessionHasErrors('form');
    $this->post('/messages', compose(['attachments' => [UploadedFile::fake()->create('big.pdf', 6000)]]))->assertSessionHasErrors('form');
});

it('previews the first deliverable recipient without saving', function () {
    Employee::factory()->create(['email' => null, 'first_name' => 'Ben']);
    $employee = Employee::factory()->create(['first_name' => '<b>Ana</b>', 'email' => 'ana@example.test']);
    $ids = Employee::query()->pluck('id')->all();

    $this->post('/messages/preview', compose(['employees' => $ids, 'emails' => '']))
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('deliverable', 1)
        ->assertJsonPath('sample.to', 'ana@example.test')
        ->assertJsonPath('skipped.0.reason', 'No email address on file');

    $html = $this->post('/messages/preview', compose(['employees' => [$employee->id], 'emails' => '']))->json('sample.html');

    expect(mb_strtolower($html))->toContain('&lt;b&gt;ana&lt;/b&gt;')->not->toContain('<b>ana')
        ->and(Message::query()->count())->toBe(0);

    $this->post('/messages/preview', compose(['subject' => '']))->assertStatus(400)->assertJsonPath('error', 'Enter a subject.');
});

it('sends written messages with the sender named and retries failures', function () {
    $this->post('/messages', compose());
    $message = Message::query()->sole();

    $this->post("/messages/{$message->id}/process")->assertRedirect();

    Mail::assertSent(SystemMessage::class, fn ($mail) => $mail->senderName === 'Rita Santos');

    $row = $message->recipients()->sole();
    $row->forceFill(['status' => RecipientStatus::Failed])->save();
    app(MailOutbox::class)->rollUp($message);

    $this->post("/messages/{$message->id}/retry")->assertSessionHas('flash.message', '1 failed email(s) put back in the queue.');

    expect($row->fresh()->status)->toBe(RecipientStatus::Queued);
});

it('shows the message pages', function () {
    $template = EmailTemplate::query()->where('key', 'requirement-reminder')->sole();
    $employee = Employee::factory()->create();
    $message = Message::factory()->create(['subject' => 'Payday <soon>', 'category' => MessageCategory::Payroll]);

    $this->get('/messages')->assertOk()->assertSee('Payday &lt;soon&gt;', false);
    $this->get('/messages?category=Leave')->assertOk()->assertDontSee('Payday');
    $this->get('/messages?kind=automatic&q=nothing')->assertOk()->assertSee('No messages yet');
    $this->get("/messages/{$message->id}")->assertOk()->assertSee('Recipients');
    $this->get("/messages/new?template=requirement-reminder&employees={$employee->id}&ctx_requirement=ITR&ctx_year=2026")
        ->assertOk()
        ->assertSee($template->subject)
        ->assertSee('name="ctx_requirement"', false)
        ->assertSee('{{requirement}}');
});
