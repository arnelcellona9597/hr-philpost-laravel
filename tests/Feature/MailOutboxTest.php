<?php

use App\Enums\MessageCategory;
use App\Enums\MessageStatus;
use App\Enums\RecipientStatus;
use App\Mail\SystemMessage;
use App\Models\Employee;
use App\Models\Media;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Notification;
use App\Models\NotificationSetting;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\User;
use App\Services\DirectMailer;
use App\Services\MailOutbox;
use App\Services\MessageQueue;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

function queueTo(array $emails, array $template = ['subject' => 'Hello', 'body' => 'Hi {{firstName}}']): Message
{
    return app(MessageQueue::class)->queueMany(
        $template,
        MessageCategory::General,
        array_map(fn (string $email): array => ['email' => $email], $emails),
        'Test',
        null,
        null,
    );
}

function failingMailer(): void
{
    app()->instance(DirectMailer::class, new class extends DirectMailer
    {
        public function isConfigured(): bool
        {
            return true;
        }

        public function deliver(string $to, string $subject, string $body, ?string $senderName = null, array $files = []): void
        {
            throw new RuntimeException('Connection refused');
        }
    });
}

beforeEach(fn () => Mail::fake());

it('sends queued mail, marks it sent and rolls up the message', function () {
    $message = queueTo(['a@example.test', 'b@example.test']);

    $result = app(MailOutbox::class)->drain();

    expect($result)->toBe(['sent' => 2, 'failed' => 0, 'remaining' => 0])
        ->and($message->fresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->fresh()->sent)->toBe(2)
        ->and(MessageRecipient::query()->where('status', RecipientStatus::Sent)->whereNotNull('sent_at')->count())->toBe(2);

    Mail::assertSent(SystemMessage::class, 2);
});

it('freezes each recipient text when queued', function () {
    $employee = Employee::factory()->create(['first_name' => 'Ana', 'email' => 'ana@example.test']);

    app(MessageQueue::class)->queueMany(['subject' => 'Hi {{firstName}}', 'body' => 'Dear {{firstName}}'], MessageCategory::General, [['employee' => $employee]], 'One', null, null);
    $employee->update(['first_name' => 'Changed']);

    app(MailOutbox::class)->drain();

    Mail::assertSent(SystemMessage::class, fn (SystemMessage $mail) => $mail->subjectLine === 'Hi Ana' && $mail->bodyText === 'Dear Ana');
});

it('stops at the rolling hourly limit and carries on later', function () {
    NotificationSetting::current()->update(['hourly_limit' => 2]);
    queueTo(['a@example.test', 'b@example.test', 'c@example.test']);

    $result = app(MailOutbox::class)->drain();

    expect($result['sent'])->toBe(2)->and($result['remaining'])->toBe(1);

    $this->travel(61)->minutes();

    expect(app(MailOutbox::class)->drain()['sent'])->toBe(1);
});

it('sends at most one batch at a time', function () {
    queueTo(array_map(fn (int $number): string => "p{$number}@example.test", range(1, 30)));

    expect(app(MailOutbox::class)->processBatch())->toBe(['sent' => 25, 'failed' => 0, 'remaining' => 5]);
});

it('retries after 2 then 4 minutes and gives up after 3 attempts', function () {
    failingMailer();
    $message = queueTo(['a@example.test']);
    $row = $message->recipients()->sole();
    $outbox = app(MailOutbox::class);

    $outbox->drain();
    expect($row->fresh()->status)->toBe(RecipientStatus::Queued)
        ->and($row->fresh()->attempts)->toBe(1)
        ->and($row->fresh()->error)->toBe('Connection refused');

    expect($outbox->drain()['sent'])->toBe(0)->and($row->fresh()->attempts)->toBe(1);

    $this->travel(2)->minutes();
    $outbox->drain();
    expect($row->fresh()->attempts)->toBe(2);

    $this->travel(4)->minutes();
    $outbox->drain();
    expect($row->fresh()->status)->toBe(RecipientStatus::Failed)
        ->and($message->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->fresh()->failed)->toBe(1);
});

it('tells System Admins once when a delivery gives up', function () {
    failingMailer();
    $admin = User::factory()->systemAdmin()->create();
    User::factory()->create();
    $message = queueTo(['a@example.test']);
    $message->recipients()->update(['attempts' => 2]);

    app(MailOutbox::class)->drain();
    $outbox = app(MailOutbox::class);
    $outbox->retryFailed($message);
    $message->recipients()->update(['attempts' => 2]);
    $outbox->drain();

    expect(Notification::query()->sole()->user_id)->toBe($admin->id)
        ->and(Notification::query()->sole()->link)->toBe("/messages/{$message->id}");
});

