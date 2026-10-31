<?php

namespace App\Policies;

use App\Models\User;

class SiteSettingPolicy extends SettingsPolicy
{
    /**
     * Read is public: the sign-in page needs the company name, logo and colors.
     */
    public function view(?User $user): bool
    {
        return true;
    }
}
