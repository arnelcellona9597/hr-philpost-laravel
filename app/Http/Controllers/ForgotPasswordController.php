<?php

namespace App\Http\Controllers;

use App\Services\AccountService;
use App\Services\DirectMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

use function Illuminate\Support\defer;

class ForgotPasswordController extends Controller
{
    public function create(DirectMailer $mailer): View
    {
        return view('auth.forgot-password', ['emailEnabled' => $mailer->isConfigured()]);
    }

    /**
     * Sent after the response, so timing can't reveal whether the address has an account.
     */
    public function store(Request $request, AccountService $accounts): RedirectResponse
    {
        $email = trim((string) $request->input('email'));

        if ($email !== '' && strlen($email) <= 254) {
            defer(fn () => $accounts->requestReset($email));
        }

        return back()->with('sent', true);
    }
}
