<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSystemAdmin();
    }

    public function view(User $user, User $account): bool
    {
        return $user->isSystemAdmin() || ($user->isApproved() && $user->is($account));
    }

    public function create(User $user): bool
    {
        return $user->isSystemAdmin();
    }

    public function update(User $user, User $account): bool
    {
        return $user->isSystemAdmin() || ($user->isApproved() && $user->is($account));
    }

    /**
     * Only System Admins change an email, role or status: a stolen session must not be able to
     * redirect future sign-in codes or raise its own access.
     */
    public function manageAccess(User $user): bool
    {
        return $user->isSystemAdmin();
    }

    public function unlock(User $user): bool
    {
        return $user->isSystemAdmin();
    }

    public function delete(User $user, User $account): bool
    {
        return $user->isSystemAdmin() && ! $user->is($account);
    }
}
