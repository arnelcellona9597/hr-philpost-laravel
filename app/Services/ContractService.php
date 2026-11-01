<?php

namespace App\Services;

use App\Enums\Classification;
use App\Enums\ContractStatus;
use App\Enums\EmploymentStatus;
use App\Enums\RateType;
use App\Exceptions\UserFacingException;
use App\Models\Contract;
use App\Models\ContractSetting;
use App\Models\ContractTemplate;
use App\Models\DocumentSequence;
use App\Models\Employee;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\CertificateBlocks;
use App\Support\ContractBlocks;
use App\Support\HrDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issues Contracts of Service, one at a time or for a whole group in one go. The merged text is frozen
 * on the record, so a reprint is identical even after the template, the officials or the person change.
 *
 * @phpstan-import-type Terms from ContractBlocks
 */
class ContractService
{
    public const MAX_BULK = 300;

    /**
     * The starting terms of a person's next contract, taken from their record and the shared settings.
     *
     * @return array<string, mixed>
     */
    public function defaults(Employee $employee, ContractSetting $settings, ?string $termStart = null, ?string $termEnd = null): array
    {
        $employee->loadMissing('station');
        $last = Contract::query()->where('employee_id', $employee->id)->where('status', ContractStatus::Valid->value)->orderByDesc('term_end')->first();

        return [
            'position' => (string) $employee->position,
            'office' => (string) $employee->station?->name,
            'place_of_service' => $last?->place_of_service ?: (string) ($settings->default_place_of_service ?: $employee->station?->name),
            'address' => (string) $employee->address,
            'term_start' => $termStart ?? '',
            'term_end' => $termEnd ?? '',
            'daily_wage' => $this->dailyWage($employee, $last),
        ];
    }

    /**
     * The worker's daily rate from their pay history, or else the wage on their last contract.
     */
    private function dailyWage(Employee $employee, ?Contract $last): string
    {
        $rate = app(PayHistoryService::class)->rateOn($employee);
        $centavos = $rate?->rate_type === RateType::Daily ? $rate->amount_centavos : $last?->daily_wage_centavos;

        return $centavos === null ? '' : number_format($centavos / 100, 2, '.', '');
    }

    /**
     * Active COS workers who have no valid contract overlapping the term, for the issue screen.
     *
     * @return list<Employee>
     */
    public function candidates(string $termStart, string $termEnd): array
    {
        return Employee::query()->with('station')
            ->where('classification', Classification::Cos->value)
            ->where('employment_status', EmploymentStatus::Active->value)
            ->whereNotExists(fn ($query) => $query->from('contracts')
                ->whereColumn('contracts.employee_id', 'employees.id')
                ->where('contracts.status', ContractStatus::Valid->value)
                ->where('contracts.term_start', '<=', $termEnd)
                ->where('contracts.term_end', '>=', $termStart))
            ->orderBy('full_name')
            ->limit(self::MAX_BULK + 1)
            ->get()
            ->all();
    }

    /**
     * The frozen content of a contract. Its keys match the certificate content, so the same renderers draw it.
     *
     * @param  Terms  $terms
     * @return array{blocks: list<array<string, mixed>>, paperSize: string, font: string, margin: string, companyName: string, logoId: int|null}
     */
    public function compose(ContractTemplate $template, Employee $employee, array $terms, string $issuedDate, ?string $controlNumber): array
    {
        $site = SiteSetting::current();
        $values = ContractBlocks::values($employee, $terms, ContractSetting::current(), $issuedDate, $controlNumber, $site->company_name);

        return [
            'blocks' => CertificateBlocks::merge(CertificateBlocks::sanitize($template->blocks, ContractBlocks::fields()), $values),
            'paperSize' => $template->paper_size,
            'font' => $template->font,
            'margin' => $template->margin,
            'companyName' => $site->company_name,
            'logoId' => $site->logo_id,
        ];
    }

    /**
     * Issue one contract.
     *
     * @param  array<string, mixed>  $row  The fields of {@see self::terms()}
     *
     * @throws UserFacingException
     */
    public function issue(User $user, ?int $templateId, ?int $employeeId, array $row, ?string $issuedDate): Contract
    {
        return $this->issueMany($user, $templateId, [[...$row, 'employee' => $employeeId]], $issuedDate)[0];
    }

