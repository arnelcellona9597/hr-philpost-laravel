<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Peso amounts as whole centavos, so totals never pick up floating-point errors.
 */
class Money
{
    public const MAX_CENTAVOS = 100_000_000_000;

    /**
     * Parse 1234.5, "1,234.50", "₱ 1,234.50" or "PHP 1234" into centavos.
     *
     * @throws InvalidArgumentException when the amount is malformed, negative, too precise or too large
     */
    public static function toCentavos(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('is not a number');
        }

        $text = is_int($value) || is_float($value)
            ? (string) $value
            : str_replace(',', '', preg_replace('/^(₱|php|p)\s*/iu', '', trim((string) $value)));

        if (! preg_match('/^-?\d+(\.\d+)?(e-?\d+)?$/i', $text)) {
            throw new InvalidArgumentException(sprintf('"%s" is not an amount', is_scalar($value) ? $value : ''));
        }

        $amount = (float) $text;

        if ($amount < 0) {
            throw new InvalidArgumentException('cannot be negative');
        }

        $centavos = round($amount * 100);

        if (abs($amount * 100 - $centavos) > 1e-6) {
            throw new InvalidArgumentException('can have at most 2 decimal places');
        }

        if ($centavos > self::MAX_CENTAVOS) {
            throw new InvalidArgumentException('is too large');
        }

        return (int) $centavos;
    }

    /**
     * 123456 becomes "₱1,234.56"; negative amounts show as "−₱1,234.56".
     */
    public static function format(int $centavos, string $symbol = '₱'): string
    {
        return ($centavos < 0 ? '−' : '').$symbol.number_format(abs($centavos) / 100, 2);
    }

    /**
     * 2584128 becomes "Twenty Five Thousand Eight Hundred Forty One Pesos and 28/100 Only", as written on vouchers.
     */
    public static function words(int $centavos): string
    {
        $pesos = intdiv(abs($centavos), 100);
        $cents = abs($centavos) % 100;

        return self::spell($pesos).' '.($pesos === 1 ? 'Peso' : 'Pesos')." and {$cents}/100 Only";
    }

    /**
     * 45000 becomes "Four Hundred Fifty Pesos"; centavos are added only when there are some.
     */
    public static function pesosInWords(int $centavos): string
    {
        $pesos = intdiv(abs($centavos), 100);
        $cents = abs($centavos) % 100;

        return self::spell($pesos).' '.($pesos === 1 ? 'Peso' : 'Pesos').($cents > 0 ? " and {$cents}/100" : '');
    }

    /**
     * 15 becomes "Fifteen".
     */
    public static function numberInWords(int $number): string
    {
        return self::spell($number);
    }

    private static function spell(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $below1000 = function (int $part) use ($ones, $tens): string {
            $words = [];

            if ($part >= 100) {
                $words[] = $ones[intdiv($part, 100)].' Hundred';
                $part %= 100;
            }

            if ($part >= 20) {
                $words[] = $tens[intdiv($part, 10)];
                $part %= 10;
            }

            if ($part > 0) {
                $words[] = $ones[$part];
            }

            return implode(' ', $words);
        };

        $words = [];

        foreach ([1_000_000_000_000 => 'Trillion', 1_000_000_000 => 'Billion', 1_000_000 => 'Million', 1_000 => 'Thousand'] as $size => $name) {
            if ($number >= $size) {
                $words[] = $below1000(intdiv($number, $size)).' '.$name;
                $number %= $size;
            }
        }

        if ($number > 0) {
            $words[] = $below1000($number);
        }

        return implode(' ', $words);
    }
}
