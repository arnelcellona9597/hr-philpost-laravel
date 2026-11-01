<?php

namespace App\Support;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Enums\RateType;
use App\Exceptions\UserFacingException;
use App\Models\ContractSetting;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Services\PayHistoryService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * The block model behind certificate templates: the block types, the {{placeholders}} a template
 * can use, validation of what the editor posts, and merging at the moment of issue.
 *
 * @phpstan-type Block array<string, mixed>
 */
class CertificateBlocks
{
    public const PAPER_SIZES = ['A4', 'Letter', 'Legal'];

    public const FONTS = ['serif', 'sans'];

    public const MARGINS = ['narrow', 'normal', 'wide'];

    /**
     * Page sizes in PDF points.
     */
    public const PAPER_POINTS = ['A4' => [595.28, 841.89], 'Letter' => [612, 792], 'Legal' => [612, 1008]];

    public const MARGIN_POINTS = ['narrow' => 50, 'normal' => 72, 'wide' => 96];

    public const SPACER_POINTS = ['sm' => 8, 'md' => 18, 'lg' => 32, 'xl' => 56];

    public const MAX_BLOCKS = 40;

    public const DEFAULT_PURPOSE = 'whatever legal purpose it may serve';

    public const BLOCK_TYPES = [
        ['type' => 'letterhead', 'label' => 'Office letterhead', 'description' => 'The letterhead set under Branding & Theme', 'icon' => 'building'],
        ['type' => 'header', 'label' => 'Simple header', 'description' => 'Logo, company name and address lines', 'icon' => 'building'],
        ['type' => 'title', 'label' => 'Title', 'description' => 'e.g. CERTIFICATION', 'icon' => 'file'],
        ['type' => 'paragraph', 'label' => 'Paragraph', 'description' => 'Text with {{placeholders}}; **bold**', 'icon' => 'edit'],
        ['type' => 'details', 'label' => 'Details table', 'description' => 'Employee details as label: value rows', 'icon' => 'sheet'],
        ['type' => 'signatory', 'label' => 'Signatories', 'description' => 'Up to 4 names with titles and signature lines', 'icon' => 'user'],
        ['type' => 'spacer', 'label' => 'Space', 'description' => 'Empty vertical space', 'icon' => 'minus'],
        ['type' => 'divider', 'label' => 'Line', 'description' => 'A thin horizontal rule', 'icon' => 'minus'],
        ['type' => 'footer', 'label' => 'Footnote', 'description' => 'Small print, e.g. "Not valid without dry seal"', 'icon' => 'info'],
    ];

    /**
     * The blocks offered when building a contract: certificates' blocks without the details table and
     * footnote, plus a page break and a footer that repeats on every page.
     */
    public const CONTRACT_BLOCK_TYPES = [
        ['type' => 'header', 'label' => 'Letterhead', 'description' => 'Logo, company name and address lines', 'icon' => 'building'],
        ['type' => 'title', 'label' => 'Title', 'description' => 'e.g. CONTRACT OF SERVICE', 'icon' => 'file'],
        ['type' => 'paragraph', 'label' => 'Paragraph / clause', 'description' => 'Text with {{placeholders}}; **bold** for clause headings', 'icon' => 'edit'],
        ['type' => 'signatory', 'label' => 'Signatories', 'description' => 'Up to 4 names with titles and signature lines', 'icon' => 'user'],
        ['type' => 'spacer', 'label' => 'Space', 'description' => 'Empty vertical space', 'icon' => 'minus'],
        ['type' => 'divider', 'label' => 'Line', 'description' => 'A thin horizontal rule', 'icon' => 'minus'],
        ['type' => 'pageBreak', 'label' => 'Page break', 'description' => 'Start the next block on a new page', 'icon' => 'file'],
        ['type' => 'pageFooter', 'label' => 'Page footer', 'description' => 'Printed at the bottom of every page, e.g. initials', 'icon' => 'info'],
    ];

