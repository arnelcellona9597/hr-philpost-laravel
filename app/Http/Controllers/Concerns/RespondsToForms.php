<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Post, redirect, get: failures go back with what was typed, successes carry a flash message.
 */
trait RespondsToForms
{
    /**
     * @param  array<string, string>  $errors
     */
    protected function invalid(array $errors): RedirectResponse
    {
        return back()->withInput()->withErrors($errors);
    }

    protected function refused(string $message): RedirectResponse
    {
        return back()->withInput()->withErrors(['form' => $message]);
    }

    /**
     * True when this form was already received: each new-record form carries a one-time token, so a
     * second click, or a reload that resends the form, cannot add the record twice.
     */
    protected function alreadySubmitted(Request $request): bool
    {
        $token = (string) $request->input('submission');

        return preg_match('/^[0-9a-f-]{36}$/', $token) === 1 && ! Cache::add("form-submission:{$token}", true, 600);
    }

    protected function flash(RedirectResponse $redirect, string $message, string $type = 'success'): RedirectResponse
    {
        return $redirect->with('flash', ['type' => $type, 'message' => $message]);
    }
}
