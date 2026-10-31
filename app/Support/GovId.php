<?php

namespace App\Support;

/**
 * Philippine government ID numbers. Kept as text so leading zeros survive and stored in one canonical dashed form.
 */
class GovId
{
    public const SPECS = [
        'sss' => ['label' => 'SSS Number', 'lengths' => [10], 'groups' => [10 => [2, 7, 1]]],
        'pagibig' => ['label' => 'Pag-IBIG Number', 'lengths' => [12], 'groups' => [12 => [4, 4, 4]]],
        'philhealth' => ['label' => 'PhilHealth Number', 'lengths' => [12], 'groups' => [12 => [2, 9, 1]]],
        'tin' => ['label' => 'TIN Number', 'lengths' => [9, 12, 14], 'groups' => [9 => [3, 3, 3], 12 => [3, 3, 3, 3], 14 => [3, 3, 3, 5]]],
    ];

    /**
     * Return an error message, or null when the value is empty or valid.
     */
    public static function validate(string $kind, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $spec = self::SPECS[$kind];
        $text = trim((string) $value);

        if (! preg_match('/^[\d\s\-.]+$/', $text)) {
            return "{$spec['label']} may only contain digits and dashes";
        }

        $digits = preg_replace('/\D/', '', $text);

        if (! in_array(strlen($digits), $spec['lengths'], true)) {
            return sprintf('%s must have %s digits (got %d)', $spec['label'], implode(' or ', $spec['lengths']), strlen($digits));
        }

        return null;
    }

    /**
     * Canonical dashed form, or the trimmed input unchanged when it isn't valid.
     */
    public static function format(string $kind, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = trim((string) $value);

        if (self::validate($kind, $text) !== null) {
            return $text;
        }

        $digits = preg_replace('/\D/', '', $text);
        $chunks = [];
        $offset = 0;

        foreach (self::SPECS[$kind]['groups'][strlen($digits)] as $length) {
            $chunks[] = substr($digits, $offset, $length);
            $offset += $length;
        }

        return implode('-', $chunks);
    }

    public static function validateEmail(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', trim((string) $value)) ? null : 'Enter a valid email address';
    }

    public static function validatePhone(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return preg_match('/^[+\d(][\d\s\-()\/,]{6,40}$/', trim((string) $value))
            ? null
            : 'Enter a valid contact number (digits, spaces, + - ( ) only)';
    }
}
