<?php

namespace App\Policies;

use App\Models\User;

/**
 * Standard access for settings groups: every approved HR user can read and change them.
 */
abstract class SettingsPolicy
{
    public function view(?User $user): bool
    {
        return $user?->isApproved() ?? false;
    }

    public function update(User $user): bool
    {
        return $user->isApproved();
    }
}
