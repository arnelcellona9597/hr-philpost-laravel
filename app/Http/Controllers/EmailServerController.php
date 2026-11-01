<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\NotificationSetting;
use App\Models\SmtpSetting;
use App\Services\DirectMailer;
use App\Support\GovId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class EmailServerController extends Controller
{
    use RespondsToForms;

    public function edit(): View
    {
        $settings = SmtpSetting::current();

        Gate::authorize('view', $settings);

        return view('settings.email-server', ['settings' => $settings, 'status' => NotificationSetting::current()]);
    }

    public function update(Request $request, DirectMailer $mailer): RedirectResponse
    {
        $settings = SmtpSetting::current();

        Gate::authorize('update', $settings);

        $host = trim((string) $request->input('host'));
        $port = filter_var($request->input('port'), FILTER_VALIDATE_INT);
        $security = (string) $request->input('security');
        $from = trim((string) $request->input('from_address'));
        $errors = [];

        if (! preg_match('/^[a-z0-9.-]+$/i', $host)) {
            $errors['host'] = 'Enter a valid host name, e.g. mail.example.com';
        }

        if ($port === false || $port < 1 || $port > 65535) {
            $errors['port'] = 'Enter a valid port (465 for SSL/TLS, 587 for STARTTLS).';
        }

        if (! in_array($security, ['ssl', 'starttls', 'none'], true)) {
            $errors['security'] = 'Choose the connection security.';
        }

        if ($from === '' || GovId::validateEmail($from) !== null) {
            $errors['from_address'] = 'Enter a valid From address.';
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $settings->fill([
            'host' => $host,
            'port' => $port,
            'security' => $security,
            'username' => trim((string) $request->input('username')) ?: null,
            'from_address' => $from,
            'from_name' => trim((string) $request->input('from_name')) ?: 'HR System',
        ]);

        if ($request->boolean('clear_password')) {
            $settings->password = null;
        } elseif ((string) $request->input('password') !== '') {
            $settings->password = (string) $request->input('password');
        }

        $settings->save();

        $error = $mailer->verifyConnection();
        $redirect = redirect()->route('settings.email-server');

        return $error === null
            ? $this->flash($redirect, 'Saved. Connected and signed in to the email server successfully.')
            : $this->flash($redirect, "Saved, but connecting failed: {$error}", 'error');
    }

    /**
     * This page is for System Admins only, so the mail server's own error text helps them fix it.
     */
    public function test(Request $request, DirectMailer $mailer): RedirectResponse
    {
        Gate::authorize('update', SmtpSetting::current());

        $to = trim((string) $request->input('to'));

        if ($to === '' || GovId::validateEmail($to) !== null) {
            return $this->refused('Enter a valid address for the test email.');
        }

        try {
            $mailer->deliver($to, 'Test email from the HR system', "This is a test email sent by {$request->user()->name}. Email sending works.");
        } catch (Throwable $exception) {
            return $this->refused("Sending failed: {$exception->getMessage()}");
        }

        return $this->flash(redirect()->route('settings.email-server'), "Test email sent to {$to}.");
    }
}
