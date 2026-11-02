<?php

namespace App\Support;

use App\Enums\Gender;
use App\Models\ContractScope;
use App\Models\ContractSetting;
use App\Models\Employee;
use App\Services\PayHistoryService;

/**
 * The placeholders a Contract of Service can use, and the values merged into them at the moment of issue.
 * Blocks themselves (validation, merging, rendering) come from {@see CertificateBlocks}.
 *
 * @phpstan-type Terms array{position: string, office: string, place_of_service: string, address: string, term_start: string, term_end: string, daily_wage_centavos: int, second_wage_centavos: int|null, second_wage_from: string|null, wage_order: string|null, premium_percent: int, contract_scope_id: int|null}
 */
class ContractBlocks
{
    private const SCOPE_CLAUSE = 6;

    private const PERSONAL = [
        'fullName' => ['label' => 'Full name', 'sample' => 'Juan A. Dela Cruz'],
        'fullNameUpper' => ['label' => 'Full name (capitals)', 'sample' => 'JUAN A. DELA CRUZ'],
        'fullNameTitle' => ['label' => 'Full name (Juan A. Dela Cruz, even if stored in capitals)', 'sample' => 'Juan A. Dela Cruz'],
        'firstName' => ['label' => 'First name', 'sample' => 'Juan'],
        'lastName' => ['label' => 'Last name', 'sample' => 'Dela Cruz'],
        'honorific' => ['label' => 'Mr. / Ms.', 'sample' => 'Mr.'],
        'pronoun' => ['label' => 'he / she', 'sample' => 'he'],
        'pronounCap' => ['label' => 'He / She', 'sample' => 'He'],
        'possessive' => ['label' => 'his / her', 'sample' => 'his'],
        'areaDirectorPossessive' => ['label' => 'his / her (the Area Director)', 'sample' => 'his'],
        'areaDirectorUpper' => ['label' => 'Area Director (name in capitals)', 'sample' => 'ANA R. REYES'],
        'address' => ['label' => 'Home address', 'sample' => '123 Rizal St., Sample City'],
        'employeeId' => ['label' => 'Employee ID', 'sample' => 'EMP-0001'],
    ];

    private const TERMS = [
        'position' => ['label' => 'Position', 'sample' => 'Mail Handler'],
        'office' => ['label' => 'Office / unit', 'sample' => 'Sample Area Office'],
        'placeOfService' => ['label' => 'Supervisor (place of service)', 'sample' => 'Postmaster of Sample Post Office'],
        'scopeOfService' => ['label' => 'Scope of service (numbered list)', 'sample' => "6.1 Attends to the receiving, sorting, and routing of each mail item;\n6.2 Performs other related duties as assigned by the immediate superior."],
        'termStart' => ['label' => 'Term starts', 'sample' => 'July 1, 2026'],
        'termEnd' => ['label' => 'Term ends', 'sample' => 'December 31, 2026'],
        'termYear' => ['label' => 'Year of the term', 'sample' => '2026'],
        'dailyWage' => ['label' => 'Daily wage (figures)', 'sample' => '₱450.00'],
        'dailyWageWords' => ['label' => 'Daily wage (words)', 'sample' => 'Four Hundred Fifty Pesos'],
        'secondWage' => ['label' => 'Second daily wage (figures)', 'sample' => '₱470.00'],
        'secondWageWords' => ['label' => 'Second daily wage (words)', 'sample' => 'Four Hundred Seventy Pesos'],
        'secondWageFrom' => ['label' => 'Second wage starts', 'sample' => 'October 1, 2026'],
        'wageOrder' => ['label' => 'Wage order', 'sample' => 'RB XI-24 tranche 2'],
        'premiumPercent' => ['label' => 'Premium (%)', 'sample' => '15'],
        'remuneration' => ['label' => 'Remuneration sentence (all of the above, composed)', 'sample' => 'Five Hundred Twenty Five Pesos (₱ 525.00) from March 13, 2026 to August 31, 2026, effective September 1, 2026, the new minimum wage rate as per Wage Order No. RB XI-24 tranche 2 is Five Hundred Forty Pesos (₱ 540.00) and Fifteen percent (15%) premium'],
    ];

    private const ISSUE = [
        'issuedDate' => ['label' => 'Date issued', 'sample' => 'June 20, 2026'],
        'issuedDay' => ['label' => 'Day issued (e.g. 20th)', 'sample' => '20th'],
        'issuedMonthYear' => ['label' => 'Month and year issued', 'sample' => 'June 2026'],
        'controlNumber' => ['label' => 'Contract number', 'sample' => 'COS-2026-0001'],
        'companyName' => ['label' => 'Company name', 'sample' => 'HR Management System'],
    ];

    /**
     * Every placeholder, with the four officials added from {@see ContractSetting::OFFICIALS}.
     *
     * @return array<string, array{label: string, sample: string}>
     */
    public static function fields(): array
    {
        $officials = [];
        $samples = ['area_director' => 'Ana R. Reyes', 'adfin_chief' => 'Ben T. Santos', 'hrm_chief' => 'Carla M. Lim', 'budget_officer' => 'Dan P. Cruz'];

        foreach (ContractSetting::OFFICIALS as $key => $title) {
            $name = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            $officials[$name] = ['label' => "{$title} (name)", 'sample' => $samples[$key]];
            $officials["{$name}Title"] = ['label' => "{$title} (title)", 'sample' => $title];
            $officials["{$name}Initials"] = ['label' => "{$title} (initials)", 'sample' => self::initialsOf($samples[$key])];
        }

        return [...self::PERSONAL, ...self::TERMS, ...self::ISSUE, ...$officials];
    }

