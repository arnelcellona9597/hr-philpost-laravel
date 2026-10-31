<?php

namespace App\Policies;

use App\Models\User;

class NotificationSettingPolicy extends SettingsPolicy
{
    public function viewQueueKey(User $user): bool
    {
        return $user->isSystemAdmin();
    }

    public function rotateQueueKey(User $user): bool
    {
        return $user->isSystemAdmin();
    }

    /**
     * Staff only get a generic warning; the raw SMTP error can reveal server details.
     */
    public function viewSmtpError(User $user): bool
    {
        return $user->isSystemAdmin();
    }
}
