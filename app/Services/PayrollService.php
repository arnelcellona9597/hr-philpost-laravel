<?php

namespace App\Services;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\MessageCategory;
use App\Enums\PayrollStatus;
use App\Exceptions\UserFacingException;
use App\Models\Employee;
use App\Models\NotificationSetting;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipSetting;
use App\Models\User;
use App\Support\CosPay;
use App\Support\HrDate;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * COS payroll: generate payslips with each worker's rate and standing deductions, save the grid in
 * one transaction and release. HR types days, lates and overtime; {@see CosPay} computes the rest as
 * the office's payroll workbook does. Each change locks the period row, so a release cannot slip in
 * between the check and the write.
 *
 * @phpstan-type Item array{label: string, amount_centavos: int}
 * @phpstan-type GridRow array{id?: int, values: array<string, string>, remarks?: string}
 * @phpstan-type GridError array{row: int|null, message: string}
 */
class PayrollService
{
    private const RELEASE_TEMPLATE = ['subject' => 'Your payslip for {{payPeriod}}', 'body' => "Hi {{firstName}},\n\nYour payslip for {{payPeriod}} is attached."];

    /** The premium when a worker's pay history has none. */
    public const DEFAULT_PREMIUM = 15;

    /**
     * What HR can type for each payslip: grid key => [column, kind, label].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const INPUTS = [
        'batch' => ['batch_label', 'short', 'Batch'],
        'specific' => ['specific_station', 'text', 'Specific station'],
        'covered' => ['period_covered', 'text', 'Date period covered'],
        'days' => ['days_worked', 'quantity', 'No. of days'],
        'late' => ['late_minutes', 'minutes', 'Late (mins)'],
        'ot' => ['ot_hours', 'quantity', 'OT hours'],
        'ot_rest' => ['ot_rest_hours', 'quantity', 'OT hours RD/SH'],
        'sss' => ['sss_centavos', 'money', 'SSS'],
        'hdmf' => ['hdmf_centavos', 'money', 'HDMF'],
        'cmpeai' => ['cmpeai_centavos', 'money', 'CMPEAI'],
        'absences' => ['absences', 'quantity', 'Absences'],
        'tardiness' => ['tardiness', 'minutes', 'Tardiness'],
    ];

    public function __construct(private MessageQueue $messages, private PayHistoryService $payHistory) {}

    /**
     * The pay items of the payslip template.
     *
     * @return array{earnings: list<string>, deductions: list<string>}
     */
    public function itemLabels(): array
    {
        $settings = PayslipSetting::current();

        return ['earnings' => $settings->earning_items ?? [], 'deductions' => $settings->deduction_items ?? []];
    }

    /**
     * Create a payslip for each active COS worker (in the chosen branches) who has none in this period,
     * with the daily rate and premium in force on the last day of the period, the period covered and
     * the worker's standing SSS / HDMF / CMPEAI deductions. Days, lates and overtime start empty.
     *
     * @param  list<int>  $branchIds
     * @return array{created: int, existing: int, without_rate: int}
     *
     * @throws UserFacingException when the period was released
     */
    public function generate(PayrollPeriod $period, array $branchIds = []): array
    {
        return DB::transaction(function () use ($period, $branchIds): array {
            $locked = $this->lockDraft($period);

            $existing = $locked->payslips()->pluck('employee_id')->all();

            $employees = Employee::query()
                ->with('station')
                ->where('employment_status', EmploymentStatus::Active)
                ->where('classification', Classification::Cos)
                ->when($branchIds !== [], fn ($query) => $query->whereIn('station_id', $branchIds))
                ->whereNotIn('id', $existing)
                ->get();

            $withoutRate = 0;

            foreach ($employees as $employee) {
                $rate = $this->payHistory->rateOn($employee, $locked->period_end->format('Y-m-d'));
                $withoutRate += $rate === null ? 1 : 0;

                $slip = new Payslip([
                    'batch_label' => $locked->batch,
                    'period_covered' => $locked->coveredLabel(),
                    'daily_rate_centavos' => $rate?->amount_centavos ?? 0,
                    'premium_percent' => $rate?->premium_percent ?? self::DEFAULT_PREMIUM,
                    'sss_centavos' => (int) $employee->sss_deduction_centavos,
                    'hdmf_centavos' => (int) $employee->hdmf_deduction_centavos,
                    'cmpeai_centavos' => (int) $employee->cmpeai_deduction_centavos,
                ]);

                $this->createPayslip($locked, $employee, [...$slip->getAttributes(), ...$this->items($slip)]);
            }

            return ['created' => $employees->count(), 'existing' => count($existing), 'without_rate' => $withoutRate];
        });
    }

