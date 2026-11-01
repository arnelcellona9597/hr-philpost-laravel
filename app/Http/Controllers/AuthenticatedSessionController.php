<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Services\SignInService;
use App\Support\SafeRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): View
    {
        return view('auth.login', [
            'next' => SafeRedirect::path($request->query('next')),
            'notice' => $request->query('notice'),
        ]);
    }

    /**
     * Step 1: the password. Step 2 is the code emailed to the user.
     */
    public function store(Request $request, SignInService $signIn): RedirectResponse
    {
        $credentials = $request->validate(
            ['email' => ['required', 'string', 'max:254'], 'password' => ['required', 'string', 'max:1000']],
            ['required' => 'Enter your email and password.'],
        );

        try {
            $challenge = $signIn->start(Str::lower(trim($credentials['email'])), $credentials['password'], $request->ip());
        } catch (UserFacingException $exception) {
            return back()->withInput($request->only('email', 'next'))->withErrors(['email' => $exception->getMessage()]);
        }

        $request->session()->regenerate();
        $request->session()->put(['login.challenge' => $challenge, 'login.next' => SafeRedirect::path($request->input('next'))]);

        $redirect = redirect()->route('login.verify');

        return $signIn->codeWasSent ? $redirect : $redirect->withErrors(['code' => SignInService::NOT_SENT]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
