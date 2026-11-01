<?php

namespace App\Services;

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
use App\Support\HrDate;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Payroll record-keeping: generate payslips, save the grid in one transaction and release. Amounts
 * are entered by HR; the system only adds them up. Each change locks the period row, so a release
 * cannot slip in between the check and the write.
 *
 * @phpstan-type Item array{label: string, amount_centavos: int}
 * @phpstan-type GridRow array{id?: int, employee_id?: int, values: array<string, string>, remarks?: string, line?: int}
 * @phpstan-type GridError array{row: int|null, message: string}
 */
class PayrollService
{
    private const RELEASE_TEMPLATE = ['subject' => 'Your payslip for {{payPeriod}}', 'body' => "Hi {{firstName}},\n\nYour payslip for {{payPeriod}} is attached."];

    public function __construct(private MessageQueue $messages) {}

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
     * Create an empty payslip for each matching active employee who has none in this period.
     *
     * @param  list<int>  $branchIds
     * @param  list<string>  $classifications
     * @return array{created: int, existing: int}
     *
     * @throws UserFacingException when the period was released
     */
    public function generate(PayrollPeriod $period, array $branchIds = [], array $classifications = []): array
    {
        $labels = $this->itemLabels();

        return DB::transaction(function () use ($period, $branchIds, $classifications, $labels): array {
            $this->lockDraft($period);

            $existing = $period->payslips()->pluck('employee_id')->all();

            $employees = Employee::query()
                ->with('station')
                ->where('employment_status', EmploymentStatus::Active)
                ->when($branchIds !== [], fn ($query) => $query->whereIn('station_id', $branchIds))
                ->when($classifications !== [], fn ($query) => $query->whereIn('classification', $classifications))
                ->whereNotIn('id', $existing)
                ->get();

            foreach ($employees as $employee) {
                $this->createPayslip($period, $employee, [
                    'earnings' => array_map(fn (string $label): array => ['label' => $label, 'amount_centavos' => 0], $labels['earnings']),
                    'deductions' => array_map(fn (string $label): array => ['label' => $label, 'amount_centavos' => 0], $labels['deductions']),
                ]);
            }

            return ['created' => $employees->count(), 'existing' => count($existing)];
        });
    }

    /**
     * Build a payslip's items from grid or Excel values, keyed "e:<label>" and "d:<label>". Template
     * items come first, a value left out keeps its amount, and extra items on the payslip stay.
     *
     * @param  array{earnings: list<string>, deductions: list<string>}  $labels
     * @param  array<string, string>  $values
     * @return array{earnings: list<Item>, deductions: list<Item>, errors: list<string>}
     */
    public function buildItems(array $labels, array $values, ?Payslip $current, string $who): array
    {
        $errors = [];

        $build = function (array $list, string $kind, array $saved) use ($values, $who, &$errors): array {
            $amounts = array_column($saved, 'amount_centavos', 'label');
            $items = [];

            foreach ($list as $label) {
                $raw = $values["{$kind}:{$label}"] ?? null;
                $amount = $amounts[$label] ?? 0;

                if ($raw !== null) {
                    try {
                        $amount = Money::toCentavos($raw);
                    } catch (InvalidArgumentException $exception) {
                        $errors[] = "{$who}: {$label} {$exception->getMessage()}";
                    }
                }

                $items[] = ['label' => $label, 'amount_centavos' => $amount];
            }

            $extra = array_filter($saved, fn (array $item): bool => ! in_array($item['label'], $list, true));

            return [...$items, ...array_values($extra)];
        };

        $earnings = $build($labels['earnings'], 'e', $current?->earnings ?? []);
        $deductions = $build($labels['deductions'], 'd', $current?->deductions ?? []);

        if ($errors === [] && $this->total($deductions) > $this->total($earnings)) {
            $errors[] = "{$who}: deductions are higher than gross pay.";
        }

        return ['earnings' => $earnings, 'deductions' => $deductions, 'errors' => $errors];
    }

    /**
     * Save payslip rows from the grid or Excel. Every value is checked first, then all rows are
     * written in one transaction: either every payslip is saved or none is. A row with an
     * `employee_id` and no payslip creates one, which a released period refuses.
     *
     * @param  list<GridRow>  $rows
     * @return array{errors: list<GridError>, updated: int, created: int}
     */
    public function saveRows(PayrollPeriod $period, array $rows): array
    {
        $labels = $this->itemLabels();

        return DB::transaction(function () use ($period, $rows, $labels): array {
            $locked = PayrollPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $slips = $locked->payslips()->get();
            $byId = $slips->keyBy('id');
            $byEmployee = $slips->keyBy('employee_id');
            $errors = [];
            $writes = [];

            foreach ($rows as $index => $row) {
                $slip = isset($row['id']) ? $byId->get($row['id']) : (isset($row['employee_id']) ? $byEmployee->get($row['employee_id']) : null);

                if ($slip === null && isset($row['id'])) {
                    $errors[] = ['row' => $index, 'message' => 'Payslip not found in this period.'];

                    continue;
                }

                if ($slip === null && $locked->isReleased()) {
                    $errors[] = ['row' => $index, 'message' => 'This period was already released, so no new payslip can be added.'];

                    continue;
                }

                $who = $slip?->full_name ?? 'Row '.($index + 1);
                $built = $this->buildItems($labels, $row['values'], $slip, $who);

                foreach ($built['errors'] as $message) {
                    $errors[] = ['row' => $index, 'message' => $message];
                }

                $writes[] = ['slip' => $slip, 'row' => $row, 'items' => ['earnings' => $built['earnings'], 'deductions' => $built['deductions']]];
            }

            if ($errors !== []) {
                return ['errors' => $errors, 'updated' => 0, 'created' => 0];
            }

            $created = 0;
            $newEmployees = Employee::query()->with('station')
                ->whereIn('id', collect($writes)->whereNull('slip')->pluck('row.employee_id')->filter())
                ->get()->keyBy('id');

            foreach ($writes as ['slip' => $slip, 'row' => $row, 'items' => $items]) {
                $attributes = array_key_exists('remarks', $row) ? [...$items, 'remarks' => $row['remarks'] ?: null] : $items;

                if ($slip === null) {
                    $this->createPayslip($locked, $newEmployees->get($row['employee_id']), $attributes);
                    $created++;

                    continue;
                }

                $unchanged = $this->paysTheSame($slip, $items);
                $slip->fill($attributes);

                if ($locked->isReleased() && (! $unchanged || $slip->isDirty('remarks'))) {
                    $slip->forceFill(['corrected_after_release' => true]);
                }

                $slip->save();
            }

            return ['errors' => [], 'updated' => count($writes) - $created, 'created' => $created];
        });
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

    /**
     * Whether new items pay the same as the payslip. Zero rows don't count, so re-saving a released
     * grid after a pay item was added to the template corrects nothing.
     *
     * @param  array{earnings: list<Item>, deductions: list<Item>}  $items
     */
    private function paysTheSame(Payslip $slip, array $items): bool
    {
        $amounts = function (array $list): array {
            $paid = array_filter(array_column($list, 'amount_centavos', 'label'));
            ksort($paid);

            return $paid;
        };

        return $amounts($slip->earnings) === $amounts($items['earnings'])
            && $amounts($slip->deductions) === $amounts($items['deductions']);
    }

    /**
     * @param  list<Item>  $items
     */
    private function total(array $items): int
    {
        return array_sum(array_column($items, 'amount_centavos'));
    }
}
