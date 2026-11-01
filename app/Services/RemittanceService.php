<?php

namespace App\Services;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\RemittanceKind;
use App\Enums\RemittanceStatus;
use App\Exceptions\UserFacingException;
use App\Models\Employee;
use App\Models\Remittance;
use App\Models\RemittanceLine;
use App\Models\RemittanceSetting;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\HrDate;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Monthly SSS, Pag-IBIG and CMPEAI remittances: start a month from the last one, keep the list of people
 * and amounts, work out the totals and lock a month once it is paid. Amounts are whole centavos.
 *
 * @phpstan-type Totals array{count: int, gross: int, employer: int, compensation: int, serviceCharge: int, net: int, payable: int}
 */
class RemittanceService
{
    public const MAX_LINES = 3_000;

    /**
     * Start a month. It copies the people from the latest earlier month of the same fund, leaving out
     * anyone who has since left; the first month lists the active COS workers who have the member number.
     *
     * @return array{remittance: Remittance, copied: int, dropped: int}
     *
     * @throws UserFacingException when the month already exists
     */
    public function generate(RemittanceKind $kind, int $year, int $month, ?User $user = null): array
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw new UserFacingException('Choose a valid month and year.');
        }

        if (Remittance::query()->where(['kind' => $kind->value, 'year' => $year, 'month' => $month])->exists()) {
            throw new UserFacingException("{$kind->label()} for ".$this->period($year, $month).' already exists.');
        }

        return DB::transaction(function () use ($kind, $year, $month, $user): array {
            $setting = RemittanceSetting::forKind($kind);
            $remittance = new Remittance(['kind' => $kind, 'year' => $year, 'month' => $month, 'status' => RemittanceStatus::Draft]);
            $remittance->created_by = $user?->id;
            $remittance->save();

            $previous = Remittance::query()
                ->where('kind', $kind->value)
                ->where(fn (Builder $query) => $query->where('year', '<', $year)->orWhere(fn (Builder $same) => $same->where('year', $year)->where('month', '<', $month)))
                ->orderByDesc('year')->orderByDesc('month')
                ->first();

            $rows = [];
            $dropped = 0;

            if ($previous !== null) {
                $employees = Employee::query()->with('station')->whereIn('id', $previous->lines()->whereNotNull('employee_id')->pluck('employee_id'))->get()->keyBy('id');

                foreach ($previous->lines as $line) {
                    $employee = $line->employee_id === null ? null : $employees->get($line->employee_id);

                    if ($line->employee_id !== null && ($employee === null || $employee->employment_status !== EmploymentStatus::Active)) {
                        $dropped++;

                        continue;
                    }

                    $rows[] = $employee === null
                        ? $line->only(['last_name', 'first_name', 'middle_name', 'extension', 'birth_date', 'station', 'member_number', 'amount_centavos', 'employer_share_centavos', 'compensation_centavos', 'remarks']) + ['employee_id' => null]
                        : [...$this->snapshot($employee, $kind), 'amount_centavos' => $line->amount_centavos, 'employer_share_centavos' => $line->employer_share_centavos, 'compensation_centavos' => $line->compensation_centavos, 'remarks' => $line->remarks];
                }
            } else {
                foreach ($this->eligible($kind)->get() as $employee) {
                    $rows[] = [...$this->snapshot($employee, $kind), ...$this->defaultAmounts($setting)];
                }
            }

            $this->insertLines($remittance, $rows);

            return ['remittance' => $remittance, 'copied' => count($rows), 'dropped' => $dropped];
        });
    }

    /**
     * Active COS workers who are not on the list yet, for the "add missing" button.
     *
     * @return list<Employee>
     */
    public function missing(Remittance $remittance): array
    {
        $listed = $remittance->lines()->whereNotNull('employee_id')->pluck('employee_id');

        return $this->eligible($remittance->kind)->whereNotIn('id', $listed)->get()->all();
    }

    /**
     * @return int how many people were added
     *
     * @throws UserFacingException when the month is finalized
     */
    public function addMissing(Remittance $remittance): int
    {
        $this->assertOpen($remittance);

        return DB::transaction(function () use ($remittance): int {
            $setting = RemittanceSetting::forKind($remittance->kind);
            $rows = array_map(fn (Employee $employee): array => [...$this->snapshot($employee, $remittance->kind), ...$this->defaultAmounts($setting)], $this->missing($remittance));

            $this->insertLines($remittance, $rows, $remittance->lines()->count());
            AuditTrail::record('update', 'remittances', $remittance->label, ['people' => ['from' => null, 'to' => 'added '.count($rows)]], $remittance->id);

            return count($rows);
        });
    }

    /**
     * Replace the list with the rows of the grid. Either every row is saved or none is.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return int rows saved
     *
     * @throws UserFacingException when the month is finalized or a row is wrong
     */
    public function saveLines(Remittance $remittance, array $rows): int
    {
        $this->assertOpen($remittance);

        if (count($rows) > self::MAX_LINES) {
            throw new UserFacingException('A remittance can list at most '.number_format(self::MAX_LINES).' people.');
        }

        $clean = [];
        $errors = [];

        foreach (array_values($rows) as $index => $row) {
            try {
                $clean[] = $this->cleanRow($row, $remittance->kind);
            } catch (InvalidArgumentException $exception) {
                $errors[] = 'Row '.($index + 1).': '.$exception->getMessage();
            }
        }

        if ($errors !== []) {
            throw new UserFacingException(implode("\n", array_slice($errors, 0, 8)).(count($errors) > 8 ? "\n…and ".(count($errors) - 8).' more.' : ''));
        }

        return DB::transaction(function () use ($remittance, $clean): int {
            $before = $this->totals($remittance);
            $remittance->lines()->delete();
            $this->insertLines($remittance, $clean);
            $after = $this->totals($remittance);
            $remittance->touch();

            AuditTrail::record('update', 'remittances', $remittance->label, ['people' => ['from' => $before['count'], 'to' => $after['count']], 'payable' => ['from' => Money::format($before['payable']), 'to' => Money::format($after['payable'])]], $remittance->id);

            return count($clean);
        });
    }

    /**
     * Lock the month. It needs people and a total above zero.
     *
     * @throws UserFacingException
     */
    public function finalize(Remittance $remittance): void
    {
        $this->assertOpen($remittance);
        $totals = $this->totals($remittance);

        if ($totals['count'] === 0 || $totals['payable'] <= 0) {
            throw new UserFacingException('List at least one person with an amount before finalizing.');
        }

        $remittance->update(['status' => RemittanceStatus::Finalized, 'finalized_at' => now()]);
    }

    public function reopen(Remittance $remittance): void
    {
        $remittance->update(['status' => RemittanceStatus::Draft, 'finalized_at' => null]);
    }

    /**
     * @return Totals
     */
    public function totals(Remittance $remittance): array
    {
        $sums = $remittance->lines()->reorder()->toBase()->selectRaw('count(*) as people, coalesce(sum(amount_centavos), 0) as gross, coalesce(sum(employer_share_centavos), 0) as employer, coalesce(sum(compensation_centavos), 0) as compensation')->first();
        $gross = (int) $sums->gross;
        $employer = (int) $sums->employer;

        $setting = RemittanceSetting::forKind($remittance->kind);
        $charge = $remittance->kind === RemittanceKind::Cmpeai ? (int) round($gross * $setting->service_charge_basis_points / 10_000) : 0;
        $net = $gross - $charge;

        return [
            'count' => (int) $sums->people,
            'gross' => $gross,
            'employer' => $employer,
            'compensation' => (int) $sums->compensation,
            'serviceCharge' => $charge,
            'net' => $net,
            'payable' => match ($remittance->kind) {
                RemittanceKind::Sss => $gross,
                RemittanceKind::Hdmf => $gross + $employer,
                RemittanceKind::Cmpeai => $net,
            },
        ];
    }

    /**
     * Things to fix before paying, such as people with no member number.
     *
     * @return list<string>
     */
    public function problems(Remittance $remittance): array
    {
        $problems = [];

        if ($remittance->kind->memberField() !== null) {
            $without = $remittance->lines()->where(fn (Builder $query) => $query->whereNull('member_number')->orWhere('member_number', ''))->count();

            if ($without > 0) {
                $problems[] = "{$without} ".($without === 1 ? 'person has' : 'people have').' no '.$remittance->kind->memberLabel().'.';
            }
        }

        $none = $remittance->lines()->where('amount_centavos', 0)->count();

        if ($none > 0) {
            $problems[] = "{$none} ".($none === 1 ? 'person has' : 'people have').' no amount.';
        }

        return $problems;
    }

    /**
     * The letter for the voucher's particulars, with {period} and {amount} filled in.
     */
    public function particulars(Remittance $remittance, int $payable): string
    {
        $template = RemittanceSetting::forKind($remittance->kind)->voucher_particulars
            ?: 'To payment of COS '.$remittance->kind->shortLabel().' remittance for the period covered {period}, in the amount of {amount}.';

        return strtr($template, ['{period}' => $remittance->periodLabel(), '{amount}' => Money::words($payable).' ('.Money::format($payable).')']);
    }

    /**
     * Copy an employee's current details onto a line.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Employee $employee, RemittanceKind $kind): array
    {
        $field = $kind->memberField();

        return [
            'employee_id' => $employee->id,
            'last_name' => $employee->last_name,
            'first_name' => $employee->first_name,
            'middle_name' => $employee->middle_name,
            'extension' => $employee->extension,
            'birth_date' => $employee->date_of_birth?->toDateString(),
            'station' => $employee->station?->name,
            'member_number' => $field === null ? null : $employee->{$field},
            'remarks' => null,
        ];
    }

    private function period(int $year, int $month): string
    {
        return (new Remittance(['year' => $year, 'month' => $month]))->periodLabel();
    }

    /**
     * @return Builder<Employee>
     */
    private function eligible(RemittanceKind $kind): Builder
    {
        $field = $kind->memberField();

        return Employee::query()->with('station')
            ->where('classification', Classification::Cos->value)
            ->where('employment_status', EmploymentStatus::Active->value)
            ->when($field !== null, fn (Builder $query) => $query->whereNotNull($field)->where($field, '!=', ''))
            ->orderBy('full_name');
    }

    /**
     * @return array{amount_centavos: int, employer_share_centavos: int, compensation_centavos: int}
     */
    private function defaultAmounts(RemittanceSetting $setting): array
    {
        return [
            'amount_centavos' => (int) $setting->default_amount_centavos,
            'employer_share_centavos' => (int) $setting->default_employer_share_centavos,
            'compensation_centavos' => (int) $setting->default_compensation_centavos,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertLines(Remittance $remittance, array $rows, int $offset = 0): void
    {
        foreach (array_chunk($rows, 500, true) as $chunk) {
            RemittanceLine::query()->insert(array_map(fn (array $row, int $index): array => [
                'remittance_id' => $remittance->id,
                'employee_id' => $row['employee_id'] ?? null,
                'position' => $offset + $index + 1,
                'last_name' => $row['last_name'],
                'first_name' => $row['first_name'],
                'middle_name' => $row['middle_name'] ?? null,
                'extension' => $row['extension'] ?? null,
                'birth_date' => HrDate::toYmd($row['birth_date'] ?? null),
                'station' => $row['station'] ?? null,
                'member_number' => $row['member_number'] ?? null,
                'amount_centavos' => $row['amount_centavos'] ?? 0,
                'employer_share_centavos' => $row['employer_share_centavos'] ?? 0,
                'compensation_centavos' => $row['compensation_centavos'] ?? 0,
                'remarks' => $row['remarks'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk, array_keys($chunk)));
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException with a message that names the problem
     */
    private function cleanRow(array $row, RemittanceKind $kind): array
    {
        $text = fn (string $key, int $max): ?string => mb_substr(trim((string) ($row[$key] ?? '')), 0, $max) ?: null;
        $last = $text('last_name', 80);
        $first = $text('first_name', 80);

        if ($last === null || $first === null) {
            throw new InvalidArgumentException('needs a last name and a first name.');
        }

        $birth = $text('birth_date', 10);

        if ($birth !== null && ! HrDate::isValid($birth)) {
            throw new InvalidArgumentException("birth date \"{$birth}\" is not a valid date (use YYYY-MM-DD).");
        }

        $amount = function (string $key, string $label) use ($row): int {
            try {
                return Money::toCentavos($row[$key] ?? null);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException("{$label} {$exception->getMessage()}");
            }
        };

        $employeeId = (int) ($row['employee_id'] ?? 0);

        return [
            'employee_id' => $employeeId > 0 && Employee::query()->whereKey($employeeId)->exists() ? $employeeId : null,
            'last_name' => $last,
            'first_name' => $first,
            'middle_name' => $text('middle_name', 80),
            'extension' => $text('extension', 10),
            'birth_date' => $birth,
            'station' => $text('station', 120),
            'member_number' => $text('member_number', 30),
            'amount_centavos' => $amount('amount', $kind->amountLabel()),
            'employer_share_centavos' => $kind === RemittanceKind::Hdmf ? $amount('employer_share', 'Employer share') : 0,
            'compensation_centavos' => $kind === RemittanceKind::Hdmf ? $amount('compensation', 'Monthly compensation') : 0,
            'remarks' => $text('remarks', 255),
        ];
    }

    /**
     * @throws UserFacingException
     */
    private function assertOpen(Remittance $remittance): void
    {
        if ($remittance->isFinalized()) {
            throw new UserFacingException('This month is finalized. Reopen it to make changes.');
        }
    }
}
