<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only approved accounts count as signed in, and a session ends 12 hours after sign-in however
 * active it has been.
 */
class RequireActiveSession
{
    public const EXPIRES_AT_KEY = 'auth_expires_at';

    public const SESSION_HOURS = 12;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $expiresAt = (int) $request->session()->get(self::EXPIRES_AT_KEY);
        $expired = $user !== null && now()->getTimestamp() >= $expiresAt;

        if ($user?->isApproved() && ! $expired) {
            return $next($request);
        }

        if ($user !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if (! $request->isMethodSafe()) {
            return response('Your session has ended. Please sign in again.', 401);
        }

        if ($expired) {
            return redirect()->route('login', ['notice' => 'expired']);
        }

        return redirect()->route('login', $request->path() === '/' ? [] : ['next' => '/'.ltrim($request->getRequestUri(), '/')]);
    }
}
