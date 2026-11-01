<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Services\EmploymentHistoryService;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Rules across fields of a record, shared by the forms and the Excel import.
 */
class RecordRules
{
    private const RECEIVED_AFTER_SUBMITTED = ['date_submitted', 'date_received', 'Date received cannot be before the date submitted'];

    private const BANK_ACCOUNT = '/^\d[\d -]*\d$/';

    /**
     * Pairs of date columns that must stay in order, with the message for the later one.
     */
    private const DATE_ORDER = [
        'employees' => [['date_hired', 'last_day_of_service', 'Last day of service cannot be before the date hired']],
        'applications' => [['date_applied', 'start_ojt', 'Start OJT cannot be before the date applied'], ['date_applied', 'fds_date', 'The FDS date cannot be before the date applied']],
        'employment-history' => [['hired_on', 'separated_on', 'The date separated cannot be before the date hired']],
        'wellness-leaves' => [['date_filing', 'date_received', 'Date received cannot be before the filing date']],
        'itr-submissions' => [self::RECEIVED_AFTER_SUBMITTED],
        'sworn-declarations' => [self::RECEIVED_AFTER_SUBMITTED],
        'pds-submissions' => [self::RECEIVED_AFTER_SUBMITTED],
        'ipcr-ratings' => [self::RECEIVED_AFTER_SUBMITTED, ['period_from', 'period_to', 'Period end is before period start']],
    ];

    /**
     * Problems with a record's values, keyed by the field to mark.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function errors(string $module, array $values): array
    {
        $errors = [];

        foreach (self::DATE_ORDER[$module] ?? [] as [$earlier, $later, $message]) {
            if (($values[$earlier] ?? null) !== null && ($values[$later] ?? null) !== null && $values[$later] < $values[$earlier]) {
                $errors[$later] = $message;
            }
        }

        if ($module === 'employees' && ($values['date_of_birth'] ?? '') > HrDate::today()) {
            $errors['date_of_birth'] = 'Date of birth cannot be in the future';
        }

        if ($module === 'employees' && filled($values['bank_account_number'] ?? null) && ! preg_match(self::BANK_ACCOUNT, (string) $values['bank_account_number'])) {
            $errors['bank_account_number'] = 'Enter the bank account number using digits, spaces and hyphens only';
        }

        return $errors;
    }

    /**
     * An applicant is listed once: the message when another application already has this name, ignoring
     * capitals and extra spaces.
     */
    public static function duplicateApplicant(?string $name, ?int $exceptId = null): ?string
    {
        $clean = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));

        if ($clean === '') {
            return null;
        }

        $existing = Application::query()
            ->whereRaw('LOWER(TRIM(applicant_name)) = ?', [$clean])
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->first(['id', 'applicant_name', 'application_id']);

        return $existing === null ? null : "An applicant named {$existing->applicant_name} is already listed ({$existing->application_id}). Open that application instead of adding a second one.";
    }

    /**
     * The same rules for a model holding new values alongside its saved ones.
     *
     * @return array<string, string>
     */
    public static function errorsFor(string $module, Model $model): array
    {
        $columns = array_merge(...array_map(fn (array $rule): array => array_slice($rule, 0, 2), self::DATE_ORDER[$module] ?? [[]]));
        $values = [];

        foreach ([...$columns, 'date_of_birth', 'bank_account_number'] as $column) {
            $value = $model->getAttribute($column);
            $values[$column] = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        $errors = self::errors($module, $values);

        if ($module === 'applications' && $model instanceof Application && ($message = self::duplicateApplicant($model->applicant_name, $model->exists ? $model->getKey() : null)) !== null) {
            $errors['applicant_name'] = $message;
        }

        return [...$errors, ...self::historyErrors($module, $model)];
    }

    /**
     * Rules that need the employee's other employment periods.
     *
     * @return array<string, string>
     */
    private static function historyErrors(string $module, Model $model): array
    {
        $history = app(EmploymentHistoryService::class);

        if ($module === 'employment-history' && $model instanceof EmploymentPeriod) {
            $employee = Employee::query()->find($model->employee_id);
            $hired = $model->hired_on?->toDateString();
            $separated = $model->separated_on?->toDateString();

            return match (true) {
                $separated !== null && $model->separation_type === null => ['separation_type' => 'Choose how the employment ended'],
                $employee === null => [],
                default => array_filter(['hired_on' => $history->problem($employee, $model, $hired, $separated)]),
            };
        }

        if ($module === 'employees' && $model instanceof Employee) {
            return $history->employeeProblems($model, [
                'date_hired' => $model->date_hired?->toDateString(),
                'last_day_of_service' => $model->last_day_of_service?->toDateString(),
                'employment_status' => $model->employment_status?->value,
            ]);
        }

        return [];
    }
}
