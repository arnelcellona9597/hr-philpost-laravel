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
}
