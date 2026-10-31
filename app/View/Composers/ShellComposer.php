<?php

namespace App\View\Composers;

use App\Http\Middleware\RequireActiveSession;
use App\Models\NotificationSetting;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\DirectMailer;
use App\Support\Navigation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Binds what the signed-in app shell shows around every page.
 */
class ShellComposer
{
    public function __construct(protected Request $request, protected DirectMailer $mailer) {}

    /**
     * Bind data to the view.
     */
    public function compose(View $view): void
    {
        $user = $this->request->user();
        $settings = SiteSetting::current();

        $view->with([
            'user' => $user,
            'company' => $settings->company_name,
            'logoId' => $settings->logo_id,
            'mailWarning' => $this->mailWarning($user),
            'sessionExpiresAt' => $this->sessionExpiresAt(),
            'path' => '/'.ltrim($this->request->path(), '/'),
            'groups' => Navigation::groups($user),
            'unreadNotifications' => $user?->hrNotifications()->whereNull('read_at')->count() ?? 0,
            'prefs' => [
                'collapsed' => $this->request->cookie('hr-sidebar') === 'collapsed',
                'right' => $this->request->cookie('hr-sidebar-side') === 'right',
                'compact' => $this->request->cookie('hr-header') === 'compact',
            ],
        ]);
    }

    /**
     * Mail-server error text can reveal infrastructure details, so only System Admins see it.
     */
    private function mailWarning(?User $user): ?string
    {
        $isAdmin = $user?->isSystemAdmin() ?? false;

        if (! $this->mailer->isConfigured()) {
            return $isAdmin
                ? 'The email server is not configured. Sign-in codes can only be issued with the hr:issue-login-code command, and no notifications are sent.'
                : 'Email is not set up yet, so notifications are not being sent. Please tell your System Admin.';
        }

        $lastError = NotificationSetting::current()->smtp_last_error;

        if ($lastError === null) {
            return null;
        }

        return $isAdmin ? "Email problem: {$lastError}" : 'There is a problem sending email. Please tell your System Admin.';
    }

    private function sessionExpiresAt(): ?Carbon
    {
        $timestamp = $this->request->hasSession() ? $this->request->session()->get(RequireActiveSession::EXPIRES_AT_KEY) : null;

        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
