<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The COS payroll computation, column for column as in the office's PAYROLL FORMAT workbook. Money is
 * whole centavos and rounding happens only where the workbook rounds (premium, rate per minute, the
 * hourly overtime rates and net pay), half away from zero like Excel's ROUND. Quantities (days, hours,
 * absences) are kept in hundredths so nothing drifts.
 *
 * @phpstan-type CosInputs array{daily_rate_centavos: int, premium_percent: float|int|string, days_worked: float|int|string|null, late_minutes: int|string|null, ot_hours: float|int|string|null, ot_rest_hours: float|int|string|null, sss_centavos: int, hdmf_centavos: int, cmpeai_centavos: int, absences?: float|int|string|null}
 * @phpstan-type CosResult array{premium: int, total_daily_rate: int, gross: int, rate_per_minute: int, late: int, hourly_rate: int, hourly_rest_rate: int, overtime: int, gross_after_late: int, deductions: int, net: int, excess: int}
 */
class CosPay
{
    /** Minutes in a working day, for the rate per minute. */
    public const MINUTES_PER_DAY = 480;

    /** Hours in a working day, for the hourly overtime rates. */
    public const HOURS_PER_DAY = 8;

    /**
     * Every computed column for one worker.
     *
     * @param  CosInputs  $inputs
     * @return CosResult
     */
    public static function compute(array $inputs): array
    {
        $rate = (int) $inputs['daily_rate_centavos'];
        $premium = self::divRound($rate * self::hundredths($inputs['premium_percent']), 10_000);
        $total = $rate + $premium;

        $days = self::hundredths($inputs['days_worked'] ?? 0);
        $minutes = (int) ($inputs['late_minutes'] ?? 0);
        $otHours = self::hundredths($inputs['ot_hours'] ?? 0);
        $restHours = self::hundredths($inputs['ot_rest_hours'] ?? 0);

        $perMinute = self::divRound($total, self::MINUTES_PER_DAY);
        $hourly = self::divRound($total * 125, self::HOURS_PER_DAY * 100);
        $hourlyRest = self::divRound($total * 150, self::HOURS_PER_DAY * 100);

        // In hundredths of a centavo, exactly as the workbook multiplies before its final ROUND.
        $grossExact = $total * $days;
        $lateExact = $minutes * $perMinute * 100;
        $overtimeExact = $otHours * $hourly + $restHours * $hourlyRest;
        $afterLateExact = $grossExact - $lateExact + $overtimeExact;

        $deductions = (int) $inputs['sss_centavos'] + (int) $inputs['hdmf_centavos'] + (int) $inputs['cmpeai_centavos'];

        return [
            'premium' => $premium,
            'total_daily_rate' => $total,
            'gross' => self::divRound($grossExact, 100),
            'rate_per_minute' => $perMinute,
            'late' => $minutes * $perMinute,
            'hourly_rate' => $hourly,
            'hourly_rest_rate' => $hourlyRest,
            'overtime' => self::divRound($overtimeExact, 100),
            'gross_after_late' => self::divRound($afterLateExact, 100),
            'deductions' => $deductions,
            'net' => self::divRound($afterLateExact - $deductions * 100, 100),
            'excess' => self::divRound(self::hundredths($inputs['absences'] ?? 0) * $total, 100),
        ];
    }

    /**
     * Parse a quantity such as days, hours or absences: 0 to 999.99, at most 2 decimals.
     *
     * @throws InvalidArgumentException when it is not such a number
     */
    public static function quantity(mixed $value): float
    {
        $text = trim((string) $value);

        if ($text === '') {
            return 0.0;
        }

        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $text)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a number from 0 to 999.99', $text));
        }

        return (float) $text;
    }

    /**
     * Parse whole minutes, 0 to 99,999.
     *
     * @throws InvalidArgumentException when it is not a whole number
     */
    public static function minutes(mixed $value): int
    {
        $text = str_replace(',', '', trim((string) $value));

        if ($text === '') {
            return 0;
        }

        if (! preg_match('/^\d{1,5}$/', $text)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a whole number of minutes', $text));
        }

        return (int) $text;
    }

    /**
     * Hundredths of a quantity, such as 10.5 days becoming 1050.
     */
    private static function hundredths(float|int|string|null $value): int
    {
        return (int) round(((float) ($value ?? 0)) * 100);
    }

    /**
     * Integer division rounded half away from zero, as Excel's ROUND does.
     */
    private static function divRound(int $numerator, int $denominator): int
    {
        $quotient = intdiv(abs($numerator) * 2 + $denominator, $denominator * 2);

        return $numerator < 0 ? -$quotient : $quotient;
    }
}
