<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\ImportJob;
use App\Models\IpcrRating;
use App\Models\ItrSubmission;
use App\Models\LeaveSetting;
use App\Models\LoginChallenge;
use App\Models\Media;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Notification;
use App\Models\NotificationSetting;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipSetting;
use App\Models\PdsSubmission;
use App\Models\RequirementDeadlineSetting;
use App\Models\SiteSetting;
use App\Models\SmtpSetting;
use App\Models\SwornDeclaration;
use App\Models\User;
use App\Models\WellnessLeave;
use Illuminate\Support\Facades\Gate;

dataset('HR records', [
    'branches' => Branch::class,
    'employees' => Employee::class,
    'applications' => Application::class,
    'wellness leaves' => WellnessLeave::class,
    'ITR submissions' => ItrSubmission::class,
    'sworn declarations' => SwornDeclaration::class,
    'PDS submissions' => PdsSubmission::class,
    'IPCR ratings' => IpcrRating::class,
    'payroll periods' => PayrollPeriod::class,
    'payslips' => Payslip::class,
    'email templates' => EmailTemplate::class,
    'holidays' => Holiday::class,
    'certificate templates' => CertificateTemplate::class,
    'files' => Media::class,
]);

dataset('staff settings', [
    'leave' => LeaveSetting::class,
    'notifications' => NotificationSetting::class,
    'payslip' => PayslipSetting::class,
    'deadlines' => RequirementDeadlineSetting::class,
]);

dataset('inactive accounts', [
    'pending' => fn () => User::factory()->pending()->create(),
    'disabled' => fn () => User::factory()->disabled()->create(),
    'disabled admin' => fn () => User::factory()->systemAdmin()->disabled()->create(),
]);

function staff(): User
{
    return User::factory()->create();
}

function systemAdmin(): User
{
    return User::factory()->systemAdmin()->create();
}

it('gives approved staff full CRUD on HR records', function (string $model) {
    $record = $model::factory()->create();

    expect(staff()->can('viewAny', $model))->toBeTrue()
        ->and(staff()->can('view', $record))->toBeTrue()
        ->and(staff()->can('create', $model))->toBeTrue()
        ->and(staff()->can('update', $record))->toBeTrue()
        ->and(staff()->can('delete', $record))->toBeTrue();
})->with('HR records');

it('denies HR records to accounts that are not approved', function (string $model, User $account) {
    $record = $model::factory()->create();

    expect($account->can('viewAny', $model))->toBeFalse()
        ->and($account->can('view', $record))->toBeFalse()
        ->and($account->can('create', $model))->toBeFalse()
        ->and($account->can('update', $record))->toBeFalse()
        ->and($account->can('delete', $record))->toBeFalse();
})->with('HR records')->with('inactive accounts');

it('denies HR records to guests', function (string $model) {
    expect(Gate::forUser(null)->allows('create', $model))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewAny', $model))->toBeFalse();
})->with('HR records');

it('lets staff issue and void certificates', function () {
    $certificate = Certificate::factory()->create();

    expect(staff()->can('create', Certificate::class))->toBeTrue()
        ->and(staff()->can('view', $certificate))->toBeTrue()
        ->and(staff()->can('update', $certificate))->toBeTrue();
});

it('lets only System Admins delete certificates', function () {
    $certificate = Certificate::factory()->create();

    expect(staff()->can('delete', $certificate))->toBeFalse()
        ->and(systemAdmin()->can('delete', $certificate))->toBeTrue();
});

it('shows private files to staff only', function () {
    $file = Media::factory()->create();

    expect(staff()->can('view', $file))->toBeTrue()
        ->and(Gate::forUser(null)->allows('view', $file))->toBeFalse()
        ->and(User::factory()->disabled()->create()->can('view', $file))->toBeFalse();
});

it('shows public files to visitors', function () {
    expect(Gate::forUser(null)->allows('view', Media::factory()->public()->create()))->toBeTrue();
});

it('lets only System Admins make a file public', function () {
    expect(staff()->can('publish', Media::class))->toBeFalse()
        ->and(systemAdmin()->can('publish', Media::class))->toBeTrue();
});

it('lets only System Admins list and create HR accounts', function () {
    expect(staff()->can('viewAny', User::class))->toBeFalse()
        ->and(staff()->can('create', User::class))->toBeFalse()
        ->and(systemAdmin()->can('viewAny', User::class))->toBeTrue()
        ->and(systemAdmin()->can('create', User::class))->toBeTrue();
});

it('limits staff to their own account', function () {
    $member = staff();
    $other = staff();

    expect($member->can('view', $member))->toBeTrue()
        ->and($member->can('update', $member))->toBeTrue()
        ->and($member->can('view', $other))->toBeFalse()
        ->and($member->can('update', $other))->toBeFalse();
});

it('stops disabled accounts editing themselves', function () {
    $account = User::factory()->disabled()->create();

    expect($account->can('update', $account))->toBeFalse();
});

it('lets System Admins edit any account', function () {
    expect(systemAdmin()->can('update', staff()))->toBeTrue();
});

it('lets only System Admins change an email, role or status', function () {
    expect(staff()->can('manageAccess', User::class))->toBeFalse()
        ->and(systemAdmin()->can('manageAccess', User::class))->toBeTrue();
});

it('lets only System Admins unlock accounts', function () {
    expect(staff()->can('unlock', User::class))->toBeFalse()
        ->and(systemAdmin()->can('unlock', User::class))->toBeTrue();
});

it('lets System Admins delete other accounts but never their own', function () {
    $admin = systemAdmin();

    expect($admin->can('delete', staff()))->toBeTrue()
        ->and($admin->can('delete', $admin))->toBeFalse()
        ->and(staff()->can('delete', staff()))->toBeFalse();
});

