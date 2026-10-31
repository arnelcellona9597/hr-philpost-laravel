<?php

namespace App\Support;

/**
 * Submission deadlines for the annual requirements and the On time / Late status of each record.
 */
class Deadlines
{
    public const RULES = [
        ['key' => 'itr', 'slug' => 'itr-submissions', 'period' => null, 'label' => 'Income Tax Return (ITR)'],
        ['key' => 'sworn', 'slug' => 'sworn-declarations', 'period' => null, 'label' => 'Sworn Declaration'],
        ['key' => 'pds', 'slug' => 'pds-submissions', 'period' => null, 'label' => 'Personal Data Sheet (PDS)'],
        ['key' => 'ipcr_jan_jun', 'slug' => 'ipcr-ratings', 'period' => 'Jan-Jun', 'label' => 'IPCR — January to June'],
        ['key' => 'ipcr_jul_dec', 'slug' => 'ipcr-ratings', 'period' => 'Jul-Dec', 'label' => 'IPCR — July to December'],
    ];

    /**
     * @return array{key: string, slug: string, period: string|null, label: string}|null
     */
    public static function ruleFor(string $slug, ?string $period = null): ?array
    {
        foreach (self::RULES as $rule) {
            if ($rule['slug'] === $slug && ($rule['period'] === null || $rule['period'] === $period)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Whether a month and day can be a deadline. Feb 29 is allowed and becomes Feb 28 in common years.
     */
    public static function isValidMonthDay(int $month, int $day): bool
    {
        return $month >= 1 && $month <= 12 && $day >= 1 && $day <= HrDate::lastDayOf(2024, $month);
    }

    /**
     * The usual deadline for records of `$year`, or null when not set.
     *
     * @param  array{month?: int|null, day?: int|null, next_year?: bool|null}|null  $rule
     */
    public static function usual(?array $rule, int $year): ?string
    {
        $month = (int) ($rule['month'] ?? 0);
        $day = (int) ($rule['day'] ?? 0);

        if (! $month || ! $day || ! self::isValidMonthDay($month, $day)) {
            return null;
        }

        $targetYear = $year + (empty($rule['next_year']) ? 0 : 1);

        return sprintf('%04d-%02d-%02d', $targetYear, $month, min($day, HrDate::lastDayOf($targetYear, $month)));
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array{requirement?: string|null, period?: string|null, year?: int|null, deadline?: string|null}|null
     */
    public static function overrideFor(?array $settings, string $slug, int $year, ?string $period = null): ?array
    {
        foreach ($settings['overrides'] ?? [] as $override) {
            $periodMatches = $slug !== 'ipcr-ratings' || ($override['period'] ?? null) === $period;

            if (($override['requirement'] ?? null) === $slug && (int) ($override['year'] ?? 0) === $year && $periodMatches) {
                return $override;
            }
        }

        return null;
    }

    /**
     * The year's exact date if HR set one, else the usual date, else null.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public static function for(?array $settings, string $slug, int $year, ?string $period = null): ?string
    {
        $exact = HrDate::toYmd(self::overrideFor($settings, $slug, $year, $period)['deadline'] ?? null);

        if ($exact) {
            return $exact;
        }

        $rule = self::ruleFor($slug, $period);

        return $rule ? self::usual($settings[$rule['key']] ?? null, $year) : null;
    }

    /**
     * Where a year's deadline comes from, for labels like "(changed for 2026)".
     *
     * @param  array<string, mixed>|null  $settings
     * @return 'override'|'usual'|'none'
     */
    public static function source(?array $settings, string $slug, int $year, ?string $period = null): string
    {
        if (HrDate::toYmd(self::overrideFor($settings, $slug, $year, $period)['deadline'] ?? null)) {
            return 'override';
        }

        $rule = self::ruleFor($slug, $period);

        return $rule && self::usual($settings[$rule['key']] ?? null, $year) ? 'usual' : 'none';
    }

    /**
     * On time / Late is decided by the date submitted. Without a submission a record is Overdue once
     * the deadline has passed, otherwise Pending. Days are calendar days.
     *
     * @return array{status: 'on-time'|'late'|'submitted'|'overdue'|'pending', days: int, label: string}
     */
    public static function timeliness(mixed $dateSubmitted, ?string $deadline, ?string $today = null): array
    {
        $today ??= HrDate::today();
        $submitted = HrDate::toYmd($dateSubmitted);
        $due = HrDate::toYmd($deadline);

        if ($submitted) {
            if (! $due) {
                return ['status' => 'submitted', 'days' => 0, 'label' => 'Submitted'];
            }

            $late = HrDate::daysBetween($due, $submitted);

            return $late > 0
                ? ['status' => 'late', 'days' => $late, 'label' => 'Late ('.self::daysText($late).')']
                : ['status' => 'on-time', 'days' => 0, 'label' => 'On time'];
        }

        if ($due && $today > $due) {
            $over = HrDate::daysBetween($due, $today);

            return ['status' => 'overdue', 'days' => $over, 'label' => 'Overdue ('.self::daysText($over).')'];
        }

        return ['status' => 'pending', 'days' => 0, 'label' => 'Pending'];
    }

    private static function daysText(int $days): string
    {
        return $days.' day'.($days === 1 ? '' : 's');
    }
}