    /**
     * Read each payslip's daily rate and premium again from pay history, such as after a wage order,
     * and recompute. Only a draft period can be refreshed.
     *
     * @return array{updated: int, without_rate: int}
     *
     * @throws UserFacingException when the period was released
     */
    public function refreshRates(PayrollPeriod $period): array
    {
        return DB::transaction(function () use ($period): array {
            $locked = $this->lockDraft($period);
            $updated = 0;
            $withoutRate = 0;

            foreach ($locked->payslips()->with('employee')->get() as $slip) {
                $rate = $slip->employee ? $this->payHistory->rateOn($slip->employee, $locked->period_end->format('Y-m-d')) : null;

                if ($rate === null) {
                    $withoutRate++;

                    continue;
                }

                $slip->fill(['daily_rate_centavos' => $rate->amount_centavos, 'premium_percent' => $rate->premium_percent ?? self::DEFAULT_PREMIUM]);

                if ($slip->isDirty()) {
                    $slip->fill($this->items($slip))->save();
                    $updated++;
                }
            }

            return ['updated' => $updated, 'without_rate' => $withoutRate];
        });
    }

    /**
     * Save payslip rows from the grid or the payslip page. Each row's values are keyed as in
     * {@see self::INPUTS}; a value left out keeps what the payslip has. Every value is checked first,
     * then all rows are written in one transaction: either every payslip is saved or none is.
     *
     * @param  list<GridRow>  $rows
     * @return array{errors: list<GridError>, updated: int}
     */
    public function saveRows(PayrollPeriod $period, array $rows): array
    {
        return DB::transaction(function () use ($period, $rows): array {
            $locked = PayrollPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $byId = $locked->payslips()->get()->keyBy('id');
            $errors = [];
            $writes = [];

            foreach ($rows as $index => $row) {
                $slip = isset($row['id']) ? $byId->get($row['id']) : null;

                if ($slip === null) {
                    $errors[] = ['row' => $index, 'message' => 'Payslip not found in this period.'];

                    continue;
                }

                $parsed = $this->parseInputs($row['values'], $slip->full_name);

                if ($parsed['errors'] !== []) {
                    foreach ($parsed['errors'] as $message) {
                        $errors[] = ['row' => $index, 'message' => $message];
                    }

                    continue;
                }

                $attributes = $parsed['attributes'];

                if (array_key_exists('remarks', $row)) {
                    $attributes['remarks'] = $row['remarks'] ?: null;
                }

                $slip->fill($attributes);

                if ($slip->cosPay()['net'] < 0) {
                    $errors[] = ['row' => $index, 'message' => "{$slip->full_name}: deductions are higher than the pay, so net pay would be negative."];

                    continue;
                }

                $writes[] = $slip->fill($this->items($slip));
            }

            if ($errors !== []) {
                return ['errors' => $errors, 'updated' => 0];
            }

            foreach ($writes as $slip) {
                if ($locked->isReleased() && $slip->isDirty()) {
                    $slip->forceFill(['corrected_after_release' => true]);
                }

                $slip->save();
            }

            return ['errors' => [], 'updated' => count($writes)];
        });
    }

    /**
     * Check one row's typed values against {@see self::INPUTS}.
     *
     * @param  array<string, string>  $values
     * @return array{attributes: array<string, mixed>, errors: list<string>}
     */
    public function parseInputs(array $values, string $who): array
    {
        $attributes = [];
        $errors = [];

        foreach (self::INPUTS as $key => [$column, $kind, $label]) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $raw = trim((string) $values[$key]);

            try {
                $attributes[$column] = match ($kind) {
                    'money' => Money::toCentavos($raw),
                    'quantity' => CosPay::quantity($raw),
                    'minutes' => CosPay::minutes($raw),
                    'short' => mb_substr($raw, 0, 20) ?: null,
                    default => mb_substr($raw, 0, 120) ?: null,
                };
            } catch (InvalidArgumentException $exception) {
                $errors[] = "{$who}: {$label} {$exception->getMessage()}";
            }
        }

