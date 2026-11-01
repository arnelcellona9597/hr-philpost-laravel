<?php

namespace App\Support;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Concerns\IsSingleton;
use App\Models\Contract;
use App\Models\ContractSetting;
use App\Models\ContractTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\Holiday;
use App\Models\IpcrRating;
use App\Models\ItrSubmission;
use App\Models\LeaveSetting;
use App\Models\NotificationSetting;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipSetting;
use App\Models\PdsSubmission;
use App\Models\ReceivedMail;
use App\Models\Remittance;
use App\Models\RemittanceSetting;
use App\Models\RequirementDeadlineSetting;
use App\Models\SiteSetting;
use App\Models\SmtpSetting;
use App\Models\SwornDeclaration;
use App\Models\User;
use App\Models\VoucherSetting;
use App\Models\WellnessLeave;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the audit log. The history cannot be edited, and it never holds a password or key.
 */
class AuditTrail
{
    /**
     * The slug shown in the log, and the column that names the record.
     */
    private const AREAS = [
        Employee::class => ['employees', 'full_name'],
        EmploymentPeriod::class => ['employment-periods', 'label'],
        Branch::class => ['branches', 'name'],
        Application::class => ['applications', 'applicant_name'],
        WellnessLeave::class => ['wellness-leaves', 'full_name'],
        ItrSubmission::class => ['itr-submissions', 'full_name'],
        SwornDeclaration::class => ['sworn-declarations', 'full_name'],
        PdsSubmission::class => ['pds-submissions', 'full_name'],
        IpcrRating::class => ['ipcr-ratings', 'full_name'],
        Holiday::class => ['holidays', 'name'],
        ReceivedMail::class => ['received-mails', 'tracking_number'],
        Remittance::class => ['remittances', 'label'],
        User::class => ['users', 'name'],
        Certificate::class => ['certificates', 'control_number'],
        CertificateTemplate::class => ['certificate-templates', 'name'],
        Contract::class => ['contracts', 'control_number'],
        ContractTemplate::class => ['contract-templates', 'name'],
        PayrollPeriod::class => ['payroll-periods', 'name'],
        Payslip::class => ['payslips', 'full_name'],
        EmailTemplate::class => ['email-templates', 'name'],
        SiteSetting::class => ['site-settings', null],
        LeaveSetting::class => ['leave-settings', null],
        NotificationSetting::class => ['notification-settings', null],
        PayslipSetting::class => ['payslip-settings', null],
        ContractSetting::class => ['contract-settings', null],
        RemittanceSetting::class => ['remittance-settings', 'title'],
        VoucherSetting::class => ['voucher-settings', null],
        SmtpSetting::class => ['smtp-settings', null],
        RequirementDeadlineSetting::class => ['requirement-deadlines', null],
    ];

    /**
     * Timestamps, computed columns, sign-in bookkeeping and secrets.
     */
    private const IGNORED = [
        'id', 'created_at', 'updated_at', 'password', 'remember_token', 'login_attempts', 'locked_until',
        'queue_key', 'smtp_last_error', 'smtp_last_error_at', 'smtp_last_ok_at',
        'requirements_email_sent_at', 'requirements_email_to', 'days_by_year', 'age',
    ];

    private static bool $paused = false;

    /**
     * Run system bookkeeping or a bulk import without a row per change.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function withoutRecording(Closure $callback): mixed
    {
        $previous = self::$paused;
        self::$paused = true;

        try {
            return $callback();
        } finally {
            self::$paused = $previous;
        }
    }

    public static function created(Model $model): void
    {
        if (! in_array(IsSingleton::class, class_uses_recursive($model), true)) {
            self::write($model, 'create', null);
        }
    }

    public static function updated(Model $model): void
    {
        $changes = [];

        foreach (array_keys($model->getChanges()) as $column) {
            if (in_array($column, self::IGNORED, true)) {
                continue;
            }

            $from = self::plain($model->getOriginal($column));
            $to = self::plain($model->getAttribute($column));

            if (json_encode($from) !== json_encode($to)) {
                $changes[$column] = ['from' => $from, 'to' => $to];
            }
        }

        if ($changes !== []) {
            self::write($model, 'update', $changes);
        }
    }

    public static function deleted(Model $model): void
    {
        $snapshot = collect($model->getAttributes())
            ->except(array_merge(self::IGNORED, $model->getHidden()))
            ->keys()
            ->mapWithKeys(fn (string $column): array => [$column => self::plain($model->getAttribute($column))])
            ->all();

        self::write($model, 'delete', $snapshot);
    }

    /**
     * One entry for something that isn't a single record, such as an import.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public static function record(string $action, string $collectionSlug, ?string $label, ?array $changes = null, int|string|null $documentId = null): void
    {
        $user = Auth::user();

        (new AuditLog)->forceFill([
            'user_id' => $user?->id,
            'user_name' => $user ? ($user->name ?: $user->email) : 'System',
            'action' => $action,
            'collection_slug' => $collectionSlug,
            'doc_id' => $documentId === null ? null : (string) $documentId,
            'doc_label' => $label === null ? null : mb_substr($label, 0, 255),
            'changes' => $changes,
        ])->save();
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    private static function write(Model $model, string $action, ?array $changes): void
    {
        if (self::$paused) {
            return;
        }

        [$slug, $labelColumn] = self::AREAS[$model::class];

        self::record($action, $slug, $labelColumn ? (string) $model->getAttribute($labelColumn) : null, $changes, $model->getKey());
    }

    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s') === $value->format('Y-m-d').' 00:00:00' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i:s'),
            default => $value,
        };
    }
}
