<?php

namespace App\Support;

/**
 * Keeps post-login redirects on this site.
 */
class SafeRedirect
{
    /**
     * Allow only a local path: rejects absolute URLs, protocol-relative `//x`, browser-normalised
     * `/\x`, control characters, malformed encoding and the sign-in pages themselves.
     */
    public static function path(?string $next): string
    {
        if (! $next || strlen($next) > 512 || preg_match('/%(?![0-9a-fA-F]{2})/', $next)) {
            return '/';
        }

        $decoded = rawurldecode($next);

        if (! mb_check_encoding($decoded, 'UTF-8')) {
            return '/';
        }

        foreach ([$next, $decoded] as $candidate) {
            if (! preg_match('/^\/(?![\/\\\\])/', $candidate) || preg_match('/[\x00-\x1f\x7f\\\\]/', $candidate)) {
                return '/';
            }
        }

        $normalized = self::withoutDotSegments($next);

        if (str_starts_with($normalized, '//') || str_starts_with($normalized, '/login') || str_starts_with($normalized, '/set-password')) {
            return '/';
        }

        return $normalized;
    }

    /**
     * Resolve `.` and `..` the way a browser will, so `/a/../login` cannot slip past the checks above.
     */
    private static function withoutDotSegments(string $target): string
    {
        $suffixAt = strcspn($target, '?#');
        $resolved = [];
        $segments = explode('/', substr($target, 1, $suffixAt - 1));

        foreach ($segments as $index => $segment) {
            $isLast = $index === array_key_last($segments);
            $plain = strtolower(str_replace(['%2e', '%2E'], '.', $segment));

            if ($plain === '..') {
                array_pop($resolved);
            } elseif ($plain !== '.') {
                $resolved[] = $segment;

                continue;
            }

            if ($isLast) {
                $resolved[] = '';
            }
        }

        return '/'.implode('/', $resolved).substr($target, $suffixAt);
    }
}
