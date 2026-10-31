<?php

namespace App\Policies;

use App\Models\User;

/**
 * Pending sign-ins and set-password links are only ever touched by the auth services.
 */
class LoginChallengePolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user): bool
    {
        return false;
    }

    public function delete(User $user): bool
    {
        return false;
    }
}