    public const FIELDS = [
        'fullName' => ['label' => 'Full name', 'sample' => 'Juan A. Dela Cruz'],
        'fullNameUpper' => ['label' => 'Full name (capitals)', 'sample' => 'JUAN A. DELA CRUZ'],
        'firstName' => ['label' => 'First name', 'sample' => 'Juan'],
        'lastName' => ['label' => 'Last name', 'sample' => 'Dela Cruz'],
        'lastNameTitle' => ['label' => 'Last name (Dela Cruz, even if stored in capitals)', 'sample' => 'Dela Cruz'],
        'honorific' => ['label' => 'Mr. / Ms.', 'sample' => 'Mr.'],
        'honorificUpper' => ['label' => 'MR. / MS.', 'sample' => 'MR.'],
        'pronoun' => ['label' => 'he / she', 'sample' => 'he'],
        'pronounCap' => ['label' => 'He / She', 'sample' => 'He'],
        'possessive' => ['label' => 'his / her', 'sample' => 'his'],
        'possessiveCap' => ['label' => 'His / Her', 'sample' => 'His'],
        'employeeId' => ['label' => 'Employee ID', 'sample' => 'EMP-0001'],
        'position' => ['label' => 'Position', 'sample' => 'Administrative Officer IV'],
        'positionWithArticle' => ['label' => 'Position with a / an', 'sample' => 'an Administrative Officer IV'],
        'station' => ['label' => 'Station / branch', 'sample' => 'PENRO Batangas'],
        'classification' => ['label' => 'Classification', 'sample' => 'Regular'],
        'engagementType' => ['label' => 'Contract of Service (COS) worker / Service Contractor', 'sample' => 'Contract of Service (COS) worker'],
        'employmentStatus' => ['label' => 'Employment status', 'sample' => 'Active'],
        'dateHired' => ['label' => 'Date hired', 'sample' => 'March 5, 2018'],
        'lastDayOfService' => ['label' => 'Last day of service', 'sample' => 'September 30, 2026'],
        'lengthOfService' => ['label' => 'Length of service', 'sample' => '8 years and 6 months'],
        'firstDateHired' => ['label' => 'First date hired (if hired again)', 'sample' => 'March 5, 2014'],
        'totalLengthOfService' => ['label' => 'Total service in all periods', 'sample' => '12 years and 3 months'],
        'employmentCount' => ['label' => 'Times employed', 'sample' => '2'],
        'separation' => ['label' => 'resigned / retired / was separated', 'sample' => 'resigned'],
        'purpose' => ['label' => 'Purpose (entered when issuing)', 'sample' => 'loan application'],
        'issuedDate' => ['label' => 'Date issued', 'sample' => 'October 4, 2026'],
        'issuedDay' => ['label' => 'Day issued (e.g. 4th)', 'sample' => '4th'],
        'issuedMonthYear' => ['label' => 'Month and year issued', 'sample' => 'October 2026'],
        'issuedMonth' => ['label' => 'Month issued', 'sample' => 'October'],
        'issuedYear' => ['label' => 'Year issued', 'sample' => '2026'],
        'dailyWage' => ['label' => 'Daily wage (figures, from Compensation History)', 'sample' => 'Php540.00'],
        'dailyWageWords' => ['label' => 'Daily wage (words)', 'sample' => 'Five Hundred Forty Pesos'],
        'premiumWords' => ['label' => 'Premium (words and figures)', 'sample' => 'fifteen percent (15%)'],
        'compensationClause' => ['label' => 'Wage sentence, starting with a space', 'sample' => ' with a daily wage rate of Five Hundred Forty Pesos (Php540.00) plus a premium equivalent of fifteen percent (15%) to his daily wage rate'],
        'controlNumber' => ['label' => 'Control number', 'sample' => 'COE-2026-0001'],
        'companyName' => ['label' => 'Company name', 'sample' => 'HR Management System'],
        'hrmChief' => ['label' => 'HRM Chief (name, from Officials and wages)', 'sample' => 'Juana S. Santos'],
        'hrmChiefTitle' => ['label' => 'HRM Chief (title)', 'sample' => 'Chief, Human Resource Management Section'],
    ];

    private const MAX_TEXT = 3000;

