<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\DocumentSequence;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\ImportJob;
use App\Models\IpcrRating;
use App\Models\ItrSubmission;
use App\Models\LoginChallenge;
use App\Models\Media;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Notification;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PdsSubmission;
use App\Models\SiteSetting;
use App\Models\SwornDeclaration;
use App\Models\User;
use App\Models\WellnessLeave;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('runs on MySQL', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toBe('hr_philpost_test');
});

it('has a table for every collection and settings group', function () {
    $tables = array_column(Schema::getTables(), 'name');

    expect($tables)->toContain(
        'users', 'media', 'branches', 'employees', 'applications', 'wellness_leaves',
        'itr_submissions', 'sworn_declarations', 'pds_submissions', 'ipcr_ratings',
        'payroll_periods', 'payslips', 'email_templates', 'messages', 'message_recipients',
        'notifications', 'holidays', 'audit_logs', 'import_jobs', 'login_challenges',
        'certificate_templates', 'certificates', 'site_settings', 'leave_settings',
        'notification_settings', 'payslip_settings', 'smtp_settings',
        'requirement_deadline_settings', 'document_sequences',
    );
});

it('stores money as integer centavos', function () {
    foreach (['gross_pay_centavos', 'total_deductions_centavos', 'net_pay_centavos'] as $column) {
        expect(Schema::getColumnType('payslips', $column))->toBe('bigint');
    }
});

it('stores HR dates in DATE columns', function (string $table, string $column) {
    expect(Schema::getColumnType($table, $column))->toBe('date');
})->with([
    ['employees', 'date_of_birth'],
    ['employees', 'date_hired'],
    ['applications', 'date_applied'],
    ['wellness_leaves', 'inclusive_date_from'],
    ['itr_submissions', 'date_submitted'],
    ['payroll_periods', 'pay_date'],
    ['certificates', 'issued_date'],
    ['holidays', 'date'],
]);

it('refuses to delete a parent that records still depend on', function (Model $parent) {
    expect(fn () => $parent->delete())->toThrow(QueryException::class);
})->with([
    'branch with employees' => fn () => Employee::factory()->for(Branch::factory(), 'station')->create()->station,
    'branch with applications' => fn () => Application::factory()->for(Branch::factory(), 'station')->create()->station,
    'employee with leave' => fn () => WellnessLeave::factory()->create()->employee,
    'employee with ITR' => fn () => ItrSubmission::factory()->create()->employee,
    'employee with sworn declaration' => fn () => SwornDeclaration::factory()->create()->employee,
    'employee with PDS' => fn () => PdsSubmission::factory()->create()->employee,
    'employee with IPCR' => fn () => IpcrRating::factory()->create()->employee,
    'employee with payslip' => fn () => Payslip::factory()->create()->employee,
    'employee with certificate' => fn () => Certificate::factory()->create()->employee,
    'employee with application' => fn () => Application::factory()->for(Employee::factory())->create()->employee,
    'period with payslips' => fn () => Payslip::factory()->create()->period,
    'template with certificates' => fn () => Certificate::factory()->create()->template,
    'file used as avatar' => fn () => User::factory()->for(Media::factory(), 'avatar')->create()->avatar,
    'file used as logo' => function () {
        $file = Media::factory()->create();
        SiteSetting::current()->update(['logo_id' => $file->id]);

        return $file;
    },
]);

it('keeps history but clears the actor when an account is deleted', function () {
    $user = User::factory()->create();
    $log = AuditLog::factory()->create(['user_id' => $user->id, 'user_name' => $user->name]);
    $import = ImportJob::factory()->create(['user_id' => $user->id]);
    $period = PayrollPeriod::factory()->create();
    $period->forceFill(['released_by' => $user->id])->save();
    $message = Message::factory()->create(['sent_by' => $user->id, 'sent_by_name' => $user->name]);
    $certificate = Certificate::factory()->create(['issued_by' => $user->id, 'issued_by_name' => $user->name]);

    $user->delete();

    expect($log->fresh()->user_id)->toBeNull()
        ->and($log->fresh()->user_name)->toBe($user->name)
        ->and($import->fresh()->user_id)->toBeNull()
        ->and($period->fresh()->released_by)->toBeNull()
        ->and($message->fresh()->sent_by)->toBeNull()
        ->and($certificate->fresh()->issued_by)->toBeNull();
});

