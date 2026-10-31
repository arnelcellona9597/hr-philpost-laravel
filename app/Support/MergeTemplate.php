<?php

namespace App\Support;

/**
 * {{placeholder}} rendering for email templates.
 */
class MergeTemplate
{
    public const FIELDS = [
        'firstName' => 'Recipient first name',
        'lastName' => 'Recipient last name',
        'fullName' => 'Recipient full name',
        'employeeId' => 'Employee ID (employees only)',
        'position' => 'Position / designation',
        'station' => 'Station / branch',
        'leaveRemaining' => 'Wellness leave days left this year',
        'companyName' => 'Company name from Branding',
    ];

    /**
     * Fields available only in automatic notifications.
     */
    public const CONTEXT_FIELDS = [
        'leaveDates' => 'Inclusive dates of the leave filing',
        'leaveDays' => 'Working days in the filing',
        'leaveStatus' => 'Status of the filing',
        'payPeriod' => 'Payroll period name',
        'payDate' => 'Payroll pay date',
        'netPay' => 'Net pay on the payslip',
        'requirement' => 'Annual requirement name',
        'year' => 'Year',
        'deadline' => 'Submission deadline of the requirement',
        'requirementsList' => 'Requirements for employment still needed (one per line)',
    ];

    private const PLACEHOLDER_PATTERN = '/\{\{\s*([a-zA-Z][a-zA-Z0-9]*)\s*\}\}/';

    /**
     * @return list<string>
     */
    public static function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Placeholders in the text that are not allowed.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public static function unknownPlaceholders(string $text, array $allowed): array
    {
        return array_values(array_diff(self::placeholders($text), $allowed));
    }

    /**
     * Replace {{name}} with its value; missing values become an empty string.
     *
     * @param  array<string, string|int|float|null>  $values
     */
    public static function render(string $text, array $values): string
    {
        return preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            fn (array $matches): string => (string) ($values[$matches[1]] ?? ''),
            $text,
        );
    }

    /**
     * Plain text as safe HTML paragraphs with line breaks and clickable links.
     */
    public static function textToHtml(string $text): string
    {
        $paragraphs = preg_split('/\n{2,}/', trim($text));

        return implode('', array_map(function (string $paragraph): string {
            $html = htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $html = preg_replace('/(https?:\/\/[^\s<]+)/', '<a href="$1">$1</a>', $html);

            return '<p style="margin:0 0 14px">'.str_replace("\n", '<br>', $html).'</p>';
        }, $paragraphs));
    }
}
