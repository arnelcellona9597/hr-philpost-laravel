<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Brand palette helpers.
 */
class Colors
{
    /**
     * Fill any missing or malformed palette entry with its default, so only hex colors reach the page.
     *
     * @param  array<string, mixed>|null  $palette
     * @return array<string, string>
     */
    public static function resolve(?array $palette): array
    {
        $resolved = SiteSetting::DEFAULT_PALETTE;

        foreach (array_keys($resolved) as $key) {
            if (self::isHex($palette[$key] ?? null)) {
                $resolved[$key] = $palette[$key];
            }
        }

        return $resolved;
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1;
    }

    public static function contrastRatio(string $first, string $second): float
    {
        $luminances = [self::luminance($first), self::luminance($second)];

        return (max($luminances) + 0.05) / (min($luminances) + 0.05);
    }

    /**
     * Black or white, whichever reads better on the color.
     */
    public static function readableOn(string $hex): string
    {
        return self::contrastRatio($hex, '#ffffff') >= self::contrastRatio($hex, '#000000') ? '#ffffff' : '#000000';
    }

    private static function luminance(string $hex): float
    {
        $number = hexdec(substr($hex, 1));

        return 0.2126 * self::channel(($number >> 16) & 255)
            + 0.7152 * self::channel(($number >> 8) & 255)
            + 0.0722 * self::channel($number & 255);
    }

    private static function channel(int $value): float
    {
        $scaled = $value / 255;

        return $scaled <= 0.03928 ? $scaled / 12.92 : (($scaled + 0.055) / 1.055) ** 2.4;
    }
}