    /**
     * Placeholder values shown in the template editor.
     *
     * @return array<string, string>
     */
    public static function sampleValues(): array
    {
        return array_map(fn (array $field): string => $field['sample'], self::fields());
    }

    /**
     * Placeholder values for one person and one contract.
     *
     * @param  Terms  $terms
     * @return array<string, string>
     */
    public static function values(Employee $employee, array $terms, ContractSetting $settings, string $issuedDate, ?string $controlNumber, string $companyName, ?ContractScope $scope = null): array
    {
        $female = $employee->gender === Gender::Female;
        $fullName = Names::firstLast($employee->last_name, $employee->first_name, $employee->middle_name, $employee->extension);
        $second = $terms['second_wage_centavos'];
        $hasSecond = $second !== null && $second > 0 && $terms['second_wage_from'] !== null;
        $values = [
            'fullName' => $fullName,
            'fullNameUpper' => mb_strtoupper($fullName),
            'fullNameTitle' => mb_convert_case($fullName, MB_CASE_TITLE),
            'firstName' => trim((string) $employee->first_name),
            'lastName' => trim((string) $employee->last_name),
            'honorific' => $female ? 'Ms.' : 'Mr.',
            'pronoun' => $female ? 'she' : 'he',
            'pronounCap' => $female ? 'She' : 'He',
            'possessive' => $female ? 'her' : 'his',
            'areaDirectorPossessive' => 'his',
            'areaDirectorUpper' => mb_strtoupper((string) $settings->area_director_name),
            'address' => $terms['address'],
            'employeeId' => (string) $employee->employee_id,
            'position' => $terms['position'],
            'office' => $terms['office'],
            'placeOfService' => $terms['place_of_service'],
            'scopeOfService' => self::scopeList($scope),
            'termStart' => date('F d, Y', (int) strtotime($terms['term_start'])),
            'termEnd' => date('F d, Y', (int) strtotime($terms['term_end'])),
            'termYear' => substr($terms['term_start'], 0, 4),
            'dailyWage' => Money::format($terms['daily_wage_centavos']),
            'dailyWageWords' => Money::pesosInWords($terms['daily_wage_centavos']),
            'secondWage' => $hasSecond ? Money::format($second) : '',
            'secondWageWords' => $hasSecond ? Money::pesosInWords($second) : '',
            'secondWageFrom' => $hasSecond ? CertificateBlocks::longDate($terms['second_wage_from']) : '',
            'wageOrder' => (string) $terms['wage_order'],
            'premiumPercent' => (string) $terms['premium_percent'],
            'issuedDate' => CertificateBlocks::longDate($issuedDate),
            'issuedDay' => CertificateBlocks::ordinal((int) substr($issuedDate, 8, 2)),
            'issuedMonthYear' => CertificateBlocks::longDate($issuedDate) === '' ? '' : date('F Y', (int) strtotime($issuedDate)),
            'controlNumber' => (string) $controlNumber,
            'companyName' => $companyName,
        ];

        $values['remuneration'] = self::remuneration($values, $terms, $hasSecond, $employee);

        foreach (array_keys(ContractSetting::OFFICIALS) as $key) {
            $name = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            $values[$name] = (string) $settings->{"{$key}_name"};
            $values["{$name}Title"] = (string) ($settings->{"{$key}_title"} ?: ContractSetting::OFFICIALS[$key]);
            $values["{$name}Initials"] = self::initialsOf((string) $settings->{"{$key}_name"});
        }

        return $values;
    }

    /**
     * The duties as a numbered list under clause 6 of the contract.
     */
    private static function scopeList(?ContractScope $scope): string
    {
        $number = 1;

        return implode("\n", array_map(function (string $item) use (&$number): string {
            return self::SCOPE_CLAUSE.'.'.$number++." {$item}";
        }, $scope?->items ?? []));
    }

    /**
     * The office's wage sentence: one rate, or a rate that changes during the term under a wage order.
     *
     * @param  array<string, string>  $values
     * @param  Terms  $terms
     */
    private static function remuneration(array $values, array $terms, bool $hasSecond, Employee $employee): string
    {
        $figure = fn (int $centavos): string => '₱ '.number_format($centavos / 100, 2);
        $text = "{$values['dailyWageWords']} ({$figure($terms['daily_wage_centavos'])})";

        if ($hasSecond) {
            $from = app(PayHistoryService::class)->rateOn($employee, $terms['term_start'])?->effective_on?->toDateString() ?? $terms['term_start'];
            $order = trim((string) $terms['wage_order']) === '' ? 'the new rate' : "the new minimum wage rate as per Wage Order No. {$terms['wage_order']}";
            $text .= ' from '.CertificateBlocks::longDate($from).' to '.CertificateBlocks::longDate(HrDate::addDays($terms['second_wage_from'], -1)).", effective {$values['secondWageFrom']}, {$order} is {$values['secondWageWords']} ({$figure($terms['second_wage_centavos'])})";
        }

        if ($terms['premium_percent'] > 0) {
            $text .= ' and '.ucfirst(mb_strtolower(Money::numberInWords($terms['premium_percent'])))." percent ({$terms['premium_percent']}%) premium";
        }

        return $text;
    }

    /**
     * "Ana R. Reyes" becomes "AR": the first and last words, as officials initial a page.
     */
    public static function initialsOf(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        return mb_strtoupper(mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    }
}
