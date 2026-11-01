<?php

namespace App\Support;

/**
 * An .xlsx file is a zip archive. Its central directory is checked before any library inflates it,
 * so a small upload cannot expand into gigabytes in memory.
 */
class ZipGuard
{
    public const MAX_UNCOMPRESSED = 60 * 1024 * 1024;

    public const MAX_ENTRIES = 300;

    public const MAX_RATIO = 200;

    private const NOT_A_WORKBOOK = 'This file is not a valid Excel workbook (.xlsx).';

    /**
     * An error message, or null when the archive looks safe to open.
     */
    public static function check(string $bytes): ?string
    {
        $length = strlen($bytes);

        if ($length < 22 || self::uint32($bytes, 0) !== 0x04034B50) {
            return self::NOT_A_WORKBOOK;
        }

        $end = self::endOfDirectory($bytes, $length);

        if ($end === null) {
            return self::NOT_A_WORKBOOK;
        }

        $entries = self::uint16($bytes, $end + 10);
        $directorySize = self::uint32($bytes, $end + 12);
        $offset = self::uint32($bytes, $end + 16);

        if ($entries === 0xFFFF || $offset === 0xFFFFFFFF) {
            return 'This workbook is too large to import.';
        }

        if ($entries > self::MAX_ENTRIES) {
            return 'This workbook has too many parts to import safely.';
        }

        if ($offset + $directorySize > $length) {
            return self::NOT_A_WORKBOOK;
        }

        $total = 0;

        for ($entry = 0; $entry < $entries; $entry++) {
            if ($offset + 46 > $length || self::uint32($bytes, $offset) !== 0x02014B50) {
                return self::NOT_A_WORKBOOK;
            }

            $compressed = self::uint32($bytes, $offset + 20);
            $uncompressed = self::uint32($bytes, $offset + 24);

            if ($compressed === 0xFFFFFFFF || $uncompressed === 0xFFFFFFFF) {
                return 'This workbook is too large to import.';
            }

            if ($uncompressed > 1024 * 1024 && $uncompressed / max($compressed, 1) > self::MAX_RATIO) {
                return 'This workbook is compressed suspiciously and was rejected.';
            }

            $total += $uncompressed;

            if ($total > self::MAX_UNCOMPRESSED) {
                return 'This workbook is too large to import (more than 60 MB of data).';
            }

            $offset += 46 + self::uint16($bytes, $offset + 28) + self::uint16($bytes, $offset + 30) + self::uint16($bytes, $offset + 32);
        }

        return null;
    }

    /**
     * The end-of-directory record sits within the last 64 KB plus 22 bytes.
     */
    private static function endOfDirectory(string $bytes, int $length): ?int
    {
        for ($position = $length - 22; $position >= max(0, $length - 65_557); $position--) {
            if (self::uint32($bytes, $position) === 0x06054B50) {
                return $position;
            }
        }

        return null;
    }

    private static function uint16(string $bytes, int $offset): int
    {
        return unpack('v', $bytes, $offset)[1];
    }

    private static function uint32(string $bytes, int $offset): int
    {
        return unpack('V', $bytes, $offset)[1];
    }
}
