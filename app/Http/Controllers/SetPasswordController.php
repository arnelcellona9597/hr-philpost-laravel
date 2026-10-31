<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Rules\HrPassword;
use App\Services\AccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SetPasswordController extends Controller
{
    public function edit(Request $request, AccountService $accounts): View
    {
        $token = (string) $request->query('token');

        return view('auth.set-password', ['token' => $token, 'link' => $accounts->linkInfo($token)]);
    }

    public function update(Request $request, AccountService $accounts): RedirectResponse
    {
        $data = $request->validate(
            ['token' => ['required', 'string'], 'password' => ['required', 'confirmed', new HrPassword]],
            ['password.confirmed' => 'Passwords do not match.'],
        );

        try {
            $accounts->setPasswordWithLink($data['token'], $data['password']);
        } catch (UserFacingException $exception) {
            return back()->withErrors(['password' => $exception->getMessage()]);
        }

        return redirect()->route('login', ['notice' => 'password-set']);
    }
}