it('lets staff read and delete the outbox but not write to it', function () {
    $message = Message::factory()->create();

    expect(staff()->can('view', $message))->toBeTrue()
        ->and(staff()->can('delete', $message))->toBeTrue()
        ->and(staff()->can('create', Message::class))->toBeFalse()
        ->and(staff()->can('update', $message))->toBeFalse();
});

it('makes delivery rows read only', function () {
    $recipient = MessageRecipient::factory()->create();

    expect(staff()->can('view', $recipient))->toBeTrue()
        ->and(systemAdmin()->can('create', MessageRecipient::class))->toBeFalse()
        ->and(systemAdmin()->can('update', $recipient))->toBeFalse()
        ->and(systemAdmin()->can('delete', $recipient))->toBeFalse();
});

it('makes import history read only', function () {
    $job = ImportJob::factory()->create();

    expect(staff()->can('view', $job))->toBeTrue()
        ->and(systemAdmin()->can('create', ImportJob::class))->toBeFalse()
        ->and(systemAdmin()->can('update', $job))->toBeFalse()
        ->and(systemAdmin()->can('delete', $job))->toBeFalse();
});

it('keeps bell notifications private to their owner', function () {
    $notification = Notification::factory()->create();

    expect($notification->user->can('view', $notification))->toBeTrue()
        ->and($notification->user->can('update', $notification))->toBeTrue()
        ->and($notification->user->can('delete', $notification))->toBeTrue()
        ->and(systemAdmin()->can('view', $notification))->toBeFalse()
        ->and(staff()->can('update', $notification))->toBeFalse()
        ->and(staff()->can('delete', $notification))->toBeFalse();
});

it('lets nobody create bell notifications', function () {
    expect(systemAdmin()->can('create', Notification::class))->toBeFalse();
});

it('hides account and SMTP history from staff', function () {
    expect(staff()->can('view', AuditLog::factory()->forUsers()->create()))->toBeFalse()
        ->and(staff()->can('view', AuditLog::factory()->forSmtpSettings()->create()))->toBeFalse()
        ->and(staff()->can('view', AuditLog::factory()->create()))->toBeTrue();
});

it('shows System Admins the whole audit log', function () {
    expect(systemAdmin()->can('view', AuditLog::factory()->forUsers()->create()))->toBeTrue();
});

it('applies the same audit rule to list queries', function () {
    $staff = staff();
    $admin = systemAdmin();
    $disabled = User::factory()->disabled()->create();
    AuditLog::query()->delete();

    AuditLog::factory()->create();
    AuditLog::factory()->forUsers()->create();
    AuditLog::factory()->forSmtpSettings()->create();

    expect(AuditLog::query()->visibleTo($staff)->count())->toBe(1)
        ->and(AuditLog::query()->visibleTo($admin)->count())->toBe(3)
        ->and(AuditLog::query()->visibleTo($disabled)->count())->toBe(0);
});

it('makes the audit log immutable', function () {
    $entry = AuditLog::factory()->create();

    expect(systemAdmin()->can('create', AuditLog::class))->toBeFalse()
        ->and(systemAdmin()->can('update', $entry))->toBeFalse()
        ->and(systemAdmin()->can('delete', $entry))->toBeFalse();
});

it('gives nobody direct access to login challenges', function () {
    $challenge = LoginChallenge::factory()->create();

    expect(systemAdmin()->can('viewAny', LoginChallenge::class))->toBeFalse()
        ->and(systemAdmin()->can('view', $challenge))->toBeFalse()
        ->and(systemAdmin()->can('create', LoginChallenge::class))->toBeFalse()
        ->and(systemAdmin()->can('update', $challenge))->toBeFalse()
        ->and(systemAdmin()->can('delete', $challenge))->toBeFalse();
});

it('makes branding readable without signing in', function () {
    expect(Gate::forUser(null)->allows('view', SiteSetting::class))->toBeTrue();
});

it('lets staff but not visitors change branding', function () {
    expect(staff()->can('update', SiteSetting::class))->toBeTrue()
        ->and(User::factory()->pending()->create()->can('update', SiteSetting::class))->toBeFalse();
});

it('lets staff read and update settings groups', function (string $settings) {
    expect(staff()->can('view', $settings))->toBeTrue()
        ->and(staff()->can('update', $settings))->toBeTrue()
        ->and(Gate::forUser(null)->allows('view', $settings))->toBeFalse()
        ->and(User::factory()->disabled()->create()->can('update', $settings))->toBeFalse();
})->with('staff settings');

it('keeps SMTP settings to System Admins', function () {
    expect(staff()->can('view', SmtpSetting::class))->toBeFalse()
        ->and(staff()->can('update', SmtpSetting::class))->toBeFalse()
        ->and(systemAdmin()->can('view', SmtpSetting::class))->toBeTrue()
        ->and(systemAdmin()->can('update', SmtpSetting::class))->toBeTrue();
});

it('keeps the cron key to System Admins', function () {
    expect(staff()->can('viewQueueKey', NotificationSetting::class))->toBeFalse()
        ->and(staff()->can('rotateQueueKey', NotificationSetting::class))->toBeFalse()
        ->and(systemAdmin()->can('viewQueueKey', NotificationSetting::class))->toBeTrue()
        ->and(systemAdmin()->can('rotateQueueKey', NotificationSetting::class))->toBeTrue();
});

it('keeps raw SMTP errors to System Admins', function () {
    expect(staff()->can('viewSmtpError', NotificationSetting::class))->toBeFalse()
        ->and(systemAdmin()->can('viewSmtpError', NotificationSetting::class))->toBeTrue();
});
