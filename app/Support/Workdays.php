<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Working-day counts for wellness leave.
 */
class Workdays
{
    private const MAX_RANGE_DAYS = 366;

    /**
     * Count Monday-to-Friday dates in the inclusive range, skipping holidays.
     *
     * @param  iterable<string>  $holidays
     * @return array{total: int, byYear: array<int, int>, dates: list<string>}
     *
     * @throws InvalidArgumentException so a bad date can never silently count as zero days
     */
    public static function count(string $from, string $to, iterable $holidays = []): array
    {
        if (! HrDate::isValid($from) || ! HrDate::isValid($to)) {
            throw new InvalidArgumentException('Invalid date range');
        }

        if ($to < $from) {
            throw new InvalidArgumentException('The end date is before the start date');
        }

        $skip = [];

        foreach ($holidays as $holiday) {
            $skip[$holiday] = true;
        }

        $result = ['total' => 0, 'byYear' => [], 'dates' => []];

        for ($current = $from, $step = 0; $current <= $to; $current = HrDate::addDays($current, 1), $step++) {
            if ($step > self::MAX_RANGE_DAYS) {
                throw new InvalidArgumentException('Leave range is longer than a year');
            }

            $dayOfWeek = HrDate::dayOfWeek($current);

            if ($dayOfWeek === 0 || $dayOfWeek === 6 || isset($skip[$current])) {
                continue;
            }

            $year = (int) substr($current, 0, 4);
            $result['total']++;
            $result['byYear'][$year] = ($result['byYear'][$year] ?? 0) + 1;
            $result['dates'][] = $current;
        }

        return $result;
    }
}