        return ['attributes' => $attributes, 'errors' => $errors];
    }

    /**
     * The payslip items behind the printed payslip, release emails and totals. Overtime takes up the
     * workbook's final rounding, so the payslip's net pay equals the workbook's net pay to the centavo.
     *
     * @return array{earnings: list<Item>, deductions: list<Item>}
     */
    private function items(Payslip $slip): array
    {
        $pay = $slip->cosPay();

        return [
            'earnings' => [
                ['label' => 'Gross', 'amount_centavos' => $pay['gross']],
                ['label' => 'Overtime Pay', 'amount_centavos' => $pay['gross_after_late'] - $pay['gross'] + $pay['late']],
            ],
            'deductions' => [
                ['label' => 'Late', 'amount_centavos' => $pay['late']],
                ['label' => 'SSS', 'amount_centavos' => (int) $slip->sss_centavos],
                ['label' => 'HDMF', 'amount_centavos' => (int) $slip->hdmf_centavos],
                ['label' => 'CMPEAI', 'amount_centavos' => (int) $slip->cmpeai_centavos],
            ],
        ];
    }

    /**
     * Mark the period Released and, when asked and enabled, queue every employee's payslip email.
     * There is no way back from this.
     *
     * @return array{payslips: int, queued: int, skipped: int}
     *
     * @throws UserFacingException when the period cannot be released
     */
    public function release(PayrollPeriod $period, User $user, bool $notify): array
    {
        return DB::transaction(function () use ($period, $user, $notify): array {
            $locked = $this->lockDraft($period);
            $slips = $locked->payslips()->with('employee.station')->get();

            if ($slips->isEmpty()) {
                throw new UserFacingException('There are no payslips in this period yet.');
            }

            $unpaid = $slips->where('gross_pay_centavos', 0)->count();

            if ($unpaid > 0) {
                throw new UserFacingException("{$unpaid} payslip(s) have no earnings (gross pay ₱0.00). Fill them in or remove them first.");
            }

            $locked->forceFill(['status' => PayrollStatus::Released, 'released_at' => now(), 'released_by' => $user->id])->save();

            $queued = 0;
            $skipped = 0;

            if ($notify && NotificationSetting::current()->payroll_released_to_employee) {
                $message = $this->messages->queueMany(
                    $this->messages->template('payroll-released', self::RELEASE_TEMPLATE),
                    MessageCategory::Payroll,
                    $this->recipients($slips, $locked),
                    "Payroll {$locked->name} ({$slips->count()} employees)",
                    ['collection' => 'payroll-periods', 'id' => $locked->id],
                    $user,
                );
                $queued = $message->total - $message->skipped;
                $skipped = $message->skipped;
            }

            return ['payslips' => $slips->count(), 'queued' => $queued, 'skipped' => $skipped];
        });
    }

    /**
     * Email one payslip again, such as after a correction. Returns false when the employee has no
     * usable address, in which case a correction stays flagged.
     *
     * @throws UserFacingException when the period has not been released
     */
    public function resend(Payslip $payslip, User $user): bool
    {
        $period = $payslip->period;

        if (! $period->isReleased()) {
            throw new UserFacingException('This payroll period has not been released yet.');
        }

        $template = $this->messages->template('payroll-released', self::RELEASE_TEMPLATE);

        if ($payslip->corrected_after_release) {
            $template['subject'] = "Corrected: {$template['subject']}";
        }

        return DB::transaction(function () use ($payslip, $period, $template, $user): bool {
            $message = $this->messages->queueMany(
                $template,
                MessageCategory::Payroll,
                $this->recipients(new Collection([$payslip->load('employee.station')]), $period),
                "Payslip {$period->name}: {$payslip->full_name}",
                ['collection' => 'payslips', 'id' => $payslip->id],
                $user,
            );

            if ($message->skipped > 0) {
                return false;
            }

            $payslip->forceFill(['corrected_after_release' => false])->save();

            return true;
        });
    }

    /**
     * @throws UserFacingException when the period was released
     */
    public function deletePayslip(Payslip $payslip): void
    {
        DB::transaction(function () use ($payslip): void {
            $this->lockDraft($payslip->period, 'Released payroll cannot be deleted.');
            $payslip->delete();
        });
    }

    /**
     * @throws UserFacingException when the period was released
     */
    public function deleteAllPayslips(PayrollPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            $this->lockDraft($period, 'Released payroll cannot be deleted.')->payslips()->get()->each->delete();
        });
    }

    /**
     * @throws UserFacingException when the period was released or still has payslips
     */
    public function deletePeriod(PayrollPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            $locked = PayrollPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $blocker = $locked->deleteBlocker();

            if ($blocker !== null) {
                throw new UserFacingException($blocker);
            }

            $locked->delete();
        });
    }

    /**
     * @throws UserFacingException when the period was released
     */
    private function lockDraft(PayrollPeriod $period, string $refusal = 'This payroll period has already been released.'): PayrollPeriod
    {
        $locked = PayrollPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

        if ($locked->isReleased()) {
            throw new UserFacingException($refusal);
        }

        return $locked;
    }

    /**
     * A new payslip with the employee's details as they are for this period.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createPayslip(PayrollPeriod $period, Employee $employee, array $attributes): Payslip
    {
        return Payslip::query()->create([
            ...$attributes,
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'full_name' => $employee->full_name,
            'employee_code' => $employee->employee_id,
            'position' => $employee->position,
            'station' => $employee->station?->name,
            'classification' => $employee->classification?->value,
            'tin' => $employee->tin,
            'sss' => $employee->sss,
            'philhealth' => $employee->philhealth,
            'pagibig' => $employee->pagibig,
        ]);
    }

    /**
     * @param  Collection<int, Payslip>  $slips
     * @return list<array{employee: Employee, payslip_id: int, context: array<string, string>}>
     */
    private function recipients(Collection $slips, PayrollPeriod $period): array
    {
        $symbol = PayslipSetting::current()->currency_symbol ?: '₱';

        return $slips->map(fn (Payslip $slip): array => [
            'employee' => $slip->employee,
            'payslip_id' => $slip->id,
            'context' => [
                'payPeriod' => $period->name,
                'payDate' => HrDate::format($period->pay_date),
                'netPay' => Money::format($slip->net_pay_centavos, $symbol),
            ],
        ])->values()->all();
    }
}
