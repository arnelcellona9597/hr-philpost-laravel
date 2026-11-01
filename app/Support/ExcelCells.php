<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

/**
 * Reading Excel cells as a person sees them. Formulas are never run: their saved result is used.
 */
class ExcelCells
{
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
     * Header text for matching: case, spacing and spacing around punctuation don't matter.
     */
    public static function headerKey(mixed $value): string
    {
        $text = mb_strtolower((string) $value);
        $text = preg_replace('/\s*([\/.()-])\s*/u', '$1', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