    /**
     * A new block of this type with its starting values.
     *
     * @return Block
     */
    public static function newBlock(string $type): array
    {
        $block = match ($type) {
            'letterhead' => [],
            'header' => ['showLogo' => true, 'companyName' => '', 'lines' => '', 'align' => 'center'],
            'title' => ['text' => 'CERTIFICATION', 'size' => 'lg', 'align' => 'center', 'wide' => true],
            'paragraph' => ['text' => 'Type your text here. Use {{fullName}} and other placeholders.', 'align' => 'justify', 'size' => 'md', 'indent' => true],
            'details' => ['fields' => ['fullName', 'position', 'station', 'dateHired']],
            'signatory' => ['align' => 'right', 'signers' => [['label' => '', 'name' => '', 'title' => 'HR Officer']]],
            'spacer' => ['size' => 'md'],
            'divider' => [],
            'footer' => ['text' => 'Not valid without the official dry seal.', 'align' => 'left'],
            'pageBreak' => [],
            'pageFooter' => ['text' => '', 'align' => 'center'],
        };

        return ['id' => self::newId(), 'type' => $type, ...$block];
    }

    /**
     * The starting layout of a template created from scratch.
     *
     * @return list<Block>
     */
    public static function starterLayout(): array
    {
        return [
            self::newBlock('header'),
            self::newBlock('divider'),
            [...self::newBlock('spacer'), 'size' => 'lg'],
            self::newBlock('title'),
            [...self::newBlock('spacer'), 'size' => 'lg'],
            self::newBlock('paragraph'),
            [...self::newBlock('spacer'), 'size' => 'xl'],
            self::newBlock('signatory'),
        ];
    }

    /**
     * Placeholder values shown in the template editor.
     *
     * @return array<string, string>
     */
    public static function sampleValues(): array
    {
        return array_map(fn (array $field): string => $field['sample'], self::FIELDS);
    }

    /**
     * Check and normalise a template's blocks. Layouts come from the browser, so nothing is trusted.
     *
     * @param  array<string, array{label: string, sample: string}>|null  $fields  The placeholders this kind of document allows
     * @return list<Block>
     *
     * @throws UserFacingException with a message for the person editing the template
     */
    public static function sanitize(mixed $input, ?array $fields = null): array
    {
        $fields ??= self::FIELDS;
        $raw = is_string($input) ? json_decode($input, true) : $input;

        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new UserFacingException('The template layout is not valid.');
        }

        if ($raw === []) {
            throw new UserFacingException('Add at least one block to the template.');
        }

        if (count($raw) > self::MAX_BLOCKS) {
            throw new UserFacingException('A template can have at most '.self::MAX_BLOCKS.' blocks.');
        }

        $ids = [];
        $blocks = [];

        foreach ($raw as $block) {
            $block = is_array($block) ? $block : [];
            $id = is_string($block['id'] ?? null) && preg_match('/^[a-z0-9]{1,16}$/', $block['id']) ? $block['id'] : self::newId();

            while (isset($ids[$id])) {
                $id = self::newId();
            }

            $ids[$id] = true;
            $blocks[] = ['id' => $id, ...self::sanitizeBlock($block, $fields)];
        }

        $unknown = self::unknownPlaceholders($blocks, $fields);

        if ($unknown !== []) {
            throw new UserFacingException('Unknown placeholder(s): '.implode(', ', array_map(fn (string $name): string => "{{{$name}}}", $unknown)));
        }