it('rolls a message up as partial when only some are delivered', function () {
    $message = Message::factory()->create();
    MessageRecipient::factory()->create(['message_id' => $message->id])->forceFill(['status' => RecipientStatus::Sent])->save();
    MessageRecipient::factory()->create(['message_id' => $message->id])->forceFill(['status' => RecipientStatus::Failed])->save();

    app(MailOutbox::class)->rollUp($message);

    expect($message->fresh()->status)->toBe(MessageStatus::Partial);
});

it('releases rows stuck in sending for over ten minutes', function () {
    $row = queueTo(['a@example.test'])->recipients()->sole();
    $row->forceFill(['status' => RecipientStatus::Sending])->save();

    expect(app(MailOutbox::class)->drain()['sent'])->toBe(0);

    $this->travel(11)->minutes();

    expect(app(MailOutbox::class)->drain()['sent'])->toBe(1);
});

it('does not use up attempts while the mail server is not configured', function () {
    $mailer = Mockery::mock(DirectMailer::class);
    $mailer->shouldReceive('isConfigured')->andReturnFalse();
    $mailer->shouldNotReceive('deliver');
    app()->instance(DirectMailer::class, $mailer);

    $row = queueTo(['a@example.test'])->recipients()->sole();

    expect(app(MailOutbox::class)->drain()['remaining'])->toBe(1)
        ->and($row->fresh()->attempts)->toBe(0);
});

it('attaches the payslip PDF at send time', function () {
    $employee = Employee::factory()->create(['email' => 'ana@example.test']);
    $period = PayrollPeriod::factory()->create(['name' => 'Oct A']);
    Payslip::factory()->create(['period_id' => $period->id, 'employee_id' => $employee->id, 'employee_code' => 'EMP-1']);
    app(PayrollService::class)->release($period, User::factory()->create(), true);

    app(MailOutbox::class)->drain();

    Mail::assertSent(SystemMessage::class, fn (SystemMessage $mail) => $mail->subjectLine === 'Your payslip for Oct A'
        && count($mail->files) === 1
        && str_starts_with($mail->files[0]['bytes'], '%PDF-')
        && str_contains($mail->files[0]['name'], 'EMP-1'));
});

it('attaches the files of a message', function () {
    Storage::fake('local');
    Storage::disk('local')->put('media/note.pdf', '%PDF-1 note');
    $media = Media::factory()->create(['path' => 'media/note.pdf', 'filename' => 'note.pdf', 'mime_type' => 'application/pdf']);
    $message = queueTo(['a@example.test']);
    $message->attachments()->attach($media);

    app(MailOutbox::class)->drain();

    Mail::assertSent(SystemMessage::class, fn (SystemMessage $mail) => $mail->files[0]['name'] === 'note.pdf');
});

it('sends one email per address, except payslips', function () {
    $first = Employee::factory()->create(['email' => 'Shared@example.test']);
    $second = Employee::factory()->create(['email' => 'shared@example.test']);

    $plain = app(MessageQueue::class)->prepare(['subject' => 'S', 'body' => 'B'], [['employee' => $first], ['employee' => $second]]);
    $payslips = app(MessageQueue::class)->prepare(['subject' => 'S', 'body' => 'B'], [['employee' => $first, 'payslip_id' => 1], ['employee' => $second, 'payslip_id' => 2]]);

    expect($plain)->toHaveCount(1)->and($payslips)->toHaveCount(2);
});

it('has a command and a keyed endpoint that drain the queue', function () {
    queueTo(['a@example.test']);
    $this->artisan('hr:send-mail')->assertSuccessful();
    Mail::assertSent(SystemMessage::class, 1);

    queueTo(['b@example.test']);
    $key = NotificationSetting::current()->queue_key;

    $this->get('/internal/queue')->assertForbidden();
    $this->withHeader('X-Queue-Key', 'wrong')->get('/internal/queue')->assertForbidden();
    $this->withHeader('X-Queue-Key', $key)->get('/internal/queue')->assertOk()->assertJson(['sent' => 1, 'remaining' => 0]);
});

it('runs the mail command from the scheduler', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('hr:send-mail')->assertSuccessful();
});
