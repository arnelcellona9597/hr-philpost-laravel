<?php

namespace App\Support;

/**
 * Reporting periods for the dashboard: a date range, the equal-length range before it and how to
 * group it into chart buckets. All dates are YYYY-MM-DD in Manila time.
 *
 * @phpstan-type Period array{key: string, label: string, from: string, to: string, prevFrom: string, prevTo: string, prevLabel: string, bucket: string}
 */
class ReportingPeriod
{
    public const OPTIONS = [
        'this-month' => 'This month',
        'last-month' => 'Last month',
        '90d' => 'Last 90 days',
        'this-year' => 'This year',
        '12m' => 'Last 12 months',
        '10y' => 'Last 10 years',
    ];

    public const DEFAULT = 'this-year';

    public const CUSTOM = 'custom';

    public const MAX_CUSTOM_DAYS = 3660;

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /**
     * A chosen range wins over the preset: both dates must be real, in order, and at most ten years apart.
     *
     * @return Period
     */
    public static function resolve(?string $key = null, ?string $today = null, ?string $from = null, ?string $to = null): array
    {
        $today ??= HrDate::today();

        if (self::validRange($from, $to)) {
            return self::custom($from, $to);
        }

        $key = isset(self::OPTIONS[$key]) ? $key : self::DEFAULT;
        $year = (int) substr($today, 0, 4);
        $monthStart = substr($today, 0, 7).'-01';

        $range = match ($key) {
            'this-month' => [$monthStart, $today, self::addMonths($monthStart, -1), self::addMonths($today, -1), 'vs same days last month', 'day'],
            'last-month' => [
                self::addMonths($monthStart, -1),
                self::monthEnd(self::addMonths($monthStart, -1)),
                self::addMonths($monthStart, -2),
                self::monthEnd(self::addMonths($monthStart, -2)),
                'vs the month before',
                'day',
            ],
            '90d' => [HrDate::addDays($today, -89), $today, HrDate::addDays($today, -179), HrDate::addDays($today, -90), 'vs previous 90 days', 'week'],
            '12m' => [
                self::addMonths($monthStart, -11),
                $today,
                self::addMonths($monthStart, -23),
                HrDate::addDays(self::addMonths($monthStart, -11), -1),
                'vs previous 12 months',
                'month',
            ],
            '10y' => [($year - 9).'-01-01', $today, ($year - 19).'-01-01', ($year - 10).'-12-31', 'vs previous 10 years', 'year'],
            default => ["{$year}-01-01", $today, ($year - 1).'-01-01', HrDate::addYears($today, -1), 'vs same period last year', 'month'],
        };

        return [
            'key' => $key,
            'label' => self::OPTIONS[$key],
            'from' => $range[0],
            'to' => $range[1],
            'prevFrom' => $range[2],
            'prevTo' => $range[3],
            'prevLabel' => $range[4],
            'bucket' => $range[5],
        ];
    }

    public static function validRange(?string $from, ?string $to): bool
    {
        return $from !== null && $to !== null && HrDate::isValid($from) && HrDate::isValid($to)
            && $from <= $to && HrDate::daysBetween($from, $to) < self::MAX_CUSTOM_DAYS;
    }

    /**
     * @return Period
     */
    private static function custom(string $from, string $to): array
    {
        $days = HrDate::daysBetween($from, $to) + 1;
        $prevTo = HrDate::addDays($from, -1);

        return [
            'key' => self::CUSTOM,
            'label' => HrDate::format($from).' – '.HrDate::format($to),
            'from' => $from,
            'to' => $to,
            'prevFrom' => HrDate::addDays($prevTo, -($days - 1)),
            'prevTo' => $prevTo,
            'prevLabel' => "vs the previous {$days} ".($days === 1 ? 'day' : 'days'),
            'bucket' => $days <= 45 ? 'day' : ($days <= 400 ? 'week' : ($days <= 3000 ? 'month' : 'year')),
        ];
    }

    /**
     * Add whole months, clamping the day to the target month's length.
     */
    public static function addMonths(string $ymd, int $months): string
    {
        $index = (int) substr($ymd, 0, 4) * 12 + (int) substr($ymd, 5, 2) - 1 + $months;
        $year = intdiv($index, 12);
        $month = $index % 12 + 1;

        return sprintf('%d-%02d-%02d', $year, $month, min((int) substr($ymd, 8, 2), HrDate::lastDayOf($year, $month)));
    }

    /**
     * The bucket a date falls in: the day, the Monday of its week, YYYY-MM or YYYY.
     */
    public static function bucketKey(string $ymd, string $bucket): string
    {
        return match ($bucket) {
            'day' => $ymd,
            'week' => HrDate::addDays($ymd, -((HrDate::dayOfWeek($ymd) + 6) % 7)),
            'month' => substr($ymd, 0, 7),
            default => substr($ymd, 0, 4),
        };
    }

    /**
     * Every bucket from one date to another, in order, with a short chart label.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function buckets(string $from, string $to, string $bucket): array
    {
        $multiYear = substr($from, 0, 4) !== substr($to, 0, 4);
        $current = match ($bucket) {
            'month' => substr($from, 0, 7).'-01',
            'year' => substr($from, 0, 4).'-01-01',
            default => self::bucketKey($from, $bucket),
        };
        $end = self::bucketKey($to, $bucket);
        $buckets = [];

        for ($guard = 0; $guard < 400; $guard++) {
            $key = self::bucketKey($current, $bucket);

            if ($key > $end) {
                break;
            }

            $month = self::MONTHS[(int) substr($current, 5, 2) - 1];
            $buckets[] = ['key' => $key, 'label' => match ($bucket) {
                'year' => substr($current, 0, 4),
                'month' => $multiYear ? "{$month} ’".substr($current, 2, 2) : $month,
                default => $month.' '.(int) substr($current, 8, 2),
            }];

            $current = match ($bucket) {
                'day' => HrDate::addDays($current, 1),
                'week' => HrDate::addDays($current, 7),
                'month' => self::addMonths($current, 1),
                default => ((int) substr($current, 0, 4) + 1).'-01-01',
            };
        }

        return $buckets;
    }

    /**
     * Percentage change, or null when there is no earlier value to compare with.
     */
    public static function percentChange(int|float $current, int|float $previous): ?float
    {
        return $previous ? ($current - $previous) / $previous * 100 : null;
    }

    private static function monthEnd(string $ymd): string
    {
        return substr($ymd, 0, 8).sprintf('%02d', HrDate::lastDayOf((int) substr($ymd, 0, 4), (int) substr($ymd, 5, 2)));
    }
}
