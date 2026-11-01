<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Date-only handling for HR data. Values are `YYYY-MM-DD` strings and "today" is always Asia/Manila.
 */
class HrDate
{
    public const TIMEZONE = 'Asia/Manila';

    private const YMD_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/';

    /**
     * Whether the value is a real calendar date in `YYYY-MM-DD` form.
     */
    public static function isValid(string $ymd): bool
    {
        if (! preg_match(self::YMD_PATTERN, $ymd, $matches)) {
            return false;
        }

        [$year, $month, $day] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];

        return $year >= 1900 && $year <= 2200 && checkdate($month, $day, $year);
    }

    /**
     * Today's date in Manila as `YYYY-MM-DD`.
     */
    public static function today(?DateTimeInterface $now = null): string
    {
        return CarbonImmutable::instance($now ?? CarbonImmutable::now())->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d');
    }

    /**
     * Convert anything date-like to `YYYY-MM-DD`, or null when empty or invalid. Full timestamps are read in Manila time.
     */
    public static function toYmd(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return self::today($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if (preg_match(self::YMD_PATTERN, $trimmed)) {
            return self::isValid($trimmed) ? $trimmed : null;
        }

        try {
            return self::today(new DateTimeImmutable($trimmed));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whole years between `$birth` and `$on`. Feb-29 birthdays age on Mar-1 in common years.
     */
    public static function ageOn(string $birth, ?string $on = null): ?int
    {
        $on ??= self::today();

        if (! self::isValid($birth) || ! self::isValid($on) || $on < $birth) {
            return null;
        }

        [$birthYear, $birthMonth, $birthDay] = self::parts($birth);
        [$onYear, $onMonth, $onDay] = self::parts($on);

        $age = $onYear - $birthYear;

        return ($onMonth < $birthMonth || ($onMonth === $birthMonth && $onDay < $birthDay)) ? $age - 1 : $age;
    }

    /**
     * Whole calendar days from `$from` to `$to`; negative when `$to` is earlier.
     */
    public static function daysBetween(string $from, string $to): int
    {
        return (int) self::utc($from)->diff(self::utc($to))->format('%r%a');
    }

    /**
     * Calendar difference between two dates, as years, months and days.
     *
     * @return array{years: int, months: int, days: int}|null
     */
    public static function diff(string $from, string $to): ?array
    {
        if (! self::isValid($from) || ! self::isValid($to) || $to < $from) {
            return null;
        }

        [$fromYear, $fromMonth, $fromDay] = self::parts($from);
        [$toYear, $toMonth, $toDay] = self::parts($to);

        $months = ($toYear - $fromYear) * 12 + ($toMonth - $fromMonth);

        if ($toDay < $fromDay) {
            $months--;
        }

        while ($months > 0 && self::addMonthsClamped($from, $months) > $to) {
            $months--;
        }

        return [
            'years' => intdiv($months, 12),
            'months' => $months % 12,
            'days' => self::daysBetween(self::addMonthsClamped($from, $months), $to),
        ];
    }

    /**
     * Length of service from hire date to the last day of service (if already past) or today.
     *
     * @return array{years: int, months: int, days: int}|null
     */
    public static function lengthOfService(?string $dateHired, ?string $lastDayOfService = null, ?string $today = null): ?array
    {
        $today ??= self::today();

        if (! $dateHired) {
            return null;
        }

        return self::diff($dateHired, $lastDayOfService && $lastDayOfService < $today ? $lastDayOfService : $today);
    }

    /**
     * Add spans together, counting 30 days as a month, such as the service of several periods.
     *
     * @param  list<array{years: int, months: int, days: int}>  $spans
     * @return array{years: int, months: int, days: int}
     */
    public static function sumSpans(array $spans): array
    {
        $months = array_sum(array_map(fn (array $span): int => $span['years'] * 12 + $span['months'], $spans));
        $days = array_sum(array_column($spans, 'days'));
        $months += intdiv($days, 30);

        return ['years' => intdiv($months, 12), 'months' => $months % 12, 'days' => $days % 30];
    }

    /**
     * Render a span such as "2 yrs 3 mos".
     *
     * @param  array{years: int, months: int, days: int}|null  $span
     */
    public static function formatSpan(?array $span): string
    {
        if ($span === null) {
            return '';
        }

        $parts = [];

        if ($span['years']) {
            $parts[] = $span['years'].' yr'.($span['years'] === 1 ? '' : 's');
        }

        if ($span['months']) {
            $parts[] = $span['months'].' mo'.($span['months'] === 1 ? '' : 's');
        }

        if (! $span['years'] && ! $span['months']) {
            $parts[] = $span['days'].' day'.($span['days'] === 1 ? '' : 's');
        }

        return implode(' ', $parts);
    }

    /**
     * Fractional years, for range filters and sorting.
     *
     * @param  array{years: int, months: int, days: int}|null  $span
     */
    public static function spanToYears(?array $span): ?float
    {
        return $span === null ? null : $span['years'] + $span['months'] / 12 + $span['days'] / 365;
    }

    /**
     * Add whole years, clamping Feb-29 to Feb-28 in common years.
     */
    public static function addYears(string $ymd, int $years): string
    {
        [$year, $month, $day] = self::parts($ymd);
        $targetYear = $year + $years;

        return sprintf('%04d-%02d-%02d', $targetYear, $month, min($day, self::lastDayOf($targetYear, $month)));
    }

    public static function addDays(string $ymd, int $days): string
    {
        return self::utc($ymd)->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * `2024-03-05` becomes `Mar 5, 2024`; empty input gives an empty string.
     */
    public static function format(mixed $value): string
    {
        $ymd = self::toYmd($value);

        return $ymd ? self::utc($ymd)->format('M j, Y') : '';
    }

    public static function yearOf(mixed $value): ?int
    {
        $ymd = self::toYmd($value);

        return $ymd ? (int) substr($ymd, 0, 4) : null;
    }

    /**
     * Day of week for a date: 0 = Sunday through 6 = Saturday.
     */
    public static function dayOfWeek(string $ymd): int
    {
        return (int) self::utc($ymd)->format('w');
    }

    public static function lastDayOf(int $year, int $month): int
    {
        return (int) gmdate('t', gmmktime(0, 0, 0, $month, 1, $year));
    }

    private static function addMonthsClamped(string $ymd, int $months): string
    {
        [$year, $month, $day] = self::parts($ymd);
        $total = $year * 12 + ($month - 1) + $months;
        $targetYear = intdiv($total, 12);
        $targetMonth = ($total % 12) + 1;

        return sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, min($day, self::lastDayOf($targetYear, $targetMonth)));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function parts(string $ymd): array
    {
        [$year, $month, $day] = array_map(intval(...), explode('-', $ymd));

        return [$year, $month, $day];
    }

    private static function utc(string $ymd): DateTimeImmutable
    {
        return new DateTimeImmutable($ymd.' 00:00:00', new DateTimeZone('UTC'));
    }
}
