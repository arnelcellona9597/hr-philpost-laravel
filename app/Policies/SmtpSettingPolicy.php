<?php

namespace App\Policies;

use App\Models\User;

class SmtpSettingPolicy
{
    public function view(User $user): bool
    {
        return $user->isSystemAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isSystemAdmin();
    }
}