    /**
     * Issue a contract for each row, all or none. Every row is checked first, and the messages name the row.
     *
     * @param  list<array<string, mixed>>  $rows  Each with `employee` and the fields of {@see self::terms()}
     * @return list<Contract>
     *
     * @throws UserFacingException
     */
    public function issueMany(User $user, ?int $templateId, array $rows, ?string $issuedDate): array
    {
        $template = $templateId ? ContractTemplate::query()->find($templateId) : null;

        if ($template === null) {
            throw new UserFacingException('Choose a contract template.');
        }

        if (! $template->active) {
            throw new UserFacingException('This contract template is inactive. Activate it or choose another one.');
        }

        $issuedDate = $issuedDate ?: HrDate::today();

        if (! HrDate::isValid($issuedDate)) {
            throw new UserFacingException('Enter a valid date issued.');
        }

        if ($rows === []) {
            throw new UserFacingException('Choose at least one person.');
        }

        if (count($rows) > self::MAX_BULK) {
            throw new UserFacingException('Issue at most '.self::MAX_BULK.' contracts at a time.');
        }

        // Layout errors surface before a number is claimed.
        CertificateBlocks::sanitize($template->blocks, ContractBlocks::fields());

        $settings = ContractSetting::current();

        return DB::transaction(function () use ($user, $template, $rows, $issuedDate, $settings): array {
            $employees = Employee::query()->with('station')->whereIn('id', array_filter(array_map(fn (array $row): int => (int) ($row['employee'] ?? 0), $rows)))->lockForUpdate()->get()->keyBy('id');
            $prepared = [];
            $errors = [];

            foreach (array_values($rows) as $index => $row) {
                $employee = $employees->get((int) ($row['employee'] ?? 0));
                $name = $employee?->full_name ?? 'Row '.($index + 1);

                try {
                    if ($employee === null) {
                        throw new InvalidArgumentException('is not an employee.');
                    }

                    $terms = $this->terms($row, $settings);
                    $this->assertNoOverlap($employee, $terms, $prepared);
                    $prepared[] = [$employee, $terms];
                } catch (InvalidArgumentException $exception) {
                    $errors[] = "{$name}: {$exception->getMessage()}";
                }
            }

            if ($errors !== []) {
                throw new UserFacingException(implode("\n", array_slice($errors, 0, 10)).(count($errors) > 10 ? "\n…and ".(count($errors) - 10).' more.' : ''));
            }

            $contracts = [];

            foreach ($prepared as [$employee, $terms]) {
                $controlNumber = DocumentSequence::nextContractNumber($template->prefix, (int) substr($issuedDate, 0, 4));
                $contract = new Contract(['template_id' => $template->id, 'employee_id' => $employee->id]);
                $contract->forceFill([
                    'control_number' => $controlNumber,
                    'template_name' => $template->name,
                    'employee_name' => ContractBlocks::values($employee, $terms, $settings, $issuedDate, null, '')['fullName'],
                    ...$terms,
                    'content' => $this->compose($template, $employee, $terms, $issuedDate, $controlNumber),
                    'issued_by' => $user->id,
                    'issued_by_name' => $user->name,
                    'status' => ContractStatus::Valid,
                ])->save();
                $contracts[] = $contract;
            }

            return $contracts;
        });
    }

    /**
     * Voiding is the only change an issued contract accepts, and it cannot be undone.
     *
     * @throws UserFacingException without a reason, or when it is already void
     */
    public function void(Contract $contract, ?string $reason): void
    {
        $reason = trim((string) $reason);

        if ($contract->isVoid()) {
            throw new UserFacingException('This contract is already void. Issue a new one instead.');
        }

        if ($reason === '') {
            throw new UserFacingException('Give a reason for voiding the contract.');
        }

        $contract->forceFill([
            'status' => ContractStatus::Void,
            'void_reason' => mb_substr($reason, 0, 300),
            'voided_at' => now(),
        ])->save();
    }

    /**
     * Check one row of terms and turn it into stored values.
     *
     * @param  array<string, mixed>  $row
     * @return Terms
     *
     * @throws InvalidArgumentException with a message that finishes "{name}: …"
     */
    public function terms(array $row, ContractSetting $settings): array
    {
        $text = fn (string $key, int $max): string => mb_substr(trim((string) ($row[$key] ?? '')), 0, $max);
        $start = $text('term_start', 10);
        $end = $text('term_end', 10);

        if (! HrDate::isValid($start) || ! HrDate::isValid($end)) {
            throw new InvalidArgumentException('needs a start and end date (YYYY-MM-DD).');
        }

        if ($end < $start) {
            throw new InvalidArgumentException('ends before it starts.');
        }

        if ($text('position', 120) === '') {
            throw new InvalidArgumentException('needs a position.');
        }

        try {
            $daily = Money::toCentavos($row['daily_wage'] ?? null);
            $second = Money::toCentavos($row['second_wage'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('wage '.$exception->getMessage().'.');
        }

        if ($daily <= 0) {
            throw new InvalidArgumentException('needs a daily wage.');
        }

        $secondFrom = $text('second_wage_from', 10);

        if ($second > 0 && ! HrDate::isValid($secondFrom)) {
            throw new InvalidArgumentException('has a second wage but no valid date for it to start.');
        }

        $premium = (int) ($row['premium_percent'] ?? $settings->premium_percent);

        if ($premium < 0 || $premium > 100) {
            throw new InvalidArgumentException('premium must be between 0 and 100 percent.');
        }

        return [
            'position' => $text('position', 120),
            'office' => $text('office', 160),
            'place_of_service' => $text('place_of_service', 160) ?: (string) $settings->default_place_of_service,
            'address' => $text('address', 255),
            'term_start' => $start,
            'term_end' => $end,
            'daily_wage_centavos' => $daily,
            'second_wage_centavos' => $second > 0 ? $second : null,
            'second_wage_from' => $second > 0 ? $secondFrom : null,
            'wage_order' => ($text('wage_order', 255) ?: (string) $settings->wage_order) ?: null,
            'premium_percent' => $premium,
        ];
    }

    /**
     * @param  Terms  $terms
     * @param  list<array{0: Employee, 1: array<string, mixed>}>  $prepared  Rows already accepted in this batch
     *
     * @throws InvalidArgumentException
     */
    private function assertNoOverlap(Employee $employee, array $terms, array $prepared): void
    {
        $existing = Contract::query()
            ->where('employee_id', $employee->id)
            ->where('status', ContractStatus::Valid->value)
            ->where('term_start', '<=', $terms['term_end'])
            ->where('term_end', '>=', $terms['term_start'])
            ->first();

        if ($existing !== null) {
            throw new InvalidArgumentException("already has contract {$existing->control_number} from ".HrDate::format($existing->term_start).' to '.HrDate::format($existing->term_end).'. Void it first, or choose other dates.');
        }

        foreach ($prepared as [$other, $otherTerms]) {
            if ($other->id === $employee->id && $otherTerms['term_start'] <= $terms['term_end'] && $otherTerms['term_end'] >= $terms['term_start']) {
                throw new InvalidArgumentException('is listed twice with overlapping dates.');
            }
        }
    }
}
