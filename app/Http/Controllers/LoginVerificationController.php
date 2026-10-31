<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Middleware\RequireActiveSession;
use App\Services\SignInService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginVerificationController extends Controller
{
    public function show(Request $request, SignInService $signIn): View|RedirectResponse
    {
        $key = $request->session()->get('login.challenge');

        if ($key === null) {
            return redirect()->route('login');
        }

        $challenge = $signIn->info($key);

        if ($challenge === null) {
            return $this->restart($request);
        }

        return view('auth.verify', ['challenge' => $challenge]);
    }

    public function store(Request $request, SignInService $signIn): RedirectResponse
    {
        $result = $signIn->verify($request->session()->get('login.challenge'), (string) $request->input('code'));

        if ($result['restart'] ?? false) {
            return $this->restart($request);
        }

        if (isset($result['error'])) {
            return back()->withErrors(['code' => $result['error']]);
        }

        $next = $request->session()->get('login.next', '/');
        $request->session()->forget(['login.challenge', 'login.next']);

        Auth::login($result['user']);
        $request->session()->regenerate();
        $request->session()->put(RequireActiveSession::EXPIRES_AT_KEY, now()->addHours(RequireActiveSession::SESSION_HOURS)->getTimestamp());

        return redirect($next);
    }

    public function resend(Request $request, SignInService $signIn): RedirectResponse
    {
        try {
            $signIn->resend($request->session()->get('login.challenge'));
        } catch (UserFacingException $exception) {
            return back()->withErrors(['code' => $exception->getMessage()]);
        }

        return back()->with('status', 'A new code was sent.');
    }

    public function cancel(Request $request, SignInService $signIn): RedirectResponse
    {
        $signIn->cancel($request->session()->get('login.challenge'));
        $request->session()->forget(['login.challenge', 'login.next']);

        return redirect()->route('login');
    }

    private function restart(Request $request): RedirectResponse
    {
        $request->session()->forget(['login.challenge', 'login.next']);

        return redirect()->route('login', ['notice' => 'restart']);
    }
}