        return $blocks;
    }

    /**
     * @param  list<Block>  $blocks
     * @param  array<string, mixed>|null  $fields
     * @return list<string>
     */
    public static function unknownPlaceholders(array $blocks, ?array $fields = null): array
    {
        $found = [];

        foreach ($blocks as $block) {
            foreach (self::texts($block) as $text) {
                array_push($found, ...MergeTemplate::placeholders($text));
            }
        }

        return array_values(array_diff(array_unique($found), array_keys($fields ?? self::FIELDS)));
    }

    /**
     * Fill the placeholders when a certificate is issued, so reprints never change.
     *
     * @param  list<Block>  $blocks
     * @param  array<string, string>  $values
     * @return list<Block>
     */
    public static function merge(array $blocks, array $values): array
    {
        $fill = fn (string $text): string => MergeTemplate::render($text, $values);

        return array_map(function (array $block) use ($fill, $values): array {
            switch ($block['type']) {
                case 'header':
                    return [...$block, 'companyName' => $fill($block['companyName']), 'lines' => $fill($block['lines'])];

                case 'title':
                case 'paragraph':
                case 'footer':
                case 'pageFooter':
                    return [...$block, 'text' => $fill($block['text'])];

                case 'signatory':
                    return [...$block, 'signers' => array_map(fn (array $signer): array => array_map($fill, $signer), $block['signers'])];

                case 'details':
                    // Rows keep their labels and freeze the values under the field names.
                    return [...$block, 'fields' => array_map(fn (string $field): string => $field.'='.($values[$field] ?? ''), $block['fields'])];

                default:
                    return $block;
            }
        }, $blocks);
    }

    /**
     * Placeholder values for one employee and one issuance.
     *
     * @return array<string, string>
     */
    public static function values(Employee $employee, string $issuedDate, ?string $purpose, ?string $controlNumber, string $companyName): array
    {
        $female = $employee->gender === Gender::Female;
        $fullName = Names::firstLast($employee->last_name, $employee->first_name, $employee->middle_name, $employee->extension);
        $hired = $employee->date_hired?->toDateString();
        $lastDay = $employee->last_day_of_service?->toDateString();
        $periods = $employee->employmentPeriods()->get();
        $officials = ContractSetting::current();

        return [
            'fullName' => $fullName,
            'fullNameUpper' => mb_strtoupper($fullName),
            'firstName' => trim((string) $employee->first_name),
            'lastName' => trim((string) $employee->last_name),
            'lastNameTitle' => mb_convert_case(trim((string) $employee->last_name), MB_CASE_TITLE),
            'honorific' => $female ? 'Ms.' : 'Mr.',
            'honorificUpper' => $female ? 'MS.' : 'MR.',
            'pronoun' => $female ? 'she' : 'he',
            'pronounCap' => $female ? 'She' : 'He',
            'possessive' => $female ? 'her' : 'his',
            'possessiveCap' => $female ? 'Her' : 'His',
            'employeeId' => (string) $employee->employee_id,
            'position' => (string) $employee->position,
            'positionWithArticle' => self::withArticle((string) $employee->position),
            'station' => (string) $employee->station?->name,
            'classification' => (string) $employee->classification?->value,
            'engagementType' => self::engagementType($employee),
            'employmentStatus' => (string) $employee->employment_status?->value,
            'dateHired' => self::longDate($hired),
            'lastDayOfService' => self::longDate($lastDay),
            'lengthOfService' => self::serviceInWords($hired, $lastDay, $issuedDate),
            'firstDateHired' => self::longDate($periods->first()?->hired_on?->toDateString() ?? $hired),
            'totalLengthOfService' => $periods->isEmpty() ? self::serviceInWords($hired, $lastDay, $issuedDate) : self::totalServiceInWords($periods->all(), $issuedDate),
            'employmentCount' => (string) max($periods->count(), $hired === null ? 0 : 1),
            'separation' => match ($employee->employment_status) {
                EmploymentStatus::Resigned => 'resigned',
                EmploymentStatus::Retired => 'retired',
                default => 'was separated',
            },
            'purpose' => trim((string) $purpose) ?: self::DEFAULT_PURPOSE,
            'issuedDate' => self::longDate($issuedDate),
            'issuedDay' => self::ordinal((int) substr($issuedDate, 8, 2)),
            'issuedMonthYear' => self::date($issuedDate)->format('F Y'),
            'issuedMonth' => self::date($issuedDate)->format('F'),
            'issuedYear' => self::date($issuedDate)->format('Y'),
            ...self::wageValues($employee, $issuedDate, $female ? 'her' : 'his'),
            'controlNumber' => (string) $controlNumber,
            'companyName' => $companyName,
            'hrmChief' => (string) $officials->hrm_chief_name,
            'hrmChiefTitle' => (string) ($officials->hrm_chief_title ?: ContractSetting::OFFICIALS['hrm_chief']),
        ];
    }

    /**
     * The wage in force on the date issued, from the employee's Compensation History.
     *
     * @return array{dailyWage: string, dailyWageWords: string, premiumWords: string, compensationClause: string}
     */
    private static function wageValues(Employee $employee, string $issuedDate, string $possessive): array
    {
        $rate = app(PayHistoryService::class)->rateOn($employee, $issuedDate);
        $amount = $rate === null ? '' : 'Php'.number_format($rate->amount_centavos / 100, 2);
        $words = $rate === null ? '' : self::hyphenate(Money::pesosInWords($rate->amount_centavos));
        $premium = $rate === null || (float) $rate->premium_percent <= 0 ? '' : self::percentInWords((float) $rate->premium_percent);

        $clause = match (true) {
            $rate === null => '',
            $rate->rate_type === RateType::Monthly => " with a monthly salary of {$words} ({$amount})",
            $premium === '' => " with a daily wage rate of {$words} ({$amount})",
            default => " with a daily wage rate of {$words} ({$amount}) plus a premium equivalent of {$premium} to {$possessive} daily wage rate",
        };

        return ['dailyWage' => $amount, 'dailyWageWords' => $words, 'premiumWords' => $premium, 'compensationClause' => $clause];
    }

    /**
     * "Twenty Five" becomes "Twenty-Five", as formal letters write it.
     */
    private static function hyphenate(string $words): string
    {
        return preg_replace('/\b(Twenty|Thirty|Forty|Fifty|Sixty|Seventy|Eighty|Ninety) (One|Two|Three|Four|Five|Six|Seven|Eight|Nine)\b/', '$1-$2', $words);
    }

    /**
     * 15 becomes "fifteen percent (15%)". A part of a percent stays in figures.
     */
    private static function percentInWords(float $percent): string
    {
        $figure = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
        $words = floor($percent) === $percent ? mb_strtolower(self::hyphenate(Money::numberInWords((int) $percent))) : $figure;

        return "{$words} percent ({$figure}%)";
    }

    private static function withArticle(string $position): string
    {
        $position = trim($position);

        return $position === '' ? '' : (preg_match('/^[aeiou]/i', $position) ? 'an ' : 'a ').$position;
    }

    private static function engagementType(Employee $employee): string
    {
        return match ($employee->classification) {
            Classification::Cos => 'Contract of Service (COS) worker',
            Classification::Contractual => 'Service Contractor',
            default => 'regular employee',
        };
    }

    /**
     * 2026-10-04 becomes "October 4, 2026".
     */
    public static function longDate(?string $ymd): string
    {
        return $ymd === null || $ymd === '' ? '' : self::date($ymd)->format('F j, Y');
    }

    public static function ordinal(int $number): string
    {
        $tens = $number % 100;
        $suffix = ($tens >= 11 && $tens <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$number % 10] ?? 'th');

        return $number.$suffix;
    }

    /**
     * "8 years and 6 months", written out for formal letters.
     */
    public static function serviceInWords(?string $dateHired, ?string $lastDay, string $today): string
    {
        return self::spanInWords(HrDate::lengthOfService($dateHired, $lastDay, $today));
    }

    /**
     * Service added up over every period of employment, in words.
     *
     * @param  list<EmploymentPeriod>  $periods
     */
    public static function totalServiceInWords(array $periods, string $today): string
    {
        $spans = array_values(array_filter(array_map(
            fn (EmploymentPeriod $period): ?array => HrDate::lengthOfService($period->hired_on->toDateString(), $period->separated_on?->toDateString(), $today),
            $periods,
        )));

        return $spans === [] ? '' : self::spanInWords(HrDate::sumSpans($spans));
    }

    /**
     * @param  array{years: int, months: int, days: int}|null  $span
     */
    private static function spanInWords(?array $span): string
    {
        if ($span === null) {
            return '';
        }

        $part = fn (int $count, string $unit): string => $count > 0 ? $count.' '.Str::plural($unit, $count) : '';
        $parts = array_filter([$part($span['years'], 'year'), $part($span['months'], 'month')]);

        return $parts !== [] ? implode(' and ', $parts) : ($part($span['days'], 'day') ?: 'less than a day');
    }

    /**
     * @param  Block  $block
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function sanitizeBlock(array $block, array $fields): array
    {
        $type = $block['type'] ?? null;

        return match ($type) {
            'letterhead' => ['type' => $type],
            'header' => [
                'type' => $type,
                'showLogo' => self::bool($block['showLogo'] ?? null),
                'companyName' => self::text($block['companyName'] ?? null, 160),
                'lines' => self::text($block['lines'] ?? null, 600),
                'align' => self::pick($block['align'] ?? null, ['left', 'center'], 'center'),
            ],
            'title' => [
                'type' => $type,
                'text' => self::text($block['text'] ?? null, 160),
                'size' => self::pick($block['size'] ?? null, ['md', 'lg', 'xl'], 'lg'),
                'align' => self::pick($block['align'] ?? null, ['left', 'center'], 'center'),
                'wide' => self::bool($block['wide'] ?? null),
            ],
            'paragraph' => [
                'type' => $type,
                'text' => self::text($block['text'] ?? null),
                'align' => self::pick($block['align'] ?? null, ['left', 'center', 'right', 'justify'], 'justify'),
                'size' => self::pick($block['size'] ?? null, ['sm', 'md', 'lg'], 'md'),
                'indent' => self::bool($block['indent'] ?? null),
            ],
            'details' => [
                'type' => $type,
                'fields' => array_slice(array_values(array_unique(array_filter(
                    is_array($block['fields'] ?? null) ? $block['fields'] : [],
                    fn (mixed $field): bool => is_string($field) && isset($fields[$field]),
                ))), 0, 12),
            ],
            'signatory' => [
                'type' => $type,
                'align' => self::pick($block['align'] ?? null, ['left', 'center', 'right'], 'right'),
                'signers' => self::signers($block['signers'] ?? null),
            ],
            'spacer' => ['type' => $type, 'size' => self::pick($block['size'] ?? null, ['sm', 'md', 'lg', 'xl'], 'md')],
            'divider' => ['type' => $type],
            'footer' => [
                'type' => $type,
                'text' => self::text($block['text'] ?? null, 600),
                'align' => self::pick($block['align'] ?? null, ['left', 'center', 'right'], 'left'),
            ],
            'pageBreak' => ['type' => $type],
            'pageFooter' => [
                'type' => $type,
                'text' => self::text($block['text'] ?? null, 300),
                'align' => self::pick($block['align'] ?? null, ['left', 'center', 'right'], 'center'),
            ],
            default => throw new UserFacingException('The template contains an unknown block.'),
        };
    }

    /**
     * @return list<array{label: string, name: string, title: string}>
     */
    private static function signers(mixed $input): array
    {
        $signers = array_map(fn (mixed $signer): array => [
            'label' => self::text($signer['label'] ?? null, 60),
            'name' => self::text($signer['name'] ?? null, 120),
            'title' => self::text($signer['title'] ?? null, 160),
        ], array_slice(array_values(array_filter(is_array($input) ? $input : [], is_array(...))), 0, 4));

        return $signers !== [] ? $signers : [['label' => '', 'name' => '', 'title' => '']];
    }

    /**
     * All the text a block shows.
     *
     * @param  Block  $block
     * @return list<string>
     */
    private static function texts(array $block): array
    {
        return match ($block['type']) {
            'header' => [$block['companyName'], $block['lines']],
            'title', 'paragraph', 'footer', 'pageFooter' => [$block['text']],
            'signatory' => array_merge(...array_map(fn (array $signer): array => array_values($signer), $block['signers'])),
            default => [],
        };
    }

    private static function text(mixed $value, int $max = self::MAX_TEXT): string
    {
        return is_string($value) ? mb_substr(preg_replace('/\r\n?/', "\n", $value), 0, $max) : '';
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function pick(mixed $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 'true';
    }

    private static function newId(): string
    {
        return Str::lower(Str::random(8));
    }

    private static function date(string $ymd): DateTimeImmutable
    {
        return new DateTimeImmutable($ymd, new DateTimeZone('UTC'));
    }
}
