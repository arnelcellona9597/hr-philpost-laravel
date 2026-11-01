<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

/**
 * Reading Excel cells as a person sees them. Formulas are never run: their saved result is used.
 */
class ExcelCells
{
    private const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

    public static function plain(Cell $cell): string|int|float|bool|null
    {
        $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();

        return match (true) {
            $value instanceof RichText => $value->getPlainText(),
            is_string($value) && str_starts_with($value, '#') && $cell->getDataType() === 'e' => null,
            is_scalar($value) => $value,
            default => null,
        };
    }

    public static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * Text as typed, with whitespace tidied. Excel may store "12345" as a number.
     */
    public static function text(mixed $value): ?string
    {
        if (self::isBlank($value)) {
            return null;
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * Read a date cell as [YYYY-MM-DD, error]. Accepts date serial numbers, YYYY-MM-DD text and
     * month-name text. 03/05/2024 is refused because it could mean March 5 or May 3.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function date(mixed $value, bool $date1904 = false): array
    {
        if (is_int($value) || is_float($value)) {
            return self::dateFromSerial($value, $date1904);
        }

        $text = trim((string) $value);

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $text, $parts)) {
            $ymd = sprintf('%s-%02d-%02d', $parts[1], $parts[2], $parts[3]);

            return HrDate::isValid($ymd) ? [$ymd, null] : [null, "\"{$text}\" is not a real date"];
        }

        $named = match (true) {
            (bool) preg_match('/^([a-z]{3})[a-z]*\.?\s+(\d{1,2}),?\s+(\d{4})$/i', $text, $parts) => [$parts[1], $parts[2], $parts[3]],
            (bool) preg_match('/^(\d{1,2})\s+([a-z]{3})[a-z]*\.?,?\s+(\d{4})$/i', $text, $parts) => [$parts[2], $parts[1], $parts[3]],
            default => null,
        };

        if ($named !== null) {
            $month = array_search(mb_strtolower($named[0]), self::MONTHS, true);
            $ymd = $month === false ? '' : sprintf('%s-%02d-%02d', $named[2], $month + 1, $named[1]);

            if ($ymd !== '' && HrDate::isValid($ymd)) {
                return [$ymd, null];
            }
        }

        if (preg_match('/^\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}$/', $text)) {
            return [null, "\"{$text}\" is ambiguous (day/month order). Use YYYY-MM-DD or an Excel date cell"];
        }

        return [null, "\"{$text}\" is not a recognised date. Use YYYY-MM-DD"];
    }

    /**
     * Header text for matching: case, spacing and spacing around punctuation don't matter.
     */
    public static function headerKey(mixed $value): string
    {
        $text = mb_strtolower((string) $value);
        $text = preg_replace('/\s*([\/.()-])\s*/u', '$1', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Excel's 1900 system wrongly counts 1900 as a leap year: serial 60 is a day that never existed,
     * and counting from 1899-12-30 is right for every serial after it.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function dateFromSerial(int|float $serial, bool $date1904): array
    {
        if ($serial < 1 || $serial > 2_958_465) {
            return [null, "({$serial}) is not a valid date"];
        }

        if (! $date1904 && (int) $serial === 60) {
            return [null, 'is 1900-02-29, which does not exist'];
        }

        $days = (int) floor($serial) + (! $date1904 && $serial < 60 ? 1 : 0);
        $start = new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30', new DateTimeZone('UTC'));

        return [$start->modify("+{$days} days")->format('Y-m-d'), null];
    }
}
