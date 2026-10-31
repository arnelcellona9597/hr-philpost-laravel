<?php

namespace App\Policies;

use App\Models\User;

/**
 * The outbox is written by the mailer service only; staff can read it and delete from it.
 */
class MessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isApproved();
    }

    public function view(User $user): bool
    {
        return $user->isApproved();
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
        return $user->isApproved();
    }
}
