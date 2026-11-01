<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Brand palette helpers.
 */
class Colors
{
    private const TEXT_COLORS = ['primary', 'secondary', 'success', 'danger'];

    public const PRESETS = [
        'Civil Blue' => ['primary' => '#1d4ed8', 'secondary' => '#475569', 'accent' => '#0d9488', 'success' => '#15803d', 'warning' => '#b45309', 'danger' => '#b91c1c'],
        'Forest' => ['primary' => '#166534', 'secondary' => '#57534e', 'accent' => '#a16207', 'success' => '#15803d', 'warning' => '#b45309', 'danger' => '#b91c1c'],
        'Maroon' => ['primary' => '#9f1239', 'secondary' => '#52525b', 'accent' => '#b45309', 'success' => '#15803d', 'warning' => '#a16207', 'danger' => '#dc2626'],
        'Royal Purple' => ['primary' => '#6d28d9', 'secondary' => '#475569', 'accent' => '#0e7490', 'success' => '#15803d', 'warning' => '#b45309', 'danger' => '#be123c'],
        'Slate & Teal' => ['primary' => '#0f766e', 'secondary' => '#334155', 'accent' => '#4338ca', 'success' => '#15803d', 'warning' => '#b45309', 'danger' => '#b91c1c'],
    ];

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

    /**
     * Text colours too pale to read on white.
     *
     * @param  array<string, string>  $palette
     * @return list<string>
     */
    public static function contrastWarnings(array $palette): array
    {
        $warnings = [];

        foreach (self::TEXT_COLORS as $key) {
            $hex = $palette[$key] ?? null;

            if (self::isHex($hex)) {
                $ratio = self::contrastRatio($hex, '#ffffff');

                if ($ratio < 3) {
                    $warnings[] = sprintf('%s (%s) is too light to read on white (%.1f:1, needs 3:1)', $key, $hex, $ratio);
                }
            }
        }

        return $warnings;
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
