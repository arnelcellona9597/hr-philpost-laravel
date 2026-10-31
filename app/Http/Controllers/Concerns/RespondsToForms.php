<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;

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

    protected function flash(RedirectResponse $redirect, string $message, string $type = 'success'): RedirectResponse
    {
        return $redirect->with('flash', ['type' => $type, 'message' => $message]);
    }
}
