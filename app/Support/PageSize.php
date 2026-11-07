<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * How many rows a list shows per page: the choice in the URL, else the one remembered in this browser,
 * else the list's own default.
 */
class PageSize
{
    public const SIZES = [5, 10, 25, 50, 100, 500, 1000];

    public const COOKIE = 'hr-per-page';

    public static function resolve(Request $request, int $default = 25): int
    {
        foreach ([$request->query('perPage'), $request->query('limit'), $request->cookie(self::COOKIE)] as $choice) {
            if (in_array((int) $choice, self::SIZES, true)) {
                return (int) $choice;
            }
        }

        return $default;
    }
}
