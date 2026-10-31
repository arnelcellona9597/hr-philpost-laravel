<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (Str::startsWith((string) config('app.url'), 'https://')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        if ($request->user() !== null && ! $headers->hasCacheControlDirective('public')) {
            $headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $hotFile = public_path('hot');
        $vite = is_file($hotFile) ? ' '.trim((string) file_get_contents($hotFile)) : '';
        $viteSocket = $vite === '' ? '' : ' '.preg_replace('/^ ?http/', 'ws', $vite);

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'{$vite}",
            "style-src 'self' 'unsafe-inline'{$vite}",
            "img-src 'self' data: blob:",
            "font-src 'self' data:{$vite}",
            "connect-src 'self'{$vite}{$viteSocket}",
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
