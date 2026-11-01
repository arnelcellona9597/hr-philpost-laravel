<?php

namespace App\Support;

use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Exceptions\UserFacingException;
use App\Models\Employee;
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
        ['type' => 'header', 'label' => 'Letterhead', 'description' => 'Logo, company name and address lines', 'icon' => 'building'],
        ['type' => 'title', 'label' => 'Title', 'description' => 'e.g. CERTIFICATE OF EMPLOYMENT', 'icon' => 'file'],
        ['type' => 'paragraph', 'label' => 'Paragraph', 'description' => 'Text with {{placeholders}}; **bold**', 'icon' => 'edit'],
        ['type' => 'details', 'label' => 'Details table', 'description' => 'Employee details as label: value rows', 'icon' => 'sheet'],
        ['type' => 'signatory', 'label' => 'Signatories', 'description' => 'Up to 3 names with titles and signature lines', 'icon' => 'user'],
        ['type' => 'spacer', 'label' => 'Space', 'description' => 'Empty vertical space', 'icon' => 'minus'],
        ['type' => 'divider', 'label' => 'Line', 'description' => 'A thin horizontal rule', 'icon' => 'minus'],
        ['type' => 'footer', 'label' => 'Footnote', 'description' => 'Small print, e.g. "Not valid without dry seal"', 'icon' => 'info'],
    ];

    public const FIELDS = [
        'fullName' => ['label' => 'Full name', 'sample' => 'Juan A. Dela Cruz'],
        'fullNameUpper' => ['label' => 'Full name (capitals)', 'sample' => 'JUAN A. DELA CRUZ'],
        'firstName' => ['label' => 'First name', 'sample' => 'Juan'],
        'lastName' => ['label' => 'Last name', 'sample' => 'Dela Cruz'],
        'honorific' => ['label' => 'Mr. / Ms.', 'sample' => 'Mr.'],
        'pronoun' => ['label' => 'he / she', 'sample' => 'he'],
        'pronounCap' => ['label' => 'He / She', 'sample' => 'He'],
        'possessive' => ['label' => 'his / her', 'sample' => 'his'],
        'employeeId' => ['label' => 'Employee ID', 'sample' => 'EMP-0001'],
        'position' => ['label' => 'Position', 'sample' => 'Administrative Officer IV'],
        'station' => ['label' => 'Station / branch', 'sample' => 'PENRO Batangas'],
        'classification' => ['label' => 'Classification', 'sample' => 'Regular'],
        'employmentStatus' => ['label' => 'Employment status', 'sample' => 'Active'],
        'dateHired' => ['label' => 'Date hired', 'sample' => 'March 5, 2018'],
        'lastDayOfService' => ['label' => 'Last day of service', 'sample' => 'September 30, 2026'],
        'lengthOfService' => ['label' => 'Length of service', 'sample' => '8 years and 6 months'],
        'separation' => ['label' => 'resigned / retired / was separated', 'sample' => 'resigned'],
        'purpose' => ['label' => 'Purpose (entered when issuing)', 'sample' => 'loan application'],
        'issuedDate' => ['label' => 'Date issued', 'sample' => 'October 4, 2026'],
        'issuedDay' => ['label' => 'Day issued (e.g. 4th)', 'sample' => '4th'],
        'issuedMonthYear' => ['label' => 'Month and year issued', 'sample' => 'October 2026'],
        'controlNumber' => ['label' => 'Control number', 'sample' => 'COE-2026-0001'],
        'companyName' => ['label' => 'Company name', 'sample' => 'HR Management System'],
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
            'header' => ['showLogo' => true, 'companyName' => '', 'lines' => '', 'align' => 'center'],
            'title' => ['text' => 'CERTIFICATE OF EMPLOYMENT', 'size' => 'lg', 'align' => 'center', 'wide' => true],
            'paragraph' => ['text' => 'Type your text here. Use {{fullName}} and other placeholders.', 'align' => 'justify', 'size' => 'md', 'indent' => true],
            'details' => ['fields' => ['fullName', 'position', 'station', 'dateHired']],
            'signatory' => ['align' => 'right', 'signers' => [['label' => '', 'name' => '', 'title' => 'HR Officer']]],
            'spacer' => ['size' => 'md'],
            'divider' => [],
            'footer' => ['text' => 'Not valid without the official dry seal.', 'align' => 'left'],
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
     * @return list<Block>
     *
     * @throws UserFacingException with a message for the person editing the template
     */
    public static function sanitize(mixed $input): array
    {
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
            $blocks[] = ['id' => $id, ...self::sanitizeBlock($block)];
        }

        $unknown = self::unknownPlaceholders($blocks);

        if ($unknown !== []) {
            throw new UserFacingException('Unknown placeholder(s): '.implode(', ', array_map(fn (string $name): string => "{{{$name}}}", $unknown)));
        }

        return $blocks;
    }

    /**
     * @param  list<Block>  $blocks
     * @return list<string>
     */
    public static function unknownPlaceholders(array $blocks): array
    {
        $found = [];

        foreach ($blocks as $block) {
            foreach (self::texts($block) as $text) {
                array_push($found, ...MergeTemplate::placeholders($text));
            }
        }

        return array_values(array_diff(array_unique($found), array_keys(self::FIELDS)));
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

        return [
            'fullName' => $fullName,
            'fullNameUpper' => mb_strtoupper($fullName),
            'firstName' => trim((string) $employee->first_name),
            'lastName' => trim((string) $employee->last_name),
            'honorific' => $female ? 'Ms.' : 'Mr.',
            'pronoun' => $female ? 'she' : 'he',
            'pronounCap' => $female ? 'She' : 'He',
            'possessive' => $female ? 'her' : 'his',
            'employeeId' => (string) $employee->employee_id,
            'position' => (string) $employee->position,
            'station' => (string) $employee->station?->name,
            'classification' => (string) $employee->classification?->value,
            'employmentStatus' => (string) $employee->employment_status?->value,
            'dateHired' => self::longDate($hired),
            'lastDayOfService' => self::longDate($lastDay),
            'lengthOfService' => self::serviceInWords($hired, $lastDay, $issuedDate),
            'separation' => match ($employee->employment_status) {
                EmploymentStatus::Resigned => 'resigned',
                EmploymentStatus::Retired => 'retired',
                default => 'was separated',
            },
            'purpose' => trim((string) $purpose) ?: self::DEFAULT_PURPOSE,
            'issuedDate' => self::longDate($issuedDate),
            'issuedDay' => self::ordinal((int) substr($issuedDate, 8, 2)),
            'issuedMonthYear' => self::date($issuedDate)->format('F Y'),
            'controlNumber' => (string) $controlNumber,
            'companyName' => $companyName,
        ];
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
        $span = HrDate::lengthOfService($dateHired, $lastDay, $today);

        if ($span === null) {
            return '';
        }

        $part = fn (int $count, string $unit): string => $count > 0 ? $count.' '.Str::plural($unit, $count) : '';
        $parts = array_filter([$part($span['years'], 'year'), $part($span['months'], 'month')]);

        return $parts !== [] ? implode(' and ', $parts) : ($part($span['days'], 'day') ?: 'less than a day');
    }

    /**
     * @param  Block  $block
     * @return array<string, mixed>
     */
    private static function sanitizeBlock(array $block): array
    {
        $type = $block['type'] ?? null;

        return match ($type) {
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
                    fn (mixed $field): bool => is_string($field) && isset(self::FIELDS[$field]),
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
        ], array_slice(array_values(array_filter(is_array($input) ? $input : [], is_array(...))), 0, 3));

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
            'title', 'paragraph', 'footer' => [$block['text']],
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
