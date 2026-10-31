<?php

namespace App\Support;

/**
 * Display forms of a person's name.
 */
class Names
{
    /**
     * "DELA CRUZ, Juan P. Jr.", the usual HR listing format.
     */
    public static function lastFirst(?string $last, ?string $first, ?string $middle = null, ?string $extension = null): string
    {
        $rest = implode(' ', array_filter([self::clean($first), self::middleInitial($middle), self::clean($extension)]));

        return implode(', ', array_filter([self::clean($last), $rest]));
    }

    /**
     * "Juan P. Dela Cruz Jr."
     */
    public static function firstLast(?string $last, ?string $first, ?string $middle = null, ?string $extension = null): string
    {
        return implode(' ', array_filter([self::clean($first), self::middleInitial($middle), self::clean($last), self::clean($extension)]));
    }

    /**
     * Up to two initials from a full display name.
     */
    public static function initials(?string $name): string
    {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        $initials = array_map(fn (string $word): string => mb_substr($word, 0, 1), array_slice($words, 0, 2));

        return mb_strtoupper(implode('', $initials)) ?: '?';
    }

    private static function middleInitial(?string $middle): string
    {
        $middle = self::clean($middle);

        return $middle === '' ? '' : mb_strtoupper(mb_substr($middle, 0, 1)).'.';
    }

    private static function clean(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }
}
