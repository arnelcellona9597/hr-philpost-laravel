<?php

namespace App\Http\Controllers;

use App\Enums\RecipientStatus;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\EmailTemplate;
use App\Models\MessageRecipient;
use App\Models\NotificationSetting;
use App\Services\DirectMailer;
use App\Support\GovId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EmailSettingsController extends Controller
{
    use RespondsToForms;

    private const SWITCHES = [
        'leave_filed_to_employee' => 'Email the employee when wellness leave is filed',
        'leave_status_to_employee' => 'Email the employee when a leave status changes',
        'hired_requirements_to_applicant' => 'Email newly hired applicants their requirements for employment',
        'payroll_released_to_employee' => 'Email each employee their payslip (PDF) when payroll is released',
    ];

    public function edit(Request $request, DirectMailer $mailer): View
    {
        $settings = NotificationSetting::current();

        Gate::authorize('view', $settings);

        return view('settings.notifications', [
            'settings' => $settings,
            'switches' => self::SWITCHES,
            'templates' => EmailTemplate::query()->orderBy('name')->get(),
            'configured' => $mailer->isConfigured(),
            'queued' => MessageRecipient::query()->whereIn('status', [RecipientStatus::Queued, RecipientStatus::Sending])->count(),
            'isAdmin' => $request->user()->isSystemAdmin(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = NotificationSetting::current();

        Gate::authorize('update', $settings);

        $limit = filter_var($request->input('hourly_limit'), FILTER_VALIDATE_INT);
        $replyTo = trim((string) $request->input('reply_to'));

        if ($limit === false || $limit < 1 || $limit > 5000) {
            return $this->invalid(['hourly_limit' => 'Enter a limit from 1 to 5000.']);
        }

        if ($replyTo !== '' && GovId::validateEmail($replyTo) !== null) {
            return $this->invalid(['reply_to' => 'Enter a valid email address.']);
        }

        $switches = [];

        foreach (array_keys(self::SWITCHES) as $name) {
            $switches[$name] = $request->boolean($name);
        }

        $settings->update([...$switches, 'hourly_limit' => $limit, 'reply_to' => $replyTo ?: null, 'footer' => mb_substr((string) $request->input('footer'), 0, 600)]);

        return $this->flash(redirect()->route('settings.notifications'), 'Email settings saved.');
    }

    /**
     * HR Staff can only test-send to their own address; System Admins also have the Email Server page.
     */
    public function test(Request $request, DirectMailer $mailer): RedirectResponse
    {
        Gate::authorize('update', NotificationSetting::current());

        $user = $request->user();
        $sent = $mailer->send($user->email, 'Test email from the HR system', "Hi,\n\nThis is a test email sent by {$user->name}. If you can read this, email sending works.");
        $redirect = redirect()->route('settings.notifications');

        if ($sent && ! $mailer->usesSavedServer()) {
            return $this->flash($redirect, 'Nothing left this server: real email is switched off here.', 'info');
        }

        return $sent
            ? $this->flash($redirect, "Test email sent to {$user->email}.")
            : $this->flash($redirect, 'The test email could not be sent.', 'error');
    }

    public function rotateKey(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin(), 403);

        NotificationSetting::current()->forceFill(['queue_key' => bin2hex(random_bytes(18))])->save();

        return $this->flash(redirect()->route('settings.notifications'), 'New cron key created. Update the cron job with the new command.');
    }
}
