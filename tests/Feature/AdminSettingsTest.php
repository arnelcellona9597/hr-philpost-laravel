<?php

use App\Enums\ThemeMode;
use App\Mail\SystemMessage;
use App\Models\AuditLog;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\Media;
use App\Models\MessageRecipient;
use App\Models\NotificationSetting;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\SiteSetting;
use App\Models\SmtpSetting;
use App\Models\User;
use App\Services\DirectMailer;
use App\Services\PayrollService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

function brandingForm(array $overrides = []): array
{
    return [...SiteSetting::DEFAULT_PALETTE, 'company_name' => 'PhilPost HR', 'default_theme' => 'dark', ...$overrides];
}

function smtpForm(array $overrides = []): array
{
    return ['host' => 'mail.example.test', 'port' => 465, 'security' => 'ssl', 'username' => 'hr', 'from_address' => 'hr@example.test', 'from_name' => 'HR', ...$overrides];
}

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    $this->hr = User::factory()->create();
    $this->admin = User::factory()->systemAdmin()->create();
    signIn($this->hr);
});

it('saves branding and rejects bad colors', function () {
    $this->put('/settings', brandingForm())->assertSessionHasNoErrors();

    $settings = SiteSetting::current();

    expect($settings->company_name)->toBe('PhilPost HR')->and($settings->default_theme)->toBe(ThemeMode::Dark);

    $this->put('/settings', brandingForm(['primary' => 'blue']))->assertSessionHasErrors(['form' => 'Use hex colors like #1d4ed8 for: primary.']);
    $this->put('/settings', brandingForm(['company_name' => '']))->assertSessionHasErrors('company_name');
    $this->get('/settings')->assertOk()->assertSee('Civil Blue');
});

it('makes the logo public, refuses SVG and removes the old file', function () {
    $this->put('/settings', brandingForm(['logo' => UploadedFile::fake()->image('logo.png')]))->assertSessionHasNoErrors();

    $logo = SiteSetting::current()->logo;

    expect($logo->is_public)->toBeTrue();
    Storage::disk('local')->assertExists($logo->path);

    $this->put('/settings', brandingForm(['logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')]))->assertSessionHasErrors('form');

    $this->put('/settings', brandingForm(['remove_logo' => '1']))->assertSessionHasNoErrors();

    expect(SiteSetting::current()->logo_id)->toBeNull()->and(Media::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($logo->path);
});

it('saves the email settings within their limits', function () {
    $this->put('/settings/notifications', ['hourly_limit' => 250, 'reply_to' => 'hr@example.test', 'footer' => 'Bye', 'leave_filed_to_employee' => '1'])->assertSessionHasNoErrors();

    $settings = NotificationSetting::current();

    expect($settings->hourly_limit)->toBe(250)
        ->and($settings->leave_filed_to_employee)->toBeTrue()
        ->and($settings->leave_status_to_employee)->toBeFalse();

    $this->put('/settings/notifications', ['hourly_limit' => 0])->assertSessionHasErrors('hourly_limit');
    $this->put('/settings/notifications', ['hourly_limit' => 10, 'reply_to' => 'nope'])->assertSessionHasErrors('reply_to');
});

it('shows the cron key to System Admins only and lets only them rotate it', function () {
    $key = NotificationSetting::current()->queue_key;

    $this->get('/settings/notifications')->assertOk()->assertDontSee($key);
    $this->post('/settings/notifications/rotate-key')->assertForbidden();

    signIn($this->admin);

    $this->get('/settings/notifications')->assertOk()->assertSee($key);
    $this->post('/settings/notifications/rotate-key')->assertRedirect();

    expect(NotificationSetting::current()->queue_key)->not->toBe($key);
});

it('hides the mail server error text from HR Staff', function () {
    NotificationSetting::current()->forceFill(['smtp_last_error' => 'secret host unreachable', 'smtp_last_error_at' => now()])->save();

    $this->get('/settings/notifications')->assertOk()->assertDontSee('secret host');

    signIn($this->admin);

    $this->get('/settings/notifications')->assertOk()->assertSee('secret host');
});

it('sends a test email only to the person asking', function () {
    $this->post('/settings/notifications/test', ['to' => 'other@example.test'])->assertRedirect();

    Mail::assertSent(SystemMessage::class, fn ($mail) => $mail->hasTo($this->hr->email));
    Mail::assertNotSent(SystemMessage::class, fn ($mail) => $mail->hasTo('other@example.test'));
});