it('removes rows owned by a deleted account', function () {
    $notification = Notification::factory()->create();
    $challenge = LoginChallenge::factory()->create(['user_id' => $notification->user_id]);

    $notification->user->delete();

    expect(Notification::query()->find($notification->id))->toBeNull()
        ->and(LoginChallenge::query()->find($challenge->id))->toBeNull();
});

it('removes delivery rows and attachment links with their message', function () {
    $recipient = MessageRecipient::factory()->create();
    $file = Media::factory()->create();
    $recipient->message->attachments()->attach($file);

    $recipient->message->delete();

    expect(MessageRecipient::query()->find($recipient->id))->toBeNull()
        ->and(DB::table('message_attachments')->count())->toBe(0)
        ->and($file->fresh())->not->toBeNull();
});

it('keeps sent mail history when a payslip goes away', function () {
    $payslip = Payslip::factory()->create();
    $recipient = MessageRecipient::factory()->create(['payslip_id' => $payslip->id]);

    $payslip->delete();

    expect($recipient->fresh()->payslip_id)->toBeNull();
});

it('refuses a file used in a message to be deleted', function () {
    $file = Media::factory()->create();
    Message::factory()->create()->attachments()->attach($file);

    expect(fn () => $file->delete())->toThrow(QueryException::class);
});

it('allows one record per employee and year', function (string $model) {
    $record = $model::factory()->create();

    expect(fn () => $model::factory()->create(['employee_id' => $record->employee_id, 'year' => $record->year]))
        ->toThrow(UniqueConstraintViolationException::class);
})->with([ItrSubmission::class, SwornDeclaration::class, PdsSubmission::class]);

it('allows one IPCR rating per employee, year and rating period', function () {
    $rating = IpcrRating::factory()->create(['rating_period' => 'Jan-Jun']);
    $attributes = ['employee_id' => $rating->employee_id, 'year' => $rating->year];

    expect(IpcrRating::factory()->create($attributes + ['rating_period' => 'Jul-Dec'])->exists)->toBeTrue()
        ->and(fn () => IpcrRating::factory()->create($attributes + ['rating_period' => 'Jan-Jun']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows one payslip per employee and period', function () {
    $payslip = Payslip::factory()->create();

    expect(fn () => Payslip::factory()->create(['period_id' => $payslip->period_id, 'employee_id' => $payslip->employee_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows one holiday per date', function () {
    Holiday::factory()->create(['date' => '2026-12-25']);

    expect(fn () => Holiday::factory()->create(['date' => '2026-12-25']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows branches without a code but keeps codes unique', function () {
    Branch::factory()->count(2)->create(['code' => null]);
    Branch::factory()->create(['code' => 'MNL']);

    expect(fn () => Branch::factory()->create(['code' => 'MNL']))->toThrow(UniqueConstraintViolationException::class);
});

it('numbers sequences independently', function () {
    expect(DocumentSequence::next('a'))->toBe(1)
        ->and(DocumentSequence::next('a'))->toBe(2)
        ->and(DocumentSequence::next('b'))->toBe(1);
});

it('formats certificate numbers per prefix and year', function () {
    expect(DocumentSequence::nextCertificateNumber('COE', 2026))->toBe('COE-2026-0001')
        ->and(DocumentSequence::nextCertificateNumber('COE', 2026))->toBe('COE-2026-0002')
        ->and(DocumentSequence::nextCertificateNumber('COE', 2027))->toBe('COE-2027-0001')
        ->and(DocumentSequence::nextCertificateNumber('EMP', 2026))->toBe('EMP-2026-0001');
});

it('formats application IDs per year', function () {
    expect(DocumentSequence::nextApplicationId(2026))->toBe('APP-2026-001')
        ->and(DocumentSequence::nextApplicationId(2026))->toBe('APP-2026-002');
});

it('never reissues a number after the highest record is deleted', function () {
    $template = CertificateTemplate::factory()->create();
    $certificate = Certificate::factory()->for($template, 'template')->create([
        'control_number' => DocumentSequence::nextCertificateNumber('COE', 2026),
    ]);

    $certificate->delete();

    expect(DocumentSequence::nextCertificateNumber('COE', 2026))->toBe('COE-2026-0002');
});
