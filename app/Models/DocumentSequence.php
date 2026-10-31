<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Locked counters behind certificate numbers and application IDs, so a number is never reused,
 * even after the record holding the highest one is deleted.
 */
class DocumentSequence extends Model
{
    /**
     * Claim the next number in a sequence.
     */
    public static function next(string $scope): int
    {
        return DB::transaction(function () use ($scope): int {
            static::query()->insertOrIgnore(['scope' => $scope, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);

            $sequence = static::query()->where('scope', $scope)->lockForUpdate()->firstOrFail();
            $sequence->increment('last_number');

            return $sequence->last_number;
        });
    }

    /**
     * The next control number, such as COE-2026-0001.
     */
    public static function nextCertificateNumber(string $prefix, int $year): string
    {
        return sprintf('%s-%d-%04d', $prefix, $year, static::next("certificate:{$prefix}:{$year}"));
    }

    /**
     * The next application ID, such as APP-2026-001.
     */
    public static function nextApplicationId(int $year): string
    {
        return sprintf('APP-%d-%03d', $year, static::next("application:{$year}"));
    }
}