it('edits templates, protects keyed ones and rejects unknown placeholders', function () {
    $keyed = EmailTemplate::query()->where('key', 'payroll-released')->sole();

    expect(EmailTemplate::query()->whereNotNull('key')->count())->toBe(5)
        ->and(EmailTemplate::query()->whereNull('key')->count())->toBe(2);

    $this->put("/settings/templates/{$keyed->id}", ['name' => $keyed->name, 'category' => 'Payroll', 'subject' => 'Pay {{payPeriod}}', 'body' => 'Net {{netPay}}'])->assertSessionHasNoErrors();
    expect($keyed->fresh()->subject)->toBe('Pay {{payPeriod}}');

    $this->delete("/settings/templates/{$keyed->id}")->assertSessionHas('flash.type', 'error');
    expect($keyed->fresh())->not->toBeNull();

    $this->post('/settings/templates', ['name' => 'Mine', 'category' => 'General', 'subject' => 'Hi', 'body' => '{{netPay}}'])->assertSessionHasErrors('body');
    $this->post('/settings/templates', ['name' => $keyed->name, 'category' => 'General', 'subject' => 'Hi', 'body' => 'Text'])->assertSessionHasErrors('name');
    $this->post('/settings/templates', ['name' => 'Mine', 'category' => 'General', 'subject' => 'Hi {{firstName}}', 'body' => 'Text'])->assertSessionHasNoErrors();

    $mine = EmailTemplate::query()->where('name', 'Mine')->sole();

    $this->get("/settings/templates/{$mine->id}")->assertOk()->assertDontSee('netPay');
    $this->get("/settings/templates/{$keyed->id}")->assertOk()->assertSee('netPay');
    $this->delete("/settings/templates/{$mine->id}")->assertRedirect('/settings/notifications');
    expect($mine->fresh())->toBeNull();
});

it('uses the keyed wording for automatic mail', function () {
    $employee = Employee::factory()->create(['email' => 'ana@example.test']);
    $period = PayrollPeriod::factory()->create(['name' => 'Oct A']);
    Payslip::factory()->create(['period_id' => $period->id, 'employee_id' => $employee->id]);

    app(PayrollService::class)->release($period, $this->hr, true);

    expect(MessageRecipient::query()->sole()->body)->toContain('Net pay: ₱23,749.50');
});

it('does not claim the mail server works when real email is switched off', function () {
    signIn($this->admin);

    $this->put('/settings/email-server', smtpForm())->assertSessionHas('flash.type', 'error');
    $this->post('/settings/email-server/test', ['to' => 'x@example.test'])->assertSessionHasErrors('form');
    $this->post('/settings/notifications/test')->assertSessionHas('flash.type', 'info');
});

it('keeps the email server page to System Admins and the password out of reach', function () {
    $this->get('/settings/email-server')->assertRedirect('/?denied=1');
    $this->put('/settings/email-server', smtpForm())->assertRedirect('/?denied=1');

    signIn($this->admin);

    $this->put('/settings/email-server', smtpForm(['password' => 'smtp-secret']))->assertRedirect();

    $settings = SmtpSetting::current();

    expect($settings->host)->toBe('mail.example.test')
        ->and($settings->password)->toBe('smtp-secret')
        ->and(DB::table('smtp_settings')->value('password'))->not->toContain('smtp-secret');

    $this->get('/settings/email-server')->assertOk()->assertDontSee('smtp-secret')->assertSee('saved (encrypted)');

    $this->put('/settings/email-server', smtpForm())->assertRedirect();
    expect(SmtpSetting::current()->password)->toBe('smtp-secret');

    $this->put('/settings/email-server', smtpForm(['clear_password' => '1']))->assertRedirect();
    expect(SmtpSetting::current()->password)->toBeNull();
});

it('checks the email server form', function (array $overrides, string $field) {
    signIn($this->admin);

    $this->put('/settings/email-server', smtpForm($overrides))->assertSessionHasErrors($field);
})->with([
    'host' => [['host' => 'bad host!'], 'host'],
    'port' => [['port' => 70000], 'port'],
    'security' => [['security' => 'x'], 'security'],
    'from' => [['from_address' => 'nope'], 'from_address'],
]);

it('shows the mail server error when a test send fails', function () {
    signIn($this->admin);

    app()->instance(DirectMailer::class, new class extends DirectMailer
    {
        public function deliver(string $to, string $subject, string $body, ?string $senderName = null, array $files = []): void
        {
            throw new RuntimeException('Auth failed');
        }
    });

    $this->post('/settings/email-server/test', ['to' => 'x@example.test'])->assertSessionHasErrors(['form' => 'Sending failed: Auth failed']);
    $this->post('/settings/email-server/test', ['to' => 'nope'])->assertSessionHasErrors(['form' => 'Enter a valid address for the test email.']);
});

it('brands every email and adds the reply-to address', function () {
    SiteSetting::current()->update(['company_name' => 'PhilPost <HR>']);
    NotificationSetting::current()->update(['reply_to' => 'hr@example.test', 'footer' => 'Footer text']);

    $mail = new SystemMessage('Subject', "Line <one>\nLine two", null, 'Rita');

    $mail->assertSeeInHtml('PhilPost &lt;HR&gt;', false);
    $mail->assertSeeInHtml('Line &lt;one&gt;', false);
    $mail->assertSeeInHtml('Sent by Rita', false);
    $mail->assertSeeInText('Footer text');
    $mail->assertHasReplyTo('hr@example.test');
});

it('records who changed what, field by field', function () {
    $employee = Employee::factory()->create(['position' => 'Clerk']);

    $this->put("/employees/{$employee->id}", ['position' => 'Director']);

    $log = AuditLog::query()->where('collection_slug', 'employees')->where('action', 'update')->sole();

    expect($log->user_id)->toBe($this->hr->id)
        ->and($log->user_name)->toBe($this->hr->name)
        ->and($log->doc_id)->toBe((string) $employee->id)
        ->and($log->doc_label)->toBe($employee->fresh()->full_name)
        ->and($log->changes['position'])->toEqual(['from' => 'Clerk', 'to' => 'Director']);
});

it('keeps a full snapshot of a deleted record', function () {
    $employee = Employee::factory()->create(['position' => 'Clerk']);

    $this->delete("/employees/{$employee->id}");

    expect(AuditLog::query()->where('action', 'delete')->where('collection_slug', 'employees')->sole()->changes['position'])->toBe('Clerk');
});

it('never writes secrets to the audit log', function () {
    signIn($this->admin);

    $this->put('/settings/email-server', smtpForm(['password' => 'smtp-secret']));
    $this->post('/settings/notifications/rotate-key');
    $this->hr->update(['password' => 'a-new-password-1']);

    $all = AuditLog::query()->get()->toJson();

    expect($all)->not->toContain('smtp-secret')
        ->not->toContain(NotificationSetting::current()->queue_key)
        ->not->toContain('a-new-password-1')
        ->not->toContain('"password"');
});

it('does not log sign-in bookkeeping or unchanged saves', function () {
    AuditLog::query()->delete();

    $this->hr->forceFill(['login_attempts' => 3])->save();
    $employee = Employee::factory()->create();
    AuditLog::query()->delete();
    $employee->update(['position' => $employee->position]);

    expect(AuditLog::query()->count())->toBe(0);
});

it('audits a certificate or payslip bulk delete row by row', function () {
    $period = PayrollPeriod::factory()->create();
    Payslip::factory()->count(2)->create(['period_id' => $period->id]);

    $this->delete("/payroll/{$period->id}/payslips");

    expect(AuditLog::query()->where('collection_slug', 'payslips')->where('action', 'delete')->count())->toBe(2);
});

it('shows the audit log with filters and hides account history from HR Staff', function () {
    Employee::factory()->create(['last_name' => 'Reyes', 'first_name' => 'Ana']);
    User::factory()->create(['name' => 'Hidden Account']);

    $this->get('/audit')->assertOk()->assertSee('Reyes, Ana')->assertDontSee('Hidden Account');
    $this->get('/audit?action=delete')->assertOk()->assertSee('No activity found');
    $this->get('/audit?collection=users')->assertOk()->assertDontSee('Hidden Account');

    signIn($this->admin);

    $this->get('/audit?collection=users')->assertOk()->assertSee('Hidden Account');
});

it('has no way to change the audit log', function () {
    $routes = collect(app('router')->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'audit'));

    expect($routes->flatMap(fn ($route) => $route->methods())->unique()->values()->all())->toBe(['GET', 'HEAD']);
});

it('saves the printed letterhead and replaces its logo', function () {
    Storage::fake('local');

    $this->put('/settings', brandingForm([
        'letterhead_name' => "SAMPLE POSTAL CORPORATION\nSAMPLE AREA OFFICE",
        'letterhead_details' => '1 Sample Avenue, Sample City',
        'letterhead_section' => 'Human Resource Management Section',
        'letterhead_logo' => UploadedFile::fake()->image('letterhead.png', 400, 100),
    ]))->assertSessionHasNoErrors();

    $settings = SiteSetting::current();
    $first = $settings->letterhead_logo_id;

    expect($settings->letterhead_name)->toBe("SAMPLE POSTAL CORPORATION\nSAMPLE AREA OFFICE")
        ->and($settings->letterhead_section)->toBe('Human Resource Management Section')
        ->and($first)->not->toBeNull()
        ->and(Media::query()->find($first)->is_public)->toBeTrue();

    $this->put('/settings', brandingForm(['letterhead_logo' => UploadedFile::fake()->image('new.png', 400, 100)]))->assertSessionHasNoErrors();
    expect(Media::query()->find($first))->toBeNull();

    $this->put('/settings', brandingForm(['remove_letterhead_logo' => '1']))->assertSessionHasNoErrors();
    expect(SiteSetting::current()->letterhead_logo_id)->toBeNull();

    $this->get('/settings')->assertOk()->assertSee('Printed letterhead');
});
